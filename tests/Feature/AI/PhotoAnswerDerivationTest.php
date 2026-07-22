<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\AiRunStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

function makeDerivationIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Afleiding Klant',
        'customer_email' => 'afleiding@example.com',
    ]);
}

/** @return array<string, mixed> */
function outdoorOutput(string $confidence = 'high', string $mountType = 'wall'): array
{
    return [
        'outdoor_location' => 'garden',
        'outdoor_mount_type' => $mountType,
        'outdoor_accessibility' => 'ladder',
        'confidence' => $confidence,
        'evidence' => 'De unit zou aan een gemetselde gevel op ruim twee meter hoogte komen.',
        'retake_instruction' => null,
    ];
}

function uploadOutdoorPhoto(Intake $intake): void
{
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('buitenunit.jpg', 1200, 900),
    );
}

test('a high confidence derivation removes the questions it answered from the wizard', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn(outdoorOutput());
    uploadOutdoorPhoto($intake);

    $run = app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

    $mountType = $intake->answers()->where('question_key', 'outdoor_mount_type')->firstOrFail();
    $accessibility = $intake->answers()->where('question_key', 'outdoor_accessibility')->firstOrFail();

    expect($mountType->value)->toBe(['value' => 'wall'])
        ->and($mountType->prefill_source)->toBe(DerivePhotoAnswers::SOURCE_DERIVED)
        ->and($accessibility->value)->toBe(['value' => 'ladder']);

    // Het bewijs blijft zichtbaar in het dossier — afgeleid is niet hetzelfde als verborgen.
    $fact = $intake->externalFacts()->where('fact_key', 'outdoor_location_photos_derivation')->firstOrFail();

    expect($fact->source)->toBe(DerivePhotoAnswers::SOURCE)
        ->and($fact->value['evidence'])->toContain('gemetselde gevel');

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->not->toContain('outdoor_mount_type')
        ->and($stepKeys)->not->toContain('outdoor_accessibility');
});

test('a medium confidence derivation keeps the question but pre-fills it as a voorzet', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn(outdoorOutput('medium'));
    uploadOutdoorPhoto($intake);

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    $mountType = $intake->answers()->where('question_key', 'outdoor_mount_type')->firstOrFail();

    expect($mountType->prefill_source)->toBe(DerivePhotoAnswers::SOURCE_SUGGESTED);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->toContain('outdoor_mount_type');
});

test('a low confidence derivation stores nothing and leaves every question standing', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn(outdoorOutput('low'));
    uploadOutdoorPhoto($intake);

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($intake->answers()->where('question_key', 'outdoor_mount_type')->exists())->toBeFalse();

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->toContain('outdoor_mount_type')
        ->and($stepKeys)->toContain('outdoor_accessibility');
});

test('an answer the applicant already gave is never overwritten by a derivation', function () {
    $intake = makeDerivationIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_mount_type', null, ['value' => 'ground']);

    FakeAiClient::alwaysReturn(outdoorOutput());
    uploadOutdoorPhoto($intake);

    app(DerivePhotoAnswers::class)->handle(
        $intake->fresh(),
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    $mountType = $intake->answers()->where('question_key', 'outdoor_mount_type')->firstOrFail();

    expect($mountType->value)->toBe(['value' => 'ground'])
        ->and($mountType->prefill_source)->toBeNull();
});

test('removing the photos discards the answers that were derived from them', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn(outdoorOutput());
    uploadOutdoorPhoto($intake);

    $profile = PhotoDerivationProfile::require('outdoor');
    app(DerivePhotoAnswers::class)->handle($intake, 'outdoor_location_photos', null, $profile);

    expect($intake->answers()->where('question_key', 'outdoor_mount_type')->exists())->toBeTrue();

    $intake->uploads()->delete();
    app(DerivePhotoAnswers::class)->handle($intake->fresh(), 'outdoor_location_photos', null, $profile);

    expect($intake->answers()->where('question_key', 'outdoor_mount_type')->exists())->toBeFalse()
        ->and($intake->externalFacts()->where('fact_key', 'outdoor_location_photos_derivation')->exists())->toBeFalse();
});

test('a value outside the template options is rejected instead of stored', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn(outdoorOutput('high', 'floating_platform'));
    uploadOutdoorPhoto($intake);

    $run = app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($run?->status)->toBe(AiRunStatus::Failed)
        ->and($intake->answers()->where('question_key', 'outdoor_mount_type')->exists())->toBeFalse();
});

test('derivation is skipped entirely when photo inference is disabled', function () {
    config(['ai.photo_inference.enabled' => false]);

    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn(outdoorOutput());
    uploadOutdoorPhoto($intake);

    $run = app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($run)->toBeNull()
        ->and($intake->answers()->where('question_key', 'outdoor_mount_type')->exists())->toBeFalse();
});

test('room photos derive per room instance without leaking into another room', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::reset();

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('woonkamer.jpg', 1200, 900),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );

    $roomOne = $intake->answers()
        ->where('question_key', 'sun_exposure')
        ->where('section_instance_key', 'room-1')
        ->firstOrFail();

    expect($roomOne->value)->toBe(['value' => 'high'])
        ->and($intake->answers()->where('question_key', 'sun_exposure')->where('section_instance_key', 'room-2')->exists())
        ->toBeFalse();
});

test('the pipe route profile derives a boolean question as a real boolean', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::reset();

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        UploadedFile::fake()->image('route.jpg', 1200, 900),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        PhotoDerivationProfile::require('pipe_route'),
    );

    $drillings = $intake->answers()->where('question_key', 'drillings_needed')->firstOrFail();
    $route = $intake->answers()->where('question_key', 'pipe_route_description')->firstOrFail();

    // 'yes' op de wire wordt een echte boolean in de opslag — niet de string 'yes'.
    expect($drillings->value)->toBe(['bool' => true])
        ->and($route->value)->toBe(['value' => 'along_facade']);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->not->toContain('drillings_needed')
        ->and($stepKeys)->not->toContain('pipe_route_description')
        // Een voorkeur staat niet op een foto en blijft dus staan.
        ->and($stepKeys)->toContain('pipe_visibility');
});

test('a boolean derivation of no is stored as false rather than dropped', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn([
        'pipe_route_description' => 'short_direct',
        'pipe_distance_indication' => 'short',
        'drillings_needed' => 'no',
        'confidence' => 'high',
        'evidence' => 'De binnen- en buitenunit delen dezelfde buitenmuur.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        UploadedFile::fake()->image('route.jpg', 1200, 900),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        PhotoDerivationProfile::require('pipe_route'),
    );

    expect($intake->answers()->where('question_key', 'drillings_needed')->firstOrFail()->value)
        ->toBe(['bool' => false]);
});

test('an unknown field is skipped while its siblings are still applied', function () {
    $intake = makeDerivationIntake();
    FakeAiClient::alwaysReturn([
        ...outdoorOutput(),
        'outdoor_accessibility' => 'unknown',
    ]);
    uploadOutdoorPhoto($intake);

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($intake->answers()->where('question_key', 'outdoor_mount_type')->exists())->toBeTrue()
        ->and($intake->answers()->where('question_key', 'outdoor_accessibility')->exists())->toBeFalse();

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->toContain('outdoor_accessibility')
        ->and($stepKeys)->not->toContain('outdoor_mount_type');
});
