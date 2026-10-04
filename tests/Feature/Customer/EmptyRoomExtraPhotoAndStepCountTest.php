<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\TechnicalDecisionKeys;
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

/**
 * Reference demo case: vrijstaande woning, geen bestaande units, 1 lege woonkamer, koelen+verwarmen.
 */
function bl137ReferenceIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    expect($version->version)->toBeGreaterThanOrEqual(24);

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'access_token' => str_repeat('c', 64),
        'token_expires_at' => now()->addDays(14),
        'customer_name' => 'BL-137 Referentie',
        'customer_email' => 'bl137@example.com',
    ]);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::REQUEST_TEXT);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'both'], PrefillSources::REQUEST_TEXT);
    $save->handle($intake, 'building_type', null, ['value' => 'detached'], PrefillSources::REQUEST_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::REQUEST_TEXT);
    $save->handle($intake, 'room_name', 'room-1', ['text' => 'Woonkamer 1'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'ownership', null, ['value' => 'owner'], PrefillSources::REQUEST_TEXT);

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    return $intake->fresh() ?? $intake;
}

/**
 * @return list<string>
 */
function bl137StepKeys(Intake $intake): array
{
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    $steps = app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version);

    return array_values(array_map(
        static fn (array $step): string => (string) ($step['question_key'] ?? $step['kind'] ?? ''),
        $steps,
    ));
}

test('lege woonkamer met bruikbare overzichtsfoto krijgt geen extra wand/deur/stopcontact-vraag', function () {
    $intake = bl137ReferenceIntake();
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
        'glazing_type' => 'unknown',
        'room_outlet_status' => 'unknown',
        'extra_overview_needed' => 'complete',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Lege woonkamer, bruikbaar overzicht vanuit deuropening.',
        'retake_instruction' => null,
    ]);

    $fixture = base_path('tests/fixtures/klanttest-20261002/woonkamer-1440.jpg');
    expect(is_file($fixture))->toBeTrue();

    Queue::fake([AssessUploadedPhotoJob::class]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set(
        'photoFiles.room-1__room_photos',
        UploadedFile::fake()->createWithContent('woonkamer-1440.jpg', (string) file_get_contents($fixture)),
    );

    expect($component->get('uploadPhase'))->toBe('assessing');

    $pushed = [];
    Queue::assertPushed(AssessUploadedPhotoJob::class, function (AssessUploadedPhotoJob $job) use (&$pushed): bool {
        $pushed[] = $job->uploadId;

        return true;
    });

    foreach (array_values(array_unique($pushed)) as $uploadId) {
        runAssessUploadedPhotoJob($uploadId);
    }

    $component->call('pollPendingAssessments');

    $keys = array_column($component->viewData('steps'), 'question_key');

    expect($keys)->not->toContain('indoor_unit_position_photo')
        ->and($keys)->not->toContain('wall_outlet_photo')
        ->and($keys)->not->toContain('room_extra_overview_needed')
        ->and($keys)->not->toContain('room_outlet_status')
        ->and($intake->fresh()->answers()->where('question_key', 'room_extra_overview_needed')->value('value'))
        ->toBe(['value' => 'complete']);
});

test('extra overzichtsfoto verschijnt alleen bij expliciete assessment needs_photo', function () {
    $intake = bl137ReferenceIntake();
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_extra_overview_needed',
        'room-1',
        ['value' => 'needs_photo'],
        PrefillSources::AI_PHOTO,
    );

    $keys = bl137StepKeys($intake->fresh() ?? $intake);

    expect($keys)->toContain('indoor_unit_position_photo')
        ->and($keys)->not->toContain('wall_outlet_photo');
});

test('reference case wizard keys: geen route-foto, geen outdoor_mount, geen altijd-extra-overzicht', function () {
    $intake = bl137ReferenceIntake();

    // Na bruikbare ruimtefoto (zoals staging-retest): size/glas/zon/outlet/overzicht uit assessment.
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'room_size_indication', 'room-1', ['value' => 'large'], PrefillSources::AI_PHOTO);
    $save->handle($intake, 'sun_exposure', 'room-1', ['value' => 'medium'], PrefillSources::AI_PHOTO);
    $save->handle($intake, 'glass_amount', 'room-1', ['value' => 'average'], PrefillSources::AI_PHOTO);
    $save->handle($intake, 'room_outlet_status', 'room-1', ['value' => 'present'], PrefillSources::AI_PHOTO);
    $save->handle($intake, 'room_extra_overview_needed', 'room-1', ['value' => 'complete'], PrefillSources::AI_PHOTO);

    $keys = bl137StepKeys($intake->fresh() ?? $intake);

    expect($keys)->not->toContain('indoor_unit_position_photo')
        ->and($keys)->not->toContain('wall_outlet_photo')
        ->and($keys)->not->toContain('pipe_route_photos')
        ->and($keys)->not->toContain('outdoor_mount_type')
        ->and(TechnicalDecisionKeys::contains('outdoor_mount_type'))->toBeFalse();

    // Stabiele referentie-lijst (after-fix voor deze scenario).
    expect($keys)->toBe([
        'request_reason',
        '_known_summary',
        'room_photos',
        'preferred_indoor_location',
        'room_length_m',
        'room_area_m2',
        'ceiling_height_m',
        'glazing_type',
        'floor_level',
        'outdoor_location_photos',
        'around_house_photos',
        'outdoor_location',
        'outdoor_accessibility',
        'noise_sensitive',
        'build_year',
        'insulation_indication',
        'floor_insulation',
        'crawl_space_present',
        'pipe_visibility',
        'fusebox_photo',
        'drain_location',
        'drain_photo',
        '_closing_wishes',
        'truth_confirmation',
        'privacy_consent',
    ]);
});
