<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\AnsweredContributionEvidence;
use App\Enums\ContributionAudience;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    $this->withoutVite();
    Mail::fake();
    config(['ai.provider' => 'fake', 'ai.dossier.enabled' => false]);
    $this->user = User::factory()->create();
    $this->intake = app(CreateIntake::class)->handle($this->user, [
        'template_key' => 'airco', 'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Test', 'customer_email' => 'test@example.com',
        'address_line' => 'Test 9', 'address_postal_code' => '1234AB',
        'address_house_number' => 9, 'address_city' => 'Amsterdam',
    ]);
    $this->item = ['type' => 'photo', 'prompt' => 'Maak een foto van de stroomkabelroute.',
        'decision_area_key' => 'power', 'photo_task_subtype' => 'power_route'];
});

test('N54 proposal enters concept list without activating or replacing installer draft', function () {
    $proposal = ContributionTask::query()->create([
        'intake_id' => $this->intake->id, 'company_id' => $this->intake->company_id,
        'audience' => ContributionAudience::Customer, 'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de meterkast.', 'decision_area_key' => 'power',
        'status' => ContributionTaskStatus::Proposed,
        'meta' => ['source_type' => 'ai', ...PhotoSubject::photoTaskMetaForSubtype('power_route')],
    ]);
    $this->actingAs($this->user)->post(route('intakes.workspace.tasks.prepare', $this->intake), $this->item);
    $edited = [...$this->item, 'prompt' => 'Mijn gecontroleerde stroomkabelopdracht.'];
    $this->post(route('intakes.workspace.tasks.send', [$this->intake, $proposal]), ['contribution_items' => [$edited]])
        ->assertSessionHas('customer_task_drafts.0.prompt', $edited['prompt'])
        ->assertSessionHas('customer_task_drafts.1.proposal_id', $proposal->id)
        ->assertSessionHas('customer_task_drafts.1.photo_task_subtype', 'power_route');
    expect($this->intake->followUpRounds()->count())->toBe(0)
        ->and($this->intake->fresh()->customer_access_enabled)->toBeFalse()
        ->and($proposal->fresh()->status)->toBe(ContributionTaskStatus::Proposed);
    Mail::assertNothingOutgoing();
    $this->post(route('intakes.workspace.tasks.send', [$this->intake, $proposal]));
    expect(session('customer_task_drafts'))->toHaveCount(2);
    $this->get(route('intakes.workspace', $this->intake))->assertOk()
        ->assertSee('form="customer-task-draft-form"', false)
        ->assertSee('formaction="'.route('intakes.workspace.tasks.send', [$this->intake, $proposal]).'"', false)
        ->assertSee('Voorstel toevoegen aan conceptlijst')->assertDontSee('Controleren en klantweergave activeren');
    $this->post(route('intakes.workspace.tasks.store', $this->intake), ['contribution_items' => [$this->item]])->assertSessionHasNoErrors();
    expect($this->intake->followUpRounds()->sole()->items()->sole()->prompt)->toBe($this->item['prompt'])
        ->and($proposal->fresh()->status)->toBe(ContributionTaskStatus::Proposed);
});

test('N54 answered usable evidence requires explicit repeat confirmation and distinguishes subtypes', function (bool $usable, bool $removed) {
    $this->intake->followUpRounds()->create([
        'round_number' => 1, 'purpose' => 'contribution', 'status' => FollowUpRoundStatus::Completed,
        'requested_by' => $this->user->id, 'completed_at' => now(), 'sent_at' => now(),
    ]);
    $round = app(CreateCustomerContributionRequest::class)->handle($this->intake, $this->user, [$this->item]);
    $answer = $round->items()->sole();
    $answer->update(['answered_at' => now()]);
    $upload = IntakeUpload::query()->create([
        'intake_id' => $this->intake->id, 'intake_follow_up_item_id' => $answer->id,
        'question_key' => 'follow_up', 'disk' => 'local', 'path' => 'test.jpg',
        'original_filename' => 'test.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        'checksum' => hash('sha256', 'test'), 'sort_order' => 1,
        'content_assessment' => ($usable ? PhotoContentAssessment::ok(PhotoSubject::PipeRoute)
            : PhotoContentAssessment::wrongSubject(PhotoSubject::PipeRoute, PhotoSubject::Room))->toArray(),
    ]);
    if ($removed) {
        $upload->delete();
    }
    $round->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    $this->intake->update(['status' => IntakeStatus::InProgress, 'customer_access_enabled' => false]);
    $changedText = [...$this->item, 'prompt' => 'Een geheel andere formulering voor dezelfde opdracht.'];
    $warning = app(AnsweredContributionEvidence::class)->warning($this->intake, $changedText);
    expect($warning !== null)->toBe($usable && ! $removed);
    expect(app(AnsweredContributionEvidence::class)->warning($this->intake, [...$changedText, 'photo_task_subtype' => 'fusebox']))->toBeNull();
    $this->actingAs($this->user)->post(route('intakes.workspace.tasks.prepare', $this->intake), $changedText);
    $response = $this->post(route('intakes.workspace.tasks.store', $this->intake), ['contribution_items' => [$changedText]]);
    if ($usable && ! $removed) {
        $response->assertSessionHasErrors('contribution_items.0.repeat_confirmed');
        expect($this->intake->followUpRounds()->count())->toBe(2);
        ContributionTask::query()->create([
            'intake_id' => $this->intake->id, 'company_id' => $this->intake->company_id,
            'audience' => ContributionAudience::Customer, 'type' => FollowUpItemType::Photo,
            'prompt' => 'AI herhaalt een taak met andere tekst.', 'decision_area_key' => 'power',
            'status' => ContributionTaskStatus::Proposed, 'meta' => ['source_type' => 'ai'],
        ]);
        $this->get(route('intakes.workspace', $this->intake))->assertOk()
            ->assertSee('klantronde 2 al een antwoord met bruikbaar bewijs ontvangen')
            ->assertSee('name="contribution_items[0][repeat_confirmed]"', false);
        $this->post(route('intakes.workspace.tasks.store', $this->intake), ['contribution_items' => [[...$changedText, 'repeat_confirmed' => '1']]])->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasNoErrors();
    }
    expect($this->intake->followUpRounds()->count())->toBe(3);
})->with(['usable' => [true, false], 'wrong subject' => [false, false], 'removed' => [true, true]]);

test('N54 reviewed concept can activate after original AI proposal is cancelled', function () {
    $proposal = ContributionTask::query()->create([
        'intake_id' => $this->intake->id, 'company_id' => $this->intake->company_id,
        'audience' => ContributionAudience::Customer, 'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto.', 'decision_area_key' => 'power',
        'status' => ContributionTaskStatus::Cancelled, 'meta' => ['source_type' => 'ai'],
    ]);
    $this->actingAs($this->user)->post(route('intakes.workspace.tasks.store', $this->intake), [
        'contribution_items' => [[...$this->item, 'proposal_id' => $proposal->id]],
    ])->assertSessionHasNoErrors();
    expect($this->intake->followUpRounds()->sole()->items()->sole()->prompt)->toBe($this->item['prompt'])
        ->and($proposal->fresh()->status)->toBe(ContributionTaskStatus::Cancelled);
});

test('N54 document evidence requires repeat confirmation without response text', function (string $state) {
    $item = ['type' => 'document', 'prompt' => 'Upload het document.', 'decision_area_key' => 'power'];
    $round = app(CreateCustomerContributionRequest::class)->handle($this->intake, $this->user, [$item]);
    $answer = $round->items()->sole();
    $answer->update(['answered_at' => now()]);
    $upload = IntakeUpload::query()->create([
        'intake_id' => $this->intake->id, 'intake_follow_up_item_id' => $answer->id,
        'question_key' => 'follow_up_'.$answer->id, 'disk' => 'local', 'path' => 'document.pdf',
        'original_filename' => 'document.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100,
        'checksum' => hash('sha256', 'document'), 'sort_order' => 1,
        'content_assessment' => $state === 'rejected'
            ? PhotoContentAssessment::wrongSubject(PhotoSubject::PipeRoute, PhotoSubject::Room)->toArray() : null,
    ]);
    if ($state === 'removed') {
        $upload->delete();
    }
    $round->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    $this->intake->update(['status' => IntakeStatus::InProgress, 'customer_access_enabled' => false]);
    expect($answer->fresh()->response_text)->toBeNull();
    $response = $this->actingAs($this->user)->post(route('intakes.workspace.tasks.store', $this->intake), [
        'contribution_items' => [[...$item, 'prompt' => 'Andere tekst voor hetzelfde document.']],
    ]);
    if ($state === 'usable') {
        $response->assertSessionHasErrors('contribution_items.0.repeat_confirmed');
        expect($this->intake->followUpRounds()->count())->toBe(1);
        $this->post(route('intakes.workspace.tasks.store', $this->intake), [
            'contribution_items' => [[...$item, 'repeat_confirmed' => '1']],
        ])->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasNoErrors();
    }
    expect($this->intake->followUpRounds()->count())->toBe(2);
})->with(['usable', 'removed', 'rejected']);
