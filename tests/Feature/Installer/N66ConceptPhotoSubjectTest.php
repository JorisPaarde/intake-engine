<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\ContributionAudience;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    $this->withoutVite();
    Mail::fake();
    config(['ai.provider' => 'fake', 'ai.dossier.enabled' => false, 'ai.photo_inference.enabled' => true]);
    $this->user = User::factory()->create();
    $this->intake = app(CreateIntake::class)->handle($this->user, [
        'template_key' => 'airco', 'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Test', 'customer_email' => 'test@example.com',
        'address_line' => 'Test 9', 'address_postal_code' => '1234AB',
        'address_house_number' => 9, 'address_city' => 'Amsterdam',
    ]);
    $this->draft = ['type' => 'photo', 'prompt' => 'Maak een foto van de gang en de route naar de achterkant.',
        'decision_area_key' => 'power', 'photo_task_subtype' => 'fusebox'];
    $this->actingAs($this->user);
});

test('N66 cleared concept survives saving and activates without subject fallback or AI call', function () {
    $proposal = ContributionTask::query()->create([
        'intake_id' => $this->intake->id, 'company_id' => $this->intake->company_id,
        'audience' => ContributionAudience::Customer, 'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto.', 'decision_area_key' => 'power',
        'status' => ContributionTaskStatus::Proposed, 'meta' => ['source_type' => 'ai'],
    ]);
    $edited = [...$this->draft, 'photo_task_subtype' => ''];
    $this->post(route('intakes.workspace.tasks.send', [$this->intake, $proposal]), [
        'contribution_items' => [$edited],
    ])->assertSessionHasNoErrors()->assertSessionHas('customer_task_drafts.0', function ($draft) {
        return array_key_exists('photo_task_subtype', $draft) && $draft['photo_task_subtype'] === null;
    });
    $this->get(route('intakes.workspace', $this->intake))->assertOk();
    $saved = session('customer_task_drafts')[0];
    $this->post(route('intakes.workspace.tasks.store', $this->intake), [
        'contribution_items' => [$saved],
    ])->assertSessionHasNoErrors();
    $task = ContributionTask::query()->whereNotNull('intake_follow_up_item_id')->sole();
    expect($task->meta['photo_subject_check'])->toBe('none')
        ->and($task->meta)->not->toHaveKeys(['expected_photo_subject', 'accepted_photo_subjects', 'photo_task_subtype'])
        ->and(PhotoSubject::expectedForTask($task))->toBeNull();
    $item = $this->intake->followUpRounds()->sole()->items()->sole();
    $upload = IntakeUpload::query()->create([
        'intake_id' => $this->intake->id, 'intake_follow_up_item_id' => $item->id,
        'question_key' => 'follow_up', 'disk' => 'local', 'path' => 'test.jpg',
        'original_filename' => 'test.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        'checksum' => hash('sha256', 'test'), 'sort_order' => 1,
    ]);
    $source = $upload->replicate();
    $source->save();
    $source->storeContentAssessment(PhotoContentAssessment::wrongSubject(
        PhotoSubject::Fusebox, PhotoSubject::Room,
    ));
    $this->mock(AiClientInterface::class)->shouldNotReceive('complete');
    expect(app(AssessFollowUpPhotoSubject::class)->handle($this->intake, $item, $upload))
        ->toBe(['assessment' => null, 'message' => null]);
    app()->call([new AssessUploadedPhotoJob($upload->id), 'handle']);
    expect($upload->fresh()->contentAssessment()->status())->toBe('ok')
        ->and($upload->fresh()->contentAssessment()->expectedSubject())->toBe(PhotoSubject::Other);

});

test('N66 chosen subtype replaces original fusebox through activation', function () {
    $this->post(route('intakes.workspace.tasks.prepare', $this->intake), $this->draft)->assertSessionHasNoErrors();
    $this->post(route('intakes.workspace.tasks.store', $this->intake), [
        'contribution_items' => [[...$this->draft, 'photo_task_subtype' => 'power_route']],
    ])->assertSessionHasNoErrors();
    $task = ContributionTask::query()->whereNotNull('intake_follow_up_item_id')->sole();
    expect($task->meta)->toMatchArray(PhotoSubject::photoTaskMetaForSubtype('power_route'))
        ->and(PhotoSubject::acceptedSubjectsForTask($task))->toBe([
            PhotoSubject::PipeRoute, PhotoSubject::OutdoorUnit, PhotoSubject::OutdoorLocation, PhotoSubject::Fusebox,
        ]);
});

test('N66 photo concept renders whitelisted options and current selection', function () {
    $this->post(route('intakes.workspace.tasks.prepare', $this->intake), $this->draft)->assertSessionHasNoErrors();
    $response = $this->get(route('intakes.workspace', $this->intake))->assertOk()
        ->assertSee('Wat moet de foto laten zien?')
        ->assertSee('Niet opgeven (geen automatische fotocontrole)')
        ->assertSee('<select id="draft-photo-subtype-0" name="contribution_items[0][photo_task_subtype]"', false)
        ->assertSee('<option value="fusebox" selected', false);
    $response->assertDontSee('<option value="around_house"', false);
    foreach (PhotoSubject::photoTaskSubtypesForDecisionArea('power') as $subtype) {
        $response->assertSee('<option value="'.$subtype.'"', false);
    }
});

test('N66 absent subtype keeps the existing power fallback', function () {
    $draft = $this->draft;
    unset($draft['photo_task_subtype']);
    $this->post(route('intakes.workspace.tasks.store', $this->intake), [
        'contribution_items' => [$draft],
    ])->assertSessionHasNoErrors();
    $task = ContributionTask::query()->whereNotNull('intake_follow_up_item_id')->sole();
    expect($task->meta['photo_subject_check'] ?? null)->toBeNull()
        ->and(PhotoSubject::expectedForTask($task))->toBe(PhotoSubject::Fusebox);
});

test('N66 auto survives proposal editing and preserves decision area fallback', function () {
    $proposal = ContributionTask::query()->create([
        'intake_id' => $this->intake->id, 'company_id' => $this->intake->company_id,
        'audience' => ContributionAudience::Customer, 'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto.', 'decision_area_key' => 'power',
        'status' => ContributionTaskStatus::Proposed, 'meta' => ['source_type' => 'ai'],
    ]);
    $this->post(route('intakes.workspace.tasks.send', [$this->intake, $proposal]))->assertSessionHasNoErrors();
    $this->get(route('intakes.workspace', $this->intake))->assertOk()
        ->assertSee('<option value="auto" selected', false);
    $this->post(route('intakes.workspace.tasks.send', [$this->intake, $proposal]), [
        'contribution_items' => [[...$this->draft, 'photo_task_subtype' => 'auto', 'proposal_id' => $proposal->id]],
    ])->assertSessionHasNoErrors();
    $this->get(route('intakes.workspace', $this->intake))->assertOk();
    expect(session('customer_task_drafts.0.photo_task_subtype'))->toBe('auto');
    $this->post(route('intakes.workspace.tasks.store', $this->intake), [
        'contribution_items' => session('customer_task_drafts'),
    ])->assertSessionHasNoErrors();
    $task = ContributionTask::query()->whereNotNull('intake_follow_up_item_id')->sole();
    expect($task->meta)->not->toHaveKey('photo_subject_check')
        ->and(PhotoSubject::expectedForTask($task))->toBe(PhotoSubject::Fusebox);
});

test('N66 incompatible explicit subtype fails instead of disappearing', function (string $area, string $subtype) {
    $this->post(route('intakes.workspace.tasks.store', $this->intake), [
        'contribution_items' => [[...$this->draft, 'decision_area_key' => $area, 'photo_task_subtype' => $subtype]],
    ])->assertSessionHasErrors([
        'contribution_items.0.photo_task_subtype' => 'Dit fototype past niet bij het gekozen onderdeel.',
    ]);
    expect($this->intake->followUpRounds()->count())->toBe(0);
})->with([['power', 'around_house'], ['placement', 'fusebox'], ['capacity', 'power_route']]);

test('N66 concept only offers photo subtypes compatible with its area', function (string $area, array $allowed) {
    $draft = $this->draft;
    unset($draft['photo_task_subtype']);
    $this->post(route('intakes.workspace.tasks.prepare', $this->intake), [
        ...$draft, 'decision_area_key' => $area,
    ])->assertSessionHasNoErrors();
    $response = $this->get(route('intakes.workspace', $this->intake))->assertOk()
        ->assertSee('<option value="auto" selected', false)
        ->assertSee('<option value=""', false);
    foreach (PhotoSubject::PHOTO_TASK_SUBTYPES as $subtype) {
        if (in_array($subtype, $allowed, true)) {
            $response->assertSee('<option value="'.$subtype.'"', false);
        } else {
            $response->assertDontSee('<option value="'.$subtype.'"', false);
        }
    }
})->with([['placement', ['around_house']], ['capacity', []], ['refrigerant', []]]);

test('N66 auto uses existing subtype metadata just like an absent key', function () {
    app(CreateCustomerContributionRequest::class)->handle(
        $this->intake,
        $this->user,
        [[...$this->draft, 'photo_task_subtype' => 'auto', 'meta' => ['photo_task_subtype' => 'power_route']]],
    );
    $task = ContributionTask::query()->whereNotNull('intake_follow_up_item_id')->sole();
    expect($task->meta)->toMatchArray(PhotoSubject::photoTaskMetaForSubtype('power_route'))
        ->and($task->meta)->not->toHaveKey('photo_subject_check');
});
