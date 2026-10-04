<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceExporter;
use App\Domains\AI\Services\AiTraceRequestIdResolver;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.tracing.enabled' => true,
        'ai.budget.daily_cents' => 1000,
        'ai.budget.monthly_cents' => 10000,
        'ai.budget.reserve_cents_per_call' => 1,
        'ai.budget.input_cents_per_1k_tokens' => 1,
        'ai.budget.output_cents_per_1k_tokens' => 2,
        'ai.seed' => 42,
        'ai.max_tokens' => 256,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeLoggingGapsIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Logging Gaps',
        'customer_email' => 'gaps@example.com',
        'address_line' => 'Gatenstraat 9',
        'is_demo' => false,
    ], $overrides));
}

test('follow-up subject assessment creates an ai_run with provider_request_id', function () {
    $user = User::factory()->create();
    $intake = makeLoggingGapsIntake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
    ]);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item = $round->items()->firstOrFail();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'evidence' => 'Meterkast zichtbaar van voren.',
    ]);

    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        UploadedFile::fake()->image('follow-up-meterkast.jpg', 1200, 900),
    );

    app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $upload);

    $run = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('upload_id', $upload->id)
        ->where('type', AiRunType::PhotoAssessment)
        ->where('status', AiRunStatus::Succeeded)
        ->latest('id')
        ->first();

    $trace = AiTrace::query()
        ->where('upload_id', $upload->id)
        ->where('call_type', AiTraceCallType::FollowUpPhotoSubject)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run?->provider_request_id)->toBeString()->not->toBeEmpty()
        ->and($trace)->not->toBeNull()
        ->and($trace?->request_id)->toBeString()->not->toBeEmpty()
        ->and($trace?->provider_response_id)->toBe($run?->provider_request_id)
        ->and($trace?->finish_reason)->toBe('stop')
        ->and($trace?->model_parameters)->toHaveKey('temperature')
        ->and($trace?->model_parameters)->toHaveKey('max_tokens')
        ->and($trace?->model_parameters)->toHaveKey('seed');
});

test('upload without assessment profile creates skipped ai_run with reason', function () {
    $intake = makeLoggingGapsIntake();

    Queue::fake([AssessUploadedPhotoJob::class]);

    // wall_outlet heeft sinds v26 een profiel; forceer een key zonder photo_analysis.
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'wall_outlet_photo',
        'room-1',
        UploadedFile::fake()->image('zonder-profiel.jpg', 1000, 800),
    );
    $upload->forceFill([
        'question_key' => 'unprofiled_test_photo',
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'content_assessment' => null,
    ])->save();

    runAssessUploadedPhotoJob($upload->id);

    $run = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('upload_id', $upload->id)
        ->where('status', AiRunStatus::Skipped)
        ->latest('id')
        ->first();

    $trace = AiTrace::query()
        ->where('upload_id', $upload->id)
        ->where('status', AiTraceStatus::Skipped)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run?->error_message)->toBe('geen beoordelingsprofiel')
        ->and($trace)->not->toBeNull()
        ->and($trace?->error_message)->toBe('geen beoordelingsprofiel')
        ->and($trace?->correlation_id)->toBeString()->not->toBeEmpty()
        ->and($trace?->request_id)->toBeString()->not->toBeEmpty();
});

test('two uploads get different correlation_ids via photo job', function () {
    $intake = makeLoggingGapsIntake();
    $resolver = app(AiTraceRequestIdResolver::class);
    // Simulate ambient context pollution from a prior upload chain.
    $resolver->rememberCorrelationId((string) Str::uuid());

    $first = app(StoreIntakeUpload::class)->handle(
        $intake,
        'wall_outlet_photo',
        'room-1',
        UploadedFile::fake()->image('a.jpg', 900, 700),
    );
    $second = app(StoreIntakeUpload::class)->handle(
        $intake,
        'wall_outlet_photo',
        'room-1',
        UploadedFile::fake()->image('b.jpg', 950, 720),
    );

    runAssessUploadedPhotoJob($first->id);
    runAssessUploadedPhotoJob($second->id);

    $firstCorrelation = data_get($first->fresh()->processing_timings, 'correlation_id');
    $secondCorrelation = data_get($second->fresh()->processing_timings, 'correlation_id');

    $traces = AiTrace::query()
        ->whereIn('upload_id', [$first->id, $second->id])
        ->get()
        ->keyBy('upload_id');

    expect($firstCorrelation)->toBeString()->not->toBeEmpty()
        ->and($secondCorrelation)->toBeString()->not->toBeEmpty()
        ->and($firstCorrelation)->not->toBe($secondCorrelation)
        ->and($traces[$first->id]->correlation_id)->toBe($firstCorrelation)
        ->and($traces[$second->id]->correlation_id)->toBe($secondCorrelation)
        ->and($traces[$first->id]->correlation_id)->not->toBe($traces[$second->id]->correlation_id);
});

test('openai client model_parameters include seed and export carries finish_reason and queued_at', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.model' => 'openrouter/test',
        'ai.max_tokens' => 128,
        'ai.temperature' => 0.1,
        'ai.seed' => 99,
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'gen-gaps-seed-1',
            'model' => 'openrouter/test',
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
                'cost' => 0.000012345678,
            ],
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => ['a']])],
            ]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Vat samen.',
        input: ['x' => 1],
        promptVersion: 'summary-v1',
        temperature: 0.0,
    ));

    expect($result->modelParameters)->toHaveKey('seed')
        ->and($result->modelParameters['seed'])->toBe(99)
        ->and($result->modelParameters['temperature'])->toBe(0.0)
        ->and($result->modelParameters['max_tokens'])->toBe(128)
        ->and($result->estimatedCost)->toBe('0.000012345678');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return ($data['seed'] ?? null) === 99
            && ($data['temperature'] ?? null) === 0.0
            && ($data['max_tokens'] ?? null) === 128;
    });

    $intake = makeLoggingGapsIntake();
    $queuedAt = now()->subSeconds(3);
    $trace = AiTrace::query()->create([
        'trace_id' => (string) Str::uuid(),
        'correlation_id' => (string) Str::uuid(),
        'request_id' => 'gen-gaps-seed-1',
        'provider_response_id' => 'gen-gaps-seed-1',
        'intake_id' => $intake->id,
        'intake_ref_id' => $intake->id,
        'is_demo' => false,
        'call_type' => AiTraceCallType::Summary,
        'status' => AiTraceStatus::Succeeded,
        'provider' => 'openai',
        'model' => 'openrouter/test',
        'model_parameters' => $result->modelParameters,
        'prompt_version' => 'summary-v1',
        'finish_reason' => 'stop',
        'retry_count' => 1,
        'attempt' => 2,
        'queue_wait_ms' => 2500,
        'queued_at' => $queuedAt,
        'estimated_cost_cents' => 1,
        'estimated_cost' => '0.000012345678',
        'request_snapshot' => ['system' => 'x', 'user' => []],
        'photo_refs' => [],
        'raw_response' => '{}',
        'parsed_response' => [],
        'validation_errors' => [],
        'input_tokens' => 10,
        'output_tokens' => 5,
        'total_tokens' => 15,
        'provider_ms' => 12,
        'process_ms' => 3,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $payload = app(AiTraceExporter::class)->callPayload($trace);

    expect($payload)->toHaveKey('finish_reason')
        ->and($payload['finish_reason'])->toBe('stop')
        ->and($payload)->toHaveKey('queued_at')
        ->and($payload['queued_at'])->not->toBeNull()
        ->and($payload)->toHaveKey('retry_count')
        ->and($payload['retry_count'])->toBe(1)
        ->and($payload['estimated_cost'])->toBe('0.000012345678')
        ->and($payload['model_parameters']['seed'] ?? null)->toBe(99);
});

test('follow-up without subject profile creates skipped run with reason', function () {
    $user = User::factory()->create();
    $intake = makeLoggingGapsIntake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
    ]);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Extra foto van de gewenste plek.',
        'decision_area_key' => 'placement',
    ]]);
    $item = $round->items()->firstOrFail();

    // BL-143: variants sync via ProcessIntakePhotoVariantsJob; AI job explicit (same as StagingAiTraceFixesTest).
    Queue::fake([AssessUploadedPhotoJob::class]);

    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        // Shortest side must be >= 640px (PhotoUsabilityHeuristic::MIN_DIMENSION).
        UploadedFile::fake()->image('extra.jpg', 1280, 960),
    );

    runAssessUploadedPhotoJob($upload->id);

    $run = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('upload_id', $upload->id)
        ->where('status', AiRunStatus::Skipped)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull()
        ->and($run?->error_message)->toContain('geen beoordelingsprofiel');
});
