<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\FollowUpContributionPresenter;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * UX-uitkomst 8 okt 2026 (Notion #15/#16), BL-147.
 */

beforeEach(function () {
    $this->withoutVite();
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

test('ronde 3 punt 7: taak zonder ruimte springt naar het beslisgebied en licht “Nieuwe aanvulling ontvangen” op', function (string $areaKey, string $areaLabel, string $prompt) {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    app(DossierManager::class)->initialize($intake);

    $intake = bl147CompleteTextTask($intake, $user, [
        'prompt' => $prompt,
        'decision_area_key' => $areaKey,
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ], 'Antwoord van de klant');

    $item = app(FollowUpContributionPresenter::class)->present($intake)['items'][0];
    expect($item['heading'])->toBe('Nieuw van klant · '.$areaLabel)
        ->and($item['review_href'])->toBe('#dossier-area-'.$areaKey)
        ->and($item['review_label'])->toBe('Beoordeel bij '.$areaLabel)
        ->and($item['highlight_field_ids'])->toBe(['dossier-area-'.$areaKey.'-contribution'])
        ->and($item['room_name'])->toBeNull();

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->assertOk()->getContent();
    expect($html)->toContain('Nieuw van klant · '.$areaLabel)
        ->toContain('Beoordeel bij '.$areaLabel)
        ->toContain('href="#dossier-area-'.$areaKey.'"')
        ->toContain('data-highlight-fields="dossier-area-'.$areaKey.'-contribution"')
        ->toContain('id="dossier-area-'.$areaKey.'"')
        ->toContain('id="dossier-area-'.$areaKey.'-contribution"');
})->with([
    'stroom' => ['power', 'Stroomtoevoer', 'Hoeveel groepen heeft je meterkast?'],
    // Was #workspace-rooms; besluit 8 okt: naar het capaciteitsblok.
    'capaciteit' => ['capacity', 'Benodigd vermogen', 'Hoe warm wordt het huis in de zomer?'],
]);

test('ronde 3 punt 7: taak zonder beslisgebied gaat naar de open punten', function () {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    app(DossierManager::class)->initialize($intake);

    $intake = bl147CompleteTextTask($intake, $user, [
        'prompt' => 'Is er nog iets dat we moeten weten?',
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ], 'De kat is bang voor boren.');

    $item = app(FollowUpContributionPresenter::class)->present($intake)['items'][0];
    expect($item['heading'])->toBe('Nieuw van klant')
        ->and($item['review_href'])->toBe('#workspace-open-items')
        ->and($item['review_label'])->toBe('Beoordeel in opname')
        ->and($item['highlight_field_ids'])->toBe([]);

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->assertOk()->getContent();
    expect($html)->toContain('Beoordeel in opname')
        ->toContain('href="#workspace-open-items"')
        ->toContain('id="workspace-open-items"')
        ->not->toContain('Nieuw van klant ·');
});

test('ronde 3 punt 7: ruimtevraag over iets anders dan maten opent het paneel zonder oplichten', function () {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, ['name' => 'Slaapkamer 1', 'use_type' => 'bedroom']);

    $intake = bl147CompleteTextTask($intake, $user, [
        'prompt' => 'Geef aan waarvoor Slaapkamer 1 vooral gebruikt wordt.',
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => $room->dossier_subject_id,
    ], 'Slapen en thuiswerken');

    $item = app(FollowUpContributionPresenter::class)->present($intake)['items'][0];
    expect($item['heading'])->toBe('Nieuw van klant · Slaapkamer 1')
        ->and($item['review_href'])->toBe('#room-'.$room->id)
        ->and($item['highlight_field_ids'])->toBe([]);
});

test('ronde 3 punt 8: stroomaansluiting-keuzes lopen door of gaan naar één kolom (bewerken én toevoegen)', function () {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, ['name' => 'Woonkamer', 'use_type' => 'living_room']);
    app(AircoSurveyService::class)->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit woonkamer',
    ]);

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->assertOk()->getContent();
    $grid = 'grid-cols-[repeat(auto-fit,minmax(min(100%,max(11rem,calc(50%_-_0.25rem))),1fr))]';
    expect(substr_count($html, $grid))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('min-w-0 break-words hyphens-auto')
        ->toContain('Stroomaansluiting');
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

test('ronde 3 punt 1+2: bedankscherm hoofdwizard zegt hetzelfde als de aanvulflow, zonder websiteknop', function () {
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => false,
    ]);

    // Alleen de weergave van het bedankscherm; het afronden zelf testen CompleteAndReview/Klanttest.
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('completed', true)
        ->assertSee('Je kunt dit venster nu sluiten.')
        ->assertDontSee('Je kunt dit venster sluiten.')
        ->assertDontSee('Naar de website van')
        ->assertDontSee('verwijderd.');
});

test('bedankscherm echte klant toont websiteknop als het bedrijf een website heeft; demo niet', function () {
    $company = Company::factory()->create([
        'name' => 'Lente Koeling',
        'website' => 'https://www.lentekoeling.nl',
    ]);
    $user = User::factory()->for($company)->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => false,
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('completed', true)
        ->assertSee('Naar de website van Lente Koeling')
        ->assertSee('href="https://www.lentekoeling.nl"', false)
        ->assertSee('Je kunt dit venster nu sluiten.');

    $demo = Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => true,
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $demo->access_token])
        ->set('completed', true)
        ->assertDontSee('Naar de website van')
        ->assertDontSee('Je kunt dit venster nu sluiten.');
});

test('hoofdwizard-foto is 8 s ongedaan te maken en zet afgeleide antwoorden terug', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    FakeAiClient::reset();
    config(['ai.provider' => 'fake', 'ai.photo_inference.enabled' => true]);
    FakeAiClient::alwaysReturn([
        'empty_module_space' => 'visible',
        'phase' => 'three_phase',
        'confidence' => 'high',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'evidence' => 'Een lege modulepositie is zichtbaar.',
        'retake_instruction' => null,
    ]);

    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id,
        'status' => IntakeStatus::Sent,
    ]);
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );
    app(AssessFuseboxPhotos::class)->handle($intake);
    $factBefore = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->first();
    $derivedBefore = $intake->answers()
        ->whereIn('prefill_source', ['ai_photo', 'ai_photo_suggestion', 'ai'])
        ->get(['question_key', 'value', 'prefill_source']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $fuseboxStep = collect($component->viewData('steps'))->firstWhere('question_key', 'fusebox_photo');
    expect($fuseboxStep)->not->toBeNull();

    $component->set('activeStepKey', $fuseboxStep['key'])
        ->call('removePhoto', $upload->id)
        ->assertSee('Foto verwijderd.')
        ->assertSee('Ongedaan maken')
        ->assertSet('saveMessage', '')
        ->assertSet('pendingWizardRemoval.upload_id', $upload->id);
    expect(IntakeUpload::withTrashed()->find($upload->id)?->trashed())->toBeTrue()
        ->and(Storage::disk($upload->disk)->exists($upload->path))->toBeTrue();

    $component->call('undoWizardUploadRemoval')
        ->assertDontSee('Ongedaan maken')
        ->assertSet('pendingWizardRemoval', null);
    expect(IntakeUpload::query()->find($upload->id))->not->toBeNull();
    if ($factBefore !== null) {
        expect($intake->fresh()->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->exists())->toBeTrue();
    }
    foreach ($derivedBefore as $row) {
        $restored = $intake->fresh()->answers()
            ->where('question_key', $row->question_key)
            ->whereNull('section_instance_key')
            ->first();
        expect($restored?->value)->toBe($row->value)
            ->and($restored?->prefill_source)->toBe($row->prefill_source);
    }

    $component->call('removePhoto', $upload->id)
        ->call('finalizePendingWizardRemoval', $upload->id)
        ->assertDontSee('Ongedaan maken');
    expect(Storage::disk($upload->disk)->exists($upload->path))->toBeFalse()
        ->and(IntakeActivityEvent::query()
            ->where('intake_id', $intake->id)
            ->where('event', 'upload_deleted')
            ->count())->toBe(1);
    FakeAiClient::reset();
});

test('ronde 3 punt 3: demo beëindigd heeft de nieuwe kop en tekst; verlopen blijft gelijk', function () {
    $this->get(route('demo.ended'))
        ->assertOk()
        ->assertSee('<title>De demo is beëindigd — Digitale Opname</title>', false)
        ->assertSee('De demo is beëindigd')
        ->assertSee('Je demogegevens worden automatisch gewist. Je kunt altijd een nieuwe demo starten.')
        ->assertSee('Naar de homepage')
        ->assertSee('Nieuwe demo starten');

    $this->get(route('demo.ended', ['reason' => 'expired']))
        ->assertOk()
        ->assertSee('<title>Demo beëindigd — Digitale Opname</title>', false)
        ->assertSee('Deze demo is verlopen')
        ->assertSee('De demosessie is verlopen. Demogegevens verdwijnen automatisch. Je kunt opnieuw beginnen met een schone demo.')
        ->assertDontSee('De demo is beëindigd');
});

test('ronde 3 punt 6: demostrook na een aanvulling heeft altijd één zin', function () {
    $html = Blade::render('<x-demo-scope-notice variant="complete" />');
    expect($html)->toContain('Je hebt als klant een aanvulling verstuurd. Geen echte klant, er ging geen mail uit. De gegevens verdwijnen vanzelf.')
        ->not->toContain('beoordeelt de foto')
        ->not->toContain('Je hebt één aanvulling verstuurd');
});

test('#16 extra: vervolgronde toont Opdracht x van y en geen intern woord Ronde', function () {
    $user = User::factory()->create();
    $intake = bl147Intake($user);
    app(DossierManager::class)->initialize($intake);

    app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [
        [
            'type' => FollowUpItemType::Text,
            'prompt' => 'Hoe lang is slaapkamer 2?',
            'decision_area_key' => 'capacity',
        ],
        [
            'type' => FollowUpItemType::Text,
            'prompt' => 'Hoe hoog is slaapkamer 2?',
            'decision_area_key' => 'capacity',
        ],
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->assertSee('Opdracht 1 van 2')
        ->assertDontSee('Ronde 1')
        ->assertDontSee('Onderdeel 1 van 2')
        ->assertSee('Je antwoord')
        ->assertSeeHtml('placeholder="Typ hier je antwoord"');
});

test('#15.9: aanmaakscherm gebruikt de vaste term Zelf de opname doen', function () {
    $this->withoutVite();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('intakes.create'))
        ->assertOk()
        ->assertSee('Zelf de opname doen')
        ->assertDontSee('Zelf de opname uitvoeren');
});
