<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

function makeN30Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'access_token' => str_repeat('n', 32).bin2hex(random_bytes(16)),
        'token_expires_at' => now()->addDays(14),
        'status' => IntakeStatus::Sent,
    ]);
}

/** @param  array<string, mixed>  $step */
function n30SatisfyStep(Intake $intake, array $step): void
{
    $key = (string) ($step['question_key'] ?? '');
    $instance = $step['section_instance_key'] ?? null;
    $kind = $step['kind'] ?? 'question';

    if ($kind === 'known_summary' || $kind === 'closing_wishes') {
        return;
    }

    if ($kind === 'question_group') {
        foreach ($step['group_question_keys'] ?? [] as $groupKey) {
            if ($groupKey === 'room_length_m') {
                app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', $instance, ['number' => 4]);
            }
            if ($groupKey === 'room_width_m') {
                app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', $instance, ['number' => 3]);
            }
            if ($groupKey === 'drain_location') {
                app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', $instance, ['value' => 'unknown']);
            }
        }

        return;
    }

    match ($key) {
        'privacy_consent', 'truth_confirmation', 'noise_sensitive' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['bool' => true]),
        'request_reason' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['text' => 'Synthetische testaanvraag zonder klantdata.']),
        'cooling_heating' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['value' => 'cooling']),
        'indoor_unit_count' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['number' => 1]),
        'room_type' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance ?? 'room-1', ['value' => 'living_room']),
        'room_name' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['text' => 'Woonkamer']),
        'floor_level' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance ?? 'room-1', ['value' => 'ground']),
        'sun_exposure' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance ?? 'room-1', ['value' => 'medium']),
        'glass_amount' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance ?? 'room-1', ['value' => 'average']),
        'glazing_type' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance ?? 'room-1', ['value' => 'double']),
        'preferred_indoor_location' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance ?? 'room-1', ['value' => 'outer_wall']),
        'room_outlet_nearby' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance ?? 'room-1', ['value' => 'yes']),
        'outdoor_location' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['value' => 'facade']),
        'outdoor_mount_type' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['value' => 'wall']),
        'ownership' => app(SaveIntakeAnswer::class)->handle($intake, $key, $instance, ['value' => 'owned']),
        'outdoor_location_photos', 'room_photos', 'fusebox_photo', 'around_house_photos',
        'facade_overview_photo', 'indoor_unit_position_photo', 'pipe_route_photos', 'drain_photo' => null,
        default => null,
    };

    if (in_array($key, [
        'outdoor_location_photos',
        'room_photos',
        'fusebox_photo',
        'facade_overview_photo',
        'indoor_unit_position_photo',
        'pipe_route_photos',
        'drain_photo',
    ], true)) {
        $query = $intake->fresh()->uploads()->where('question_key', $key);
        $instance === null
            ? $query->whereNull('section_instance_key')
            : $query->where('section_instance_key', $instance);
        $upload = $query->first();
        if ($upload === null) {
            $upload = app(StoreIntakeUpload::class)->handle(
                $intake,
                $key,
                $instance,
                UploadedFile::fake()->image(str_replace('_', '-', $key).'.jpg', 1000, 800),
            );
        }
        markTestUploadSatisfied($upload->fresh() ?? $upload);
    }
}

/** @return array<string, mixed> */
function n30OutdoorHigh(): array
{
    return [
        'outdoor_location' => 'facade',
        'outdoor_mount_type' => 'wall',
        'outdoor_accessibility' => 'ladder',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Synthetische gevel zonder klantdata.',
        'retake_instruction' => null,
    ];
}

/** @return array<string, mixed> */
function n30OutdoorLowUnknown(): array
{
    return [
        'outdoor_location' => 'unknown',
        'outdoor_mount_type' => 'wall',
        'outdoor_accessibility' => 'ladder',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'confidence' => 'low',
        'evidence' => 'Synthetisch overzicht zonder duidelijke unitplek.',
        'retake_instruction' => null,
    ];
}

test('N30: na hoge-zekerheid outdoor + latere invalidate verschijnt bereikbaarheid vóór koop/huur bij Volgende', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $intake = makeN30Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'noise_sensitive', null, ['bool' => true]);

    FakeAiClient::alwaysReturn(n30OutdoorHigh());
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('buiten-1.jpg', 1200, 900),
    );
    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($intake->answers()->where('question_key', 'outdoor_accessibility')->exists())->toBeTrue();

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');
    expect($stepKeys)->not->toContain('outdoor_accessibility');

    // Repro: tweede foto-analyse invalideert eerdere afleiding en past niets toe (lage zekerheid /
    // geen match). Direct invalidate i.p.v. re-assess van al-terminale uploads.
    app(DerivePhotoAnswers::class)->invalidateDerivedState(
        $intake->fresh(),
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($intake->fresh()->answers()->where('question_key', 'outdoor_accessibility')->exists())->toBeFalse();

    // Zorg dat mount-showregel klopt en outdoor-foto tevreden is.
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_location', null, ['value' => 'facade']);
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_mount_type', null, ['value' => 'wall']);
    $outdoorUpload = $intake->fresh()->uploads()->where('question_key', 'outdoor_location_photos')->first();
    expect($outdoorUpload)->not->toBeNull();
    markTestUploadSatisfied($outdoorUpload);

    // Eerst count/type zodat room-stappen bestaan; daarna alle stappen vóór bereikbaarheid.
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    $versionFresh = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    for ($pass = 0; $pass < 3; $pass++) {
        $builderSteps = app(IntakeStepBuilder::class)->build($intake->fresh(), $versionFresh);
        $accessIndexBuild = collect($builderSteps)->search(
            static fn (array $step): bool => ($step['question_key'] ?? null) === 'outdoor_accessibility',
        );
        expect($accessIndexBuild)->not->toBeFalse();
        foreach ($builderSteps as $index => $step) {
            if ($index >= (int) $accessIndexBuild) {
                break;
            }
            n30SatisfyStep($intake, $step);
        }
    }

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');

    $accessIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'outdoor_accessibility',
    );
    $noiseIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'noise_sensitive',
    );
    expect($accessIndex)->not->toBeFalse()
        ->and($noiseIndex)->not->toBeFalse()
        ->and($accessIndex)->toBeLessThan($noiseIndex);

    // Sta al op de stap ná bereikbaarheid; catch-up-markering alsof foto-invalidate
    // outdoor_accessibility liet verdwijnen terwijl de klant al verder was.
    $component
        ->set('stepIndex', (int) $noiseIndex)
        ->set('activeStepKey', $steps[(int) $noiseIndex]['key'])
        ->set('catchUpQuestionKeys', ['outdoor_accessibility' => true])
        ->set('knownStepKeys', array_column($steps, 'key'))
        ->set('form.noise_sensitive.bool', true)
        ->call('next')
        ->assertHasNoErrors();

    $afterSteps = $component->viewData('steps');
    $afterIndex = (int) $component->get('stepIndex');
    $afterStep = $afterSteps[$afterIndex] ?? null;
    $accessIndexAfter = collect($afterSteps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'outdoor_accessibility',
    );

    expect($afterStep)->not->toBeNull()
        ->and($afterStep['question_key'] ?? null)->toBe('outdoor_accessibility')
        ->and($accessIndexAfter)->not->toBeFalse()
        ->and($afterIndex)->toBe((int) $accessIndexAfter)
        // Vraagkop-index en steplijst-positie zijn gelijk (teller ↔ kop).
        ->and($afterIndex)->toBeLessThan((int) $noiseIndex);

    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    expect(collect($check['missing'])->pluck('question_key'))->toContain('outdoor_accessibility');
});

test('N30: tweede derive naar AI-unknown terwijl cursor op noise_sensitive → Volgende toont bereikbaarheid', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $intake = makeN30Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'noise_sensitive', null, ['bool' => true]);

    FakeAiClient::alwaysReturn(n30OutdoorHigh());
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('buiten-high.jpg', 1200, 900),
    );
    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    $accessAnswer = $intake->fresh()->answers()->where('question_key', 'outdoor_accessibility')->first();
    expect($accessAnswer)->not->toBeNull()
        ->and($accessAnswer?->value)->not->toBe(['value' => 'unknown']);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    expect(array_column(app(IntakeStepBuilder::class)->build($intake->fresh(), $version), 'question_key'))
        ->not->toContain('outdoor_accessibility');

    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_location', null, ['value' => 'facade']);
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_mount_type', null, ['value' => 'wall']);
    $outdoorUpload = $intake->fresh()->uploads()->where('question_key', 'outdoor_location_photos')->first();
    expect($outdoorUpload)->not->toBeNull();
    markTestUploadSatisfied($outdoorUpload);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    $versionFresh = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    // Prefill-skip staat nog; bouw stappen alsof bereikbaarheid stil is overgeslagen, vul alles t/m noise.
    for ($pass = 0; $pass < 3; $pass++) {
        $builderSteps = app(IntakeStepBuilder::class)->build($intake->fresh(), $versionFresh);
        $noiseIndexBuild = collect($builderSteps)->search(
            static fn (array $step): bool => ($step['question_key'] ?? null) === 'noise_sensitive',
        );
        expect($noiseIndexBuild)->not->toBeFalse();
        foreach ($builderSteps as $index => $step) {
            if ($index > (int) $noiseIndexBuild) {
                break;
            }
            if (($step['question_key'] ?? null) === 'noise_sensitive') {
                continue;
            }
            n30SatisfyStep($intake, $step);
        }
    }

    // Deel A: wizard met skip-staat in cache → overwrite naar AI-unknown → catch-up markeren.
    $marker = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $stepsSkipped = $marker->viewData('steps');
    expect(array_column($stepsSkipped, 'question_key'))->not->toContain('outdoor_accessibility');

    $noiseIndexSkipped = collect($stepsSkipped)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'noise_sensitive',
    );
    expect($noiseIndexSkipped)->not->toBeFalse();
    $marker
        ->set('stepIndex', (int) $noiseIndexSkipped)
        ->set('activeStepKey', $stepsSkipped[(int) $noiseIndexSkipped]['key']);

    app(SaveIntakeAnswer::class)->handle(
        $intake->fresh(),
        'outdoor_accessibility',
        null,
        ['value' => 'unknown'],
        PrefillSources::AI_PHOTO,
    );
    expect($intake->fresh()->answers()->where('question_key', 'outdoor_accessibility')->first()?->value)
        ->toBe(['value' => 'unknown']);

    $apply = new ReflectionMethod(IntakeWizard::class, 'applyPhotoDerivationResults');
    $apply->setAccessible(true);
    $apply->invoke($marker->instance(), 'outdoor_location_photos', null);
    expect($marker->instance()->catchUpQuestionKeys)->toHaveKey('outdoor_accessibility');

    // Deel B: verse mount (DB heeft al unknown) — Volgende vanaf noise toont bereikbaarheid vóór ownership.
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $accessIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'outdoor_accessibility',
    );
    $noiseIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'noise_sensitive',
    );
    $ownershipIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'ownership',
    );
    expect($accessIndex)->not->toBeFalse()
        ->and($noiseIndex)->not->toBeFalse()
        ->and($ownershipIndex)->not->toBeFalse()
        ->and($accessIndex)->toBeLessThan($noiseIndex)
        ->and($noiseIndex)->toBeLessThan($ownershipIndex);

    // Geen form.bool-set: die triggert maybeAutoAdvance → tweede next() die AI-unknown
    // als klantantwoord opslaat en doorloopt naar de volgende stap. Noise staat al in DB.
    $component
        ->set('stepIndex', (int) $noiseIndex)
        ->set('activeStepKey', $steps[(int) $noiseIndex]['key'])
        ->set('catchUpQuestionKeys', ['outdoor_accessibility' => true])
        ->set('knownStepKeys', array_column($steps, 'key'))
        ->call('next')
        ->assertHasNoErrors();

    $afterSteps = $component->viewData('steps');
    $afterIndex = (int) $component->get('stepIndex');
    $afterStep = $afterSteps[$afterIndex] ?? null;
    $accessIndexAfter = collect($afterSteps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'outdoor_accessibility',
    );

    expect($afterStep)->not->toBeNull()
        ->and($afterStep['question_key'] ?? null)->toBe('outdoor_accessibility')
        ->and($accessIndexAfter)->not->toBeFalse()
        ->and($afterIndex)->toBe((int) $accessIndexAfter)
        ->and($afterIndex)->toBeLessThan((int) $ownershipIndex)
        ->and($component->viewData('stepDisplayNumber'))->toBe($afterIndex + 1);
});

test('N30: AI-unknown outdoor_accessibility laat de stap niet vervallen', function () {
    $intake = makeN30Intake();

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'outdoor_accessibility',
        null,
        ['value' => 'unknown'],
        PrefillSources::AI_PHOTO,
    );

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->toContain('outdoor_accessibility');

    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    expect(collect($check['missing'])->pluck('question_key'))->toContain('outdoor_accessibility');
});

test('N30: hoge zekerheid skip’t bereikbaarheid; latere invalidate toont hem weer', function () {
    $intake = makeN30Intake();

    FakeAiClient::alwaysReturn(n30OutdoorHigh());
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('volgorde-high.jpg', 1100, 800),
    );
    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $keysHigh = array_column(app(IntakeStepBuilder::class)->build($intake->fresh(), $version), 'question_key');
    expect($intake->fresh()->answers()->where('question_key', 'outdoor_accessibility')->exists())->toBeTrue()
        ->and($keysHigh)->not->toContain('outdoor_accessibility');

    app(DerivePhotoAnswers::class)->invalidateDerivedState(
        $intake->fresh(),
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    $keysLow = array_column(app(IntakeStepBuilder::class)->build($intake->fresh(), $version), 'question_key');
    expect($keysLow)->toContain('outdoor_accessibility');
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    expect(collect($check['missing'])->pluck('question_key'))->toContain('outdoor_accessibility');
});

test('N30: lage zekerheid eerst, daarna hoge — bereikbaarheid mag alsnog vervallen', function () {
    $intake = makeN30Intake();
    Queue::fake([AssessUploadedPhotoJob::class]);

    FakeAiClient::alwaysReturn(n30OutdoorLowUnknown());
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('volgorde-low.jpg', 1100, 800),
    );
    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    expect(array_column(app(IntakeStepBuilder::class)->build($intake->fresh(), $version), 'question_key'))
        ->toContain('outdoor_accessibility');

    // Separated intake for high-confidence path (avoids terminal-assessment short-circuit).
    $intakeHigh = makeN30Intake();
    FakeAiClient::alwaysReturn(n30OutdoorHigh());
    app(StoreIntakeUpload::class)->handle(
        $intakeHigh,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('volgorde-high-2.jpg', 1100, 800),
    );
    app(DerivePhotoAnswers::class)->handle(
        $intakeHigh,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($intakeHigh->fresh()->answers()->where('question_key', 'outdoor_accessibility')->exists())->toBeTrue()
        ->and(array_column(app(IntakeStepBuilder::class)->build($intakeHigh->fresh(), $version), 'question_key'))
        ->not->toContain('outdoor_accessibility');
});

test('E2E-regressie: live floor_level-keuze blijft gezet na updated/auto-advance', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $intake = makeN30Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    for ($pass = 0; $pass < 3; $pass++) {
        $builderSteps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
        $floorIndex = collect($builderSteps)->search(
            static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
                && ($step['section_instance_key'] ?? null) === 'room-1',
        );
        if ($floorIndex === false) {
            break;
        }
        foreach ($builderSteps as $index => $step) {
            if ($index >= (int) $floorIndex) {
                break;
            }
            n30SatisfyStep($intake, $step);
        }
    }

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $floorIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($floorIndex)->not->toBeFalse();

    $floorComposite = 'room-1__floor_level';
    $component
        ->set('stepIndex', (int) $floorIndex)
        ->set('activeStepKey', $steps[(int) $floorIndex]['key'])
        ->set('knownStepKeys', array_column($steps, 'key'))
        // Zoals wire:model.live: updated → persist → maybeAutoAdvance → next.
        ->set('form.'.$floorComposite.'.value', 'basement');

    $dbValue = $intake->fresh()->answers()
        ->where('question_key', 'floor_level')
        ->where('section_instance_key', 'room-1')
        ->value('value');

    expect($dbValue)->toBe(['value' => 'basement']);

    $formValue = $component->get('form.'.$floorComposite);
    $onFloor = ($component->viewData('steps')[$component->get('stepIndex')]['question_key'] ?? null) === 'floor_level';

    // Of we zijn doorgeschoven (keuze in DB), of we staan nog op floor_level mét keuze in form.
    expect($dbValue)->toBe(['value' => 'basement']);
    if ($onFloor) {
        expect($formValue)->toBe(['value' => 'basement']);
    }
});

test('N30/E2E: dirty floor_level overleeft hydrateFormFromAnswers tijdens catch-up next', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $intake = makeN30Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    for ($pass = 0; $pass < 3; $pass++) {
        $builderSteps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
        $floorIndex = collect($builderSteps)->search(
            static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
                && ($step['section_instance_key'] ?? null) === 'room-1',
        );
        if ($floorIndex === false) {
            break;
        }
        foreach ($builderSteps as $index => $step) {
            if ($index >= (int) $floorIndex) {
                break;
            }
            n30SatisfyStep($intake, $step);
        }
    }

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $floorIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($floorIndex)->not->toBeFalse();

    $floorComposite = 'room-1__floor_level';
    // Simuleer: keuze net live gezet, DB-rij nog niet zichtbaar in een stale hydrate.
    $component
        ->set('stepIndex', (int) $floorIndex)
        ->set('activeStepKey', $steps[(int) $floorIndex]['key'])
        ->set('knownStepKeys', array_column($steps, 'key'))
        ->set('form.'.$floorComposite, ['value' => 'basement'])
        ->set('dirtyFormComposites', [$floorComposite => true])
        // Catch-up markering forceert het hydrate-pad in next() (N30).
        ->set('catchUpQuestionKeys', ['outdoor_accessibility' => true]);

    $hydrate = new ReflectionMethod(IntakeWizard::class, 'hydrateFormFromAnswers');
    $hydrate->setAccessible(true);
    $hydrate->invoke($component->instance());

    expect($component->instance()->form[$floorComposite] ?? null)->toBe(['value' => 'basement']);

    // Zonder dirty: refresh mag DB volgen (leeg).
    $component->set('dirtyFormComposites', []);
    $refresh = new ReflectionMethod(IntakeWizard::class, 'refreshAnswerInForm');
    $refresh->setAccessible(true);
    $refresh->invoke($component->instance(), $floorComposite);
    expect($component->instance()->form[$floorComposite] ?? null)->toBe([]);

    // Met dirty: refresh laat klantkeuze staan.
    $component
        ->set('form.'.$floorComposite, ['value' => 'basement'])
        ->set('dirtyFormComposites', [$floorComposite => true]);
    $refresh->invoke($component->instance(), $floorComposite);
    expect($component->instance()->form[$floorComposite] ?? null)->toBe(['value' => 'basement']);
});

test('N3: keuze + directe upload + beoordeling bewaart drain_location', function () {
    $intake = makeN30Intake();
    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $drainKey = (string) collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'drain_nearby')['key'];

    Queue::fake([AssessUploadedPhotoJob::class]);
    FakeAiClient::alwaysReturn([
        'drain_location' => 'indoor_nearby',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Synthetische afvoerplek.',
        'retake_instruction' => null,
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey)
        ->set('form.drain_location.value', 'outside_nearby');

    // Simuleer race: form heeft keuze, maar doe alsof DB-row nog ontbrak tot upload-refresh.
    // updated() heeft in Livewire-test al gepersisteerd; wis DB en houd dirty form.
    IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'drain_location')
        ->delete();
    $component->set('form.drain_location', ['value' => 'outside_nearby'])
        ->set('dirtyFormComposites', ['drain_location' => true]);

    $component->set('photoFiles.drain_photo', UploadedFile::fake()->image('achtergevel.jpg', 1200, 900));

    foreach (Queue::pushed(AssessUploadedPhotoJob::class) as $job) {
        if ($job instanceof AssessUploadedPhotoJob) {
            runAssessUploadedPhotoJob($job->uploadId);
        }
    }
    $component->call('pollPendingAssessments');

    $answer = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'drain_location')
        ->first();

    expect($answer)->not->toBeNull()
        ->and($answer?->value)->toBe(['value' => 'outside_nearby'])
        ->and($answer?->prefill_source)->toBeNull()
        ->and($component->get('form.drain_location'))->toBe(['value' => 'outside_nearby']);
});

test('N3: AI-prefill wissen schrijft geen klantantwoord terug bij leeg dirty-form', function () {
    $intake = makeN30Intake();
    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $drainKey = (string) collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'drain_nearby')['key'];

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'drain_location',
        null,
        ['value' => 'indoor_nearby'],
        PrefillSources::AI_PHOTO,
    );

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey);

    expect($component->get('form.drain_location'))->toBe(['value' => 'indoor_nearby']);

    // Invalidate zoals bij weggehaalde foto: DB leeg, form nog AI-waarde, géén dirty.
    IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'drain_location')
        ->delete();

    // Zonder dirty-markering mag een refresh de AI-waarde wissen (niet als klantantwoord houden).
    $component
        ->set('form.drain_location', ['value' => 'indoor_nearby'])
        ->set('dirtyFormComposites', []);

    $reflected = new ReflectionClass($component->instance());
    $method = $reflected->getMethod('refreshAnswerInForm');
    $method->setAccessible(true);
    $method->invoke($component->instance(), 'drain_location');

    // Sync Livewire-state met de gewiste form-waarde.
    $component->set('form.drain_location', [])
        ->set('dirtyFormComposites', []);

    expect($component->get('form.drain_location'))->toBe([]);

    $component->call('next')->assertHasNoErrors();
    expect(
        IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', 'drain_location')
            ->exists(),
    )->toBeFalse();
});

test('#176: bekende trusted m² verbergt L×B-stap', function () {
    $intake = makeN30Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_area_m2',
        'room-1',
        ['number' => 30],
        PrefillSources::REQUEST_TEXT,
        FactProvenance::Stated,
        90,
    );

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);

    expect(collect($steps)->firstWhere('group_key', 'room_dimensions'))->toBeNull()
        ->and(array_column($steps, 'question_key'))->not->toContain('room_length_m')
        ->and(array_column($steps, 'question_key'))->not->toContain('room_width_m');
});

test('#176: lage-confidence m² houdt L×B-stap zichtbaar', function () {
    $intake = makeN30Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_area_m2',
        'room-1',
        ['number' => 30],
        PrefillSources::AI_TEXT,
        FactProvenance::Inferred,
        40,
    );

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);

    expect(collect($steps)->firstWhere('group_key', 'room_dimensions'))->not->toBeNull();
});

test('N16: drain_photo gebruikt drain-profiel; mismatchtekst noemt geen buitenunitplek', function () {
    $intake = makeN30Intake();

    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();
    $profileName = null;
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if ($question->key === 'drain_photo') {
                $profileName = $question->meta['photo_analysis'] ?? null;
            }
        }
    }
    expect($profileName)->toBe('drain')
        ->and(PhotoDerivationProfile::require('drain')->promptName)->toBe('drain_assessment');

    expect(PhotoSubject::expectedForPhotoQuestion('drain_photo', 'drain'))->toBe(PhotoSubject::OutdoorLocation);

    $message = PhotoSubject::OutdoorLocation->mismatchMessage(
        PhotoSubject::Other,
        'drain_photo',
    );
    expect($message)->toContain('afvoer')
        ->and($message)->not->toContain('buitenunit geplaatst')
        ->and($message)->not->toContain('waar u de');

    $path = base_path('tests/Eval/fixtures/photo_observations/obs-n16-drain-achtergevel-regenpijp.json');
    expect(is_file($path))->toBeTrue();
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded['slot'])->toBe('drain_photo')
        ->and($decoded['expected']['retake_mentions_outdoor_unit'])->toBeFalse()
        ->and($decoded['expected']['profile'])->toBe('drain')
        ->and($decoded['model_fields']['retake_instruction'])->toBeNull();
});

test('N16: FakeAi drain-assessment op achtergevel geeft geen buitenunit-retake in customerMessage', function () {
    $intake = makeN30Intake();

    FakeAiClient::alwaysReturn([
        'drain_location' => 'outside_nearby',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Achtergevel met regenpijp zichtbaar.',
        'retake_instruction' => null,
    ]);

    Queue::fake([AssessUploadedPhotoJob::class]);
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->image('regenpijp-gevel.jpg', 1200, 900),
    );
    runAssessUploadedPhotoJob($upload->id);

    $assessment = $upload->fresh()?->contentAssessment();
    expect($assessment)->not->toBeNull()
        ->and($assessment?->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($assessment?->customerMessage())->toBeNull();
});
