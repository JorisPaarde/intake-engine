<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
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
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.tracing.enabled' => true,
        'filesystems.media' => 'local',
    ]);
    Storage::fake('local');
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeBl119GuardIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_email' => 'bl119-guard@example.com',
        'access_token' => 'bl119guard'.str_repeat('a', 55),
    ]);
}

function bl119GuardFixture(): string
{
    return base_path('tests/fixtures/klanttest-20261002/woonkamer-funda-1440.jpg');
}

test('wizard dispatches ai-upload-stored after composite store (network_upload_ms path)', function () {
    $intake = makeBl119GuardIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.room-1__room_photos', UploadedFile::fake()->image('woonkamer.jpg', 1200, 900))
        ->assertDispatched('ai-upload-stored');

    expect(IntakeUpload::query()->where('intake_id', $intake->id)->exists())->toBeTrue();
});

test('photo validateOutput normalizes Dutch confidence synonym and records normalize step', function () {
    $intake = makeBl119GuardIntake();
    $fixture = bl119GuardFixture();

    Queue::fake([AssessUploadedPhotoJob::class]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        new UploadedFile($fixture, 'woonkamer.jpg', 'image/jpeg', null, true),
    );

    if (! Storage::disk($upload->disk)->exists((string) $upload->analysis_path)) {
        Storage::disk($upload->disk)->put((string) $upload->analysis_path, file_get_contents($fixture) ?: 'x');
    }

    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'high',
        'glass_amount' => 'much',
        'room_outlet_status' => 'present',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'hoog',
        'evidence' => 'Woonkamer zichtbaar met ramen en stopcontact.',
        'retake_instruction' => null,
    ]);

    $run = app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->output['confidence'] ?? null)->toBe('high');

    $trace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoDerive)
        ->where('status', AiTraceStatus::Succeeded)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->steps->pluck('step_key')->all())->toContain('normalize')
        ->and($trace->normalizations)->not->toBeEmpty();
});

test('changed photo during analysis does not write derived answers', function () {
    $intake = makeBl119GuardIntake();
    $fixture = bl119GuardFixture();

    Queue::fake([AssessUploadedPhotoJob::class]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        new UploadedFile($fixture, 'woonkamer.jpg', 'image/jpeg', null, true),
    );

    if (! Storage::disk($upload->disk)->exists((string) $upload->analysis_path)) {
        Storage::disk($upload->disk)->put((string) $upload->analysis_path, file_get_contents($fixture) ?: 'x');
    }

    FakeAiClient::respondUsing(function () use ($upload) {
        // Mutatie ná analyse-manifest, vóór apply-check.
        $upload->forceFill([
            'analysis_checksum' => hash('sha256', 'mutated-during-analysis'),
        ])->save();

        return [
            'room_type' => 'living_room',
            'room_size_indication' => 'medium',
            'sun_exposure' => 'high',
            'glass_amount' => 'much',
            'room_outlet_status' => 'present',
            'detected_subject' => 'room',
            'subject_match' => 'yes',
            'confidence' => 'high',
            'evidence' => 'Woonkamer zichtbaar met ramen en stopcontact.',
            'retake_instruction' => null,
        ];
    });

    $run = app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AiRunStatus::Failed)
        ->and($run->error_message)->toContain('Foto’s gewijzigd tijdens AI-analyse');

    expect(
        IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', 'room_type')
            ->where('section_instance_key', 'room-1')
            ->exists(),
    )->toBeFalse();
});

test('successful photo derive trace contains apply step and activity ai_trace_id', function () {
    $intake = makeBl119GuardIntake();
    $fixture = bl119GuardFixture();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        new UploadedFile($fixture, 'woonkamer.jpg', 'image/jpeg', null, true),
    );

    if (! Storage::disk($upload->disk)->exists((string) $upload->analysis_path)) {
        Storage::disk($upload->disk)->put((string) $upload->analysis_path, file_get_contents($fixture) ?: 'x');
    }

    FakeAiClient::reset();

    $run = app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

    $trace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoDerive)
        ->where('status', AiTraceStatus::Succeeded)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->steps->pluck('step_key')->all())->toContain('normalize')
        ->and($trace->steps->pluck('step_key')->all())->toContain('invalidate_previous')
        ->and($trace->steps->pluck('step_key')->all())->toContain('apply')
        ->and($trace->field_outcomes)->not->toBeEmpty();

    $activity = IntakeActivityEvent::query()
        ->where('intake_id', $intake->id)
        ->where('event', 'photo_answers_derived')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['ai_trace_id'] ?? null)->toBe($trace->trace_id)
        ->and($activity->properties['ai_run_id'] ?? null)->toBe($run->id);
});
