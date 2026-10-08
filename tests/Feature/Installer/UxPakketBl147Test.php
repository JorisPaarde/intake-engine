<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\FollowUpContributionPresenter;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * UX-uitkomst 8 okt 2026 (Notion #15/#16), BL-147.
 */

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config(['ai.provider' => 'fake', 'ai.dossier.enabled' => false]);
});

function bl147Intake(User $user): Intake
{
    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'UX Pakket',
        'customer_email' => 'ux147@example.com',
        'address_line' => 'Uxlaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
}

/**
 * @param  array<string, mixed>  $task
 */
function bl147CompleteTextTask(Intake $intake, User $user, array $task, string $answer): Intake
{
    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [array_merge([
        'type' => FollowUpItemType::Text,
    ], $task)]);
    $item = $round->items()->firstOrFail();

    Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->set('followUpResponses.'.$item->id, $answer)
        ->call('completeFollowUp')
        ->assertSet('completed', true);

    return $intake->fresh();
}

test('#15.1/#15.2: Beoordeel springt naar de ruimte van de taak en de hint volgt de gevraagde maat', function () {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, ['name' => 'Slaapkamer 2', 'use_type' => 'bedroom']);

    $intake = bl147CompleteTextTask($intake, $user, [
        'prompt' => 'Meet of noteer de lengte en breedte van Slaapkamer 2, of het vloeroppervlak in m².',
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => $room->dossier_subject_id,
    ], '4 bij 3 meter');

    $item = app(FollowUpContributionPresenter::class)->present($intake)['items'][0];
    expect($item['review_href'])->toBe('#room-'.$room->id)
        ->and($item['review_label'])->toBe('Beoordeel bij Slaapkamer 2')
        ->and($item['installer_decides'])->toBe('Jij beslist of deze maten kloppen voor de capaciteit.')
        ->and($item['highlight_field_ids'])->toBe(['room-'.$room->id.'-length', 'room-'.$room->id.'-width']);

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->assertOk()->getContent();
    expect($html)->toContain('Nieuw van klant · Slaapkamer 2')
        ->toContain('Beoordeel bij Slaapkamer 2')
        ->toContain('href="#room-'.$room->id.'"')
        ->toContain('data-highlight-fields="room-'.$room->id.'-length room-'.$room->id.'-width"')
        ->toContain('id="room-'.$room->id.'-length"')
        ->not->toContain('niet blind het hoogste punt overnemen');
});

test('#15.2: hoogte-, oppervlak- en overige capaciteitsvragen krijgen hun eigen hint', function (string $prompt, array $meta, string $expected) {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, ['name' => 'Zolder', 'use_type' => 'attic']);

    $intake = bl147CompleteTextTask($intake, $user, [
        'prompt' => $prompt,
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => $room->dossier_subject_id,
        'meta' => $meta,
    ], 'antwoord');

    expect(app(FollowUpContributionPresenter::class)->present($intake)['items'][0]['installer_decides'])->toBe($expected);
})->with([
    'hoogte (meta)' => ['Meet de zolder.', ['requested_field' => 'height_m'], 'Jij beslist welke hoogte telt voor de capaciteit.'],
    'hoogte (tekst)' => ['Meet of noteer de hoogte van Zolder.', [], 'Jij beslist welke hoogte telt voor de capaciteit.'],
    'oppervlak' => ['Noteer het vloeroppervlak van Zolder.', [], 'Jij beslist of dit oppervlak klopt voor de capaciteit.'],
    'overig' => ['Geef aan waarvoor Zolder vooral gebruikt wordt.', [], 'Jij beslist wat dit betekent voor de capaciteit.'],
]);

test('#15.1: taak zonder ruimte linkt naar het beslisgebied met Beoordeel in opname', function () {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    app(DossierManager::class)->initialize($intake);

    $intake = bl147CompleteTextTask($intake, $user, [
        'prompt' => 'Hoeveel groepen heeft je meterkast?',
        'decision_area_key' => 'power',
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ], 'Zes groepen');

    $item = app(FollowUpContributionPresenter::class)->present($intake)['items'][0];
    expect($item['review_href'])->toBe('#dossier-area-power')
        ->and($item['review_label'])->toBe('Beoordeel in opname')
        ->and($item['room_name'])->toBeNull();

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->assertOk()->getContent();
    expect($html)->toContain('Beoordeel in opname')
        ->not->toContain('Nieuw van klant ·');
});

test('#15.3: ruimtekaart leest binnenunit en koppeling', function () {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 2', 'use_type' => 'bedroom']);

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->getContent();
    expect($html)->toContain('Binnenunit nog kiezen')
        ->not->toContain('Nog niet gekoppeld');

    $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit slaapkamer 2',
    ]);
    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->getContent();
    expect($html)->toContain('Binnenunit: Binnenunit slaapkamer 2 · buitenunit nog kiezen')
        ->toContain('Nog kiezen')
        ->not->toContain('Nog open')
        ->not->toContain('Nog niet gekoppeld');

    $this->actingAs($user)->post(route('intakes.workspace.rooms.unit-coupling', [$intake, $room]), [
        'indoor_label' => 'Binnenunit slaapkamer 2',
        'outdoor_label' => 'Buitenunit gevel',
        'configuration_type' => AircoConfigurationType::cases()[0]->value,
    ])->assertRedirect();
    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->getContent();
    expect($html)->toContain('Gekoppeld: Binnenunit slaapkamer 2 → Buitenunit gevel');
});

test('#15.4: flash per type, in het toegevoegde blok', function (AircoPlacementType $type, string $message) {
    $user = User::factory()->create();
    $intake = bl147Intake($user);

    $response = $this->actingAs($user)->post(route('intakes.workspace.placements.store', $intake), [
        'type' => $type->value,
        'label' => 'Test '.$type->value,
    ]);
    $placement = $intake->aircoPlacements()->latest('id')->firstOrFail();

    $response->assertRedirect(route('intakes.workspace', $intake).'#placement-'.$placement->id)
        ->assertSessionHas('block_status', ['target' => 'placement-'.$placement->id, 'message' => $message])
        ->assertSessionMissing('status');

    $html = $this->actingAs($user)
        ->withSession(['block_status' => ['target' => 'placement-'.$placement->id, 'message' => $message]])
        ->get(route('intakes.workspace', $intake))
        ->getContent();
    $block = substr($html, (int) strpos($html, 'id="placement-'.$placement->id.'"'));
    expect($block)->toContain($message)
        ->and($html)->not->toContain('data-testid="workspace-status"');
})->with([
    'buitenunit' => [AircoPlacementType::OutdoorUnit, 'Buitenunit toegevoegd.'],
    'stroom' => [AircoPlacementType::PowerSource, 'Stroomaansluiting toegevoegd.'],
    'afvoer' => [AircoPlacementType::DrainPoint, 'Afvoerpunt toegevoegd.'],
]);

test('#16.4: foto weghalen is 8 s ongedaan te maken en wordt pas daarna echt gewist', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ]]);
    $item = $round->items()->firstOrFail();
    $photo = UploadedFile::fake()->createWithContent(
        'meterkast.jpg',
        (string) file_get_contents(base_path('tests/fixtures/klanttest-20261002/meterkast-flow.jpg')),
    );

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->set('followUpPhotoFiles.'.$item->id, $photo)
        ->assertSee('Foto verwijderen')
        ->assertDontSee('absolute inset-x-0 bottom-0', false);
    $upload = $item->fresh()->uploads()->firstOrFail();
    $answeredAt = $item->fresh()->answered_at;
    $assessmentBefore = $upload->fresh()->assessment_status;

    // Weghalen: verborgen, maar nog niet gewist.
    $component->call('removeFollowUpUpload', $item->id, $upload->id)
        ->assertSee('Foto verwijderd.')
        ->assertSee('Ongedaan maken');
    expect(IntakeUpload::withTrashed()->find($upload->id)?->trashed())->toBeTrue()
        ->and(Storage::disk($upload->disk)->exists($upload->path))->toBeTrue()
        ->and($item->fresh()->answered_at)->toBeNull();

    // Ongedaan maken: foto en beoordeling terug.
    $component->call('undoFollowUpUploadRemoval')
        ->assertDontSee('Foto verwijderd.')
        ->assertSet('pendingFollowUpRemoval', null);
    $restored = IntakeUpload::query()->find($upload->id);
    expect($restored)->not->toBeNull()
        ->and($restored?->assessment_status)->toBe($assessmentBefore)
        ->and($item->fresh()->answered_at?->toIso8601String())->toBe($answeredAt?->toIso8601String());

    // Een late timer van een oudere melding wist niets.
    $component->call('removeFollowUpUpload', $item->id, $upload->id)
        ->call('finalizePendingFollowUpRemoval', $upload->id + 999);
    expect(Storage::disk($upload->disk)->exists($upload->path))->toBeTrue();

    // Na 8 s (timer) echt gewist.
    $component->call('finalizePendingFollowUpRemoval', $upload->id)
        ->assertDontSee('Ongedaan maken');
    expect(Storage::disk($upload->disk)->exists($upload->path))->toBeFalse()
        ->and(IntakeActivityEvent::query()
            ->where('intake_id', $intake->id)
            ->where('event', 'follow_up_upload_deleted')
            ->count())->toBe(1);
});
