<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function wizardNavFixture(string $name): UploadedFile
{
    $mapped = match ($name) {
        'meterkast-groot.jpg' => 'meterkast-flow.jpg',
        'buitenunit-leiding.jpeg' => 'buitenunit-flow.jpeg',
        default => $name,
    };
    $path = base_path('tests/fixtures/klanttest-20261002/'.$mapped);
    expect(is_file($path))->toBeTrue("Fixture ontbreekt: {$name}");

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

function makeWizardNavIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Wizard Nav',
        'customer_email' => 'wizard-nav@example.com',
    ], $overrides));
}

/**
 * Twee slaapkamers + bekende maten/type zodat room_name zichtbaar is en tussenliggende
 * vragen overgeslagen worden — daarna volgt wall_outlet_photo wanneer outlets ontbreken.
 */
function seedTwoBedroomRoomNameFlow(Intake $intake, string $outletStatus = 'needs_photo'): void
{
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => 'Twee slaapkamers koelen']);

    foreach (['room-1', 'room-2'] as $instance) {
        foreach ([
            ['room_type', ['value' => 'bedroom'], PrefillSources::AI_TEXT],
            ['room_size_indication', ['value' => 'medium'], PrefillSources::AI_TEXT],
            ['room_length_m', ['number' => 4.0], PrefillSources::AI_TEXT],
            ['room_width_m', ['number' => 3.0], PrefillSources::AI_TEXT],
            ['room_area_m2', ['number' => 12.0], PrefillSources::AI_TEXT],
            ['ceiling_height_m', ['number' => 2.5], PrefillSources::AI_TEXT],
            ['sun_exposure', ['value' => 'medium'], PrefillSources::AI_TEXT],
            ['glass_amount', ['value' => 'average'], PrefillSources::AI_TEXT],
            ['glazing_type', ['value' => 'double'], PrefillSources::AI_TEXT],
            ['floor_level', ['value' => '1'], PrefillSources::AI_TEXT],
            ['room_outlet_status', ['value' => $outletStatus], PrefillSources::AI_PHOTO],
        ] as [$key, $value, $source]) {
            app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, $value, $source);
        }

        foreach (['room_photos', 'indoor_unit_position_photo'] as $photoKey) {
            $upload = app(StoreIntakeUpload::class)->handle(
                $intake,
                $photoKey,
                $instance,
                wizardNavFixture('woonkamer-1440.jpg'),
            );
            $upload->updateQuietly([
                'usability_verdict' => PhotoUsabilityVerdict::Ok,
                'assessment_status' => PhotoAssessmentStatus::Assessed,
                'content_assessment' => [
                    'status' => 'ok',
                    'expected_subject' => 'room',
                    'detected_subject' => 'room',
                    'customer_message' => null,
                ],
            ]);
        }
    }
}

function runWizardNavPhotoJob(int $uploadId): void
{
    runAssessUploadedPhotoJob($uploadId);
}

/**
 * Upload → eventueel queue job (geen sync AI) → job runnen → poll tot klaar.
 * Foto’s zonder AI-profiel (bijv. wall_outlet_photo) blijven bij lokale usability.
 */
function uploadAndPollPhotoAssessment(Testable $component, string $composite, UploadedFile $file): Testable
{
    Queue::fake([AssessUploadedPhotoJob::class]);

    $component->set('photoFiles.'.$composite, $file);

    if ($component->get('uploadPhase') !== 'assessing') {
        // Geen AI-profiel: lokale usability is genoeg; geen job.
        Queue::assertNothingPushed();

        return $component->assertSet('uploadPhase', '');
    }

    $pushed = [];
    foreach (Queue::pushed(AssessUploadedPhotoJob::class) as $job) {
        if ($job instanceof AssessUploadedPhotoJob) {
            $pushed[] = $job->uploadId;
        }
    }

    // BL-143: too_small → HeuristicRejected zonder AI-job; poll past kwaliteitshint toe.
    if ($pushed === []) {
        return $component
            ->call('pollPendingAssessments')
            ->assertSet('uploadPhase', '');
    }

    foreach (array_values(array_unique($pushed)) as $uploadId) {
        runWizardNavPhotoJob($uploadId);
    }

    return $component
        ->call('pollPendingAssessments')
        ->assertSet('uploadPhase', '');
}

test('kamernaam-autosave houdt actieve vraag vast; Volgende valideert niet de opvolger', function () {
    $intake = makeWizardNavIntake();
    seedTwoBedroomRoomNameFlow($intake, 'needs_photo');

    // preferred_indoor al beantwoord → na room_name volgt direct de verplichte stopcontactfoto.
    foreach (['room-1', 'room-2'] as $instance) {
        app(SaveIntakeAnswer::class)->handle(
            $intake,
            'preferred_indoor_location',
            $instance,
            ['text' => 'Boven de deur'],
            PrefillSources::AI_TEXT,
        );
    }

    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_name',
        'current_section_instance_key' => 'room-1',
    ]);

    FakeAiClient::alwaysReturn([
        'room_type' => 'bedroom',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'present',
        'detected_subject' => 'wall_outlet',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Stopcontact duidelijk in beeld.',
        'retake_instruction' => null,
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'rooms::room-1::room_name');

    $totalBefore = count($component->viewData('steps'));
    $displayBefore = $component->viewData('stepDisplayNumber');
    expect($totalBefore)->toBeGreaterThan(1)
        ->and(array_column($component->viewData('steps'), 'key'))
        ->toContain('rooms::room-1::wall_outlet_photo');

    // Echte klantflow: blur/autosave via Livewire set (niet buitenband save).
    $component->set('form.room-1__room_name.text', 'Ouders');

    // Sticky: geen sprong naar stopcontact, totaal/nummer stabiel tot Volgende.
    expect($component->get('activeStepKey'))->toBe('rooms::room-1::room_name')
        ->and(count($component->viewData('steps')))->toBe($totalBefore)
        ->and($component->viewData('stepDisplayNumber'))->toBe($displayBefore)
        ->and($component->get('showMissing'))->toBeFalse();

    $component->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', 'rooms::room-1::wall_outlet_photo');

    expect(count($component->viewData('steps')))->toBe($totalBefore - 1);

    uploadAndPollPhotoAssessment(
        $component,
        'room-1__wall_outlet_photo',
        wizardNavFixture('woonkamer-1440.jpg'),
    )->assertSet('showMissing', false);

    $beforeNext = $component->get('activeStepKey');
    expect($beforeNext)->toBe('rooms::room-1::wall_outlet_photo');

    $component->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', function (string $key) use ($beforeNext): bool {
            return $key !== $beforeNext && $key !== '';
        });
});

test('room_name opslaan + foto + Volgende zonder reload: stabiele vraag-id, geen index-drift', function () {
    $intake = makeWizardNavIntake();
    seedTwoBedroomRoomNameFlow($intake, 'needs_photo');

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepsBefore = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $keysBefore = array_column($stepsBefore, 'key');

    expect($keysBefore)->toContain('rooms::room-1::room_name')
        ->and($keysBefore)->toContain('rooms::room-1::wall_outlet_photo');

    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_name',
        'current_section_instance_key' => 'room-1',
    ]);

    FakeAiClient::alwaysReturn([
        'room_type' => 'bedroom',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'present',
        'detected_subject' => 'wall_outlet',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Stopcontact duidelijk in beeld.',
        'retake_instruction' => null,
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'rooms::room-1::room_name');

    $totalBefore = count($component->viewData('steps'));
    $knownBefore = $component->get('knownStepKeys');
    expect($totalBefore)->toBeGreaterThan(1)
        ->and($knownBefore)->toContain('rooms::room-1::room_name')
        ->and($knownBefore)->toContain('rooms::room-1::wall_outlet_photo');

    // Autosave via Livewire (blur): sticky houdt room_name vast; Volgende schuift pas daarna door.
    $component->set('form.room-1__room_name.text', 'Slaapkamer ouders')
        ->assertSet('activeStepKey', 'rooms::room-1::room_name')
        ->assertSet('showMissing', false);

    expect(count($component->viewData('steps')))->toBe($totalBefore);

    $component->call('next')
        ->assertSet('showMissing', false);

    $afterNameKey = $component->get('activeStepKey');
    $keysAfterName = array_column($component->viewData('steps'), 'key');
    expect($afterNameKey)->not->toBe('rooms::room-1::room_name')
        ->and($afterNameKey)->not->toBe('')
        ->and($keysAfterName)->not->toContain('rooms::room-1::room_name')
        ->and(count($keysAfterName))->toBe($totalBefore - 1)
        // v22/v23: preferred_indoor_location (optioneel) vóór wall_outlet wanneer outlets een foto nodig hebben.
        ->and(in_array($afterNameKey, [
            'rooms::room-1::preferred_indoor_location',
            'rooms::room-1::wall_outlet_photo',
        ], true))->toBeTrue();

    // Skip optional preferred_indoor_location to reach the photo check when present.
    if ($afterNameKey === 'rooms::room-1::preferred_indoor_location') {
        $component->set('form.room-1__preferred_indoor_location.text', 'Weet ik niet')
            ->call('next')
            ->assertSet('showMissing', false)
            ->assertSet('activeStepKey', 'rooms::room-1::wall_outlet_photo');
    }

    uploadAndPollPhotoAssessment(
        $component,
        'room-1__wall_outlet_photo',
        wizardNavFixture('woonkamer-1440.jpg'),
    )->assertSet('showMissing', false);

    $upload = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'wall_outlet_photo')
        ->where('section_instance_key', 'room-1')
        ->latest('id')
        ->first();

    expect($upload)->not->toBeNull()
        ->and($upload->usability_verdict)->toBe(PhotoUsabilityVerdict::Ok);

    // Simuleer stale form zonder upload_ids (pre-fix faalde hier tot een reload).
    $component->instance()->form['room-1__wall_outlet_photo'] = [];

    $beforeNext = $component->get('activeStepKey');
    expect($beforeNext)->toBe('rooms::room-1::wall_outlet_photo');

    $component->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', function (string $key) use ($beforeNext): bool {
            return $key !== $beforeNext && $key !== '';
        });
});

test('vraaglijst krimpt en groeit mid-flow zonder index-drift (beide richtingen)', function () {
    $intake = makeWizardNavIntake();
    seedTwoBedroomRoomNameFlow($intake, 'present'); // geen wall_outlet tot analyse dat wijzigt

    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_name',
        'current_section_instance_key' => 'room-1',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'rooms::room-1::room_name');

    $countWithName = count($component->viewData('steps'));

    // Autosave: sticky houdt room_name vast tot Volgende (geen index-sprong).
    $component->set('form.room-1__room_name.text', 'Ouders');
    expect(count($component->viewData('steps')))->toBe($countWithName)
        ->and($component->get('activeStepKey'))->toBe('rooms::room-1::room_name')
        ->and($component->get('showMissing'))->toBeFalse();

    $component->call('next')
        ->assertSet('showMissing', false);

    $keyAfterLeave = $component->get('activeStepKey');
    $countWithoutName = count($component->viewData('steps'));
    expect($keyAfterLeave)->not->toBe('rooms::room-1::room_name')
        ->and($countWithoutName)->toBe($countWithName - 1);

    // Grow: room_photos-analyse zet outlets op needs_photo → wall_outlet verschijnt.
    FakeAiClient::alwaysReturn([
        'room_type' => 'bedroom',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'glazing_type' => 'double',
        'room_outlet_status' => 'needs_photo',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Ruimte zichtbaar, geen stopcontact.',
        'retake_instruction' => null,
    ]);

    $existingRoomPhoto = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->where('section_instance_key', 'room-1')
        ->latest('id')
        ->firstOrFail();

    $component
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->call('removePhoto', $existingRoomPhoto->id)
        // Hoofdwizard wist direct; melding mét punt (BL-147 ronde 3, punt 5).
        ->assertSet('saveMessage', 'Foto verwijderd.');

    uploadAndPollPhotoAssessment(
        $component,
        'room-1__room_photos',
        wizardNavFixture('woonkamer-funda-1440.jpg'),
    );

    $stepsAfterGrow = $component->viewData('steps');
    $keysAfterGrow = array_column($stepsAfterGrow, 'key');
    expect($keysAfterGrow)->toContain('rooms::room-1::wall_outlet_photo')
        ->and(count($stepsAfterGrow))->toBeGreaterThan($countWithoutName);

    // Actieve stap blijft de room_photos-vraag (niet een verschoven index).
    expect($component->get('activeStepKey'))->toBe('rooms::room-1::room_photos')
        ->and($component->get('showMissing'))->toBeFalse();

    $component->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', function (string $key): bool {
            return $key !== 'rooms::room-1::room_photos';
        });
});

test('stale foto-feedback verdwijnt na Vervang/Verwijderen zonder reload', function () {
    $intake = makeWizardNavIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_photos',
        'current_section_instance_key' => 'room-1',
    ]);

    // 1) Verkeerde meterkastfoto op ruimtevraag → mismatch + eventueel extra-taaknotitie.
    FakeAiClient::alwaysReturn([
        'room_type' => 'unknown',
        'room_size_indication' => 'unknown',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'room_outlet_status' => 'needs_photo',
        'detected_subject' => 'fusebox',
        'subject_match' => 'no',
        'confidence' => 'high',
        'evidence' => 'Dit lijkt een meterkast.',
        'retake_instruction' => null,
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    uploadAndPollPhotoAssessment(
        $component,
        'room-1__room_photos',
        wizardNavFixture('meterkast-groot.jpg'),
    );

    $wrong = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->latest('id')
        ->firstOrFail();

    expect($wrong->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);

    $component->assertSee('Vervang foto');

    // 2) Vervang → 3) 720px ruimtefoto (lage resolutie + stopcontact-instructie).
    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'needs_photo',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Ruimte vanuit deuropening.',
        'retake_instruction' => null,
    ]);

    $component->call('replaceMismatchedPhoto');

    uploadAndPollPhotoAssessment(
        $component,
        'room-1__room_photos',
        wizardNavFixture('woonkamer-720.jpg'),
    );

    $lowRes = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->latest('id')
        ->firstOrFail();

    expect($lowRes->id)->not->toBe($wrong->id)
        ->and($lowRes->usability_verdict)->toBe(PhotoUsabilityVerdict::TooSmall);

    $component
        ->assertSee('lage resolutie')
        ->assertSee('stopcontact');

    $hintAfterLowRes = $component->get('photoHint')['room-1__room_photos'] ?? null;
    $extraAfterLowRes = $component->get('progressExtraNote');
    expect($hintAfterLowRes)->toBeString()->toContain('lage resolutie');

    // Forceer een progress-extra-notitie zoals na analyse met nieuwe taak (repro-tekst).
    if ($extraAfterLowRes === '') {
        $component
            ->set('progressExtraNote', 'Na je foto hebben we nog één vraag: Extra foto van de wand met stopcontact')
            ->set('progressExtraNoteUploadIds', [$lowRes->id]);
    }

    $component->assertSee('Na je foto hebben we nog één vraag');

    // 4) Verwijderen → oude feedback moet weg (zonder reload).
    $component->call('removePhoto', $lowRes->id);

    expect($component->get('photoHint')['room-1__room_photos'] ?? null)->toBeNull()
        ->and($component->get('photoHintScope')['room-1__room_photos'] ?? null)->toBeNull()
        ->and($component->get('progressExtraNote'))->toBe('');

    $component
        ->assertDontSee('lage resolutie')
        ->assertDontSee('Na je foto hebben we nog één vraag');

    // 5) Goede 1440px foto → Beoordeeld, geen stale low-res/stopcontact-tekst.
    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'large',
        'sun_exposure' => 'high',
        'glass_amount' => 'much',
        'room_outlet_status' => 'present',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Heldere ruimtefoto met stopcontacten.',
        'retake_instruction' => null,
    ]);

    uploadAndPollPhotoAssessment(
        $component,
        'room-1__room_photos',
        wizardNavFixture('woonkamer-1440.jpg'),
    );

    $good = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->latest('id')
        ->firstOrFail();

    expect($good->usability_verdict)->toBe(PhotoUsabilityVerdict::Ok)
        ->and($good->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_OK);

    $component
        ->assertDontSee('lage resolutie')
        ->assertDontSee('Deze foto lijkt erg donker')
        ->assertSet('showMissing', false);
});

test('lege woonkamer zonder outlet-needs triggert geen extra stopcontactvraag', function () {
    $intake = makeWizardNavIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_photos',
        'current_section_instance_key' => 'room-1',
    ]);

    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'large',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'present',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Lege woonkamer, stopcontacten zichtbaar.',
        'retake_instruction' => null,
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    uploadAndPollPhotoAssessment(
        $component,
        'room-1__room_photos',
        wizardNavFixture('woonkamer-1440.jpg'),
    );

    $keys = array_column($component->viewData('steps'), 'question_key');
    expect($keys)->not->toContain('wall_outlet_photo');
});

test('Weet ik niet blokkeert Volgende niet op optionele korte tekst', function () {
    $intake = makeWizardNavIntake();
    // v22/BL-129: merk/planning/opmerkingen zitten in één closing_wishes-scherm.
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => 'Koelen']);
    app(SaveIntakeAnswer::class)->handle($intake, 'cooling_heating', null, ['value' => 'cooling']);

    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $closing = collect($steps)->firstWhere('kind', 'closing_wishes');
    expect($closing)->not->toBeNull()
        ->and($closing['bundle_question_keys'] ?? [])->toContain('brand_preference');

    $intake->update([
        'current_section_key' => $closing['section_key'],
        'current_question_key' => $closing['question_key'],
        'current_section_instance_key' => $closing['section_instance_key'],
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', $closing['key'])
        ->set('form.brand_preference.text', 'Weet ik niet')
        ->set('form.planning_flexibility.text', 'Weet ik niet')
        ->set('form.notes.text', 'Weet ik niet')
        ->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', function (string $key) use ($closing): bool {
            return $key !== $closing['key'];
        });
});

test('bekende feiten worden niet opnieuw gevraagd (prefill skip)', function () {
    $intake = makeWizardNavIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_type',
        'room-1',
        ['value' => 'living_room'],
        PrefillSources::AI_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'building_type',
        null,
        ['value' => 'terraced'],
        'pdok',
    );

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $keys = array_column(app(IntakeStepBuilder::class)->build($intake->fresh(), $version), 'question_key');

    expect($keys)->not->toContain('room_type')
        ->and($keys)->not->toContain('building_type');
});
