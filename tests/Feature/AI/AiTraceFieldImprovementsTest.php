<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceRedactor;
use App\Domains\AI\Services\AiTraceRequestIdResolver;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.tracing.enabled' => true,
        'ai.budget.daily_cents' => 1000,
        'ai.budget.monthly_cents' => 10000,
        'ai.budget.reserve_cents_per_call' => 1,
        'ai.budget.input_cents_per_1k_tokens' => 1,
        'ai.budget.output_cents_per_1k_tokens' => 2,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeTraceFieldsIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Trace Fields',
        'customer_email' => 'fields@example.com',
        'address_line' => 'Veldenstraat 7',
        'is_demo' => false,
    ]);
}

test('openai client stores provider response id, generation settings and fine cost', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.model' => 'openrouter/test',
        'ai.max_tokens' => 512,
        'ai.temperature' => 0.2,
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'gen-openrouter-abc123xyz',
            'model' => 'openrouter/test',
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 40,
                'total_tokens' => 140,
                'cost' => 0.000123456789,
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

    expect($result->providerResponseId)->toBe('gen-openrouter-abc123xyz')
        ->and($result->estimatedCost)->toBe('0.000123456789')
        ->and($result->estimatedCostCents)->toBe(1)
        ->and($result->modelParameters['temperature'])->toBe(0.0)
        ->and($result->modelParameters['max_tokens'])->toBe(512)
        ->and($result->modelParameters['schema'])->toBe('summary-v1')
        ->and($result->modelParameters['response_format_type'])->toBe('json_object');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return ($data['temperature'] ?? null) === 0.0
            && ($data['max_tokens'] ?? null) === 512
            && ($data['response_format']['type'] ?? null) === 'json_object';
    });
});

test('trace recorder fills request_id correlation_id and provider_response_id from fake client', function () {
    $intake = makeTraceFieldsIntake();
    $correlation = (string) Str::uuid();

    $handle = app(AiTraceRecorder::class)->start($intake, AiTraceCallType::Summary, [
        'correlation_id' => $correlation,
        'request_id' => 'http-req-demo-1',
    ]);

    config(['ai.provider' => 'fake']);
    $result = app(FakeAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Vat samen.',
        input: ['answers' => []],
        promptVersion: 'summary-v1',
    ));
    $handle->recordProviderResult($result);
    $trace = $handle->succeed();

    expect($trace->correlation_id)->toBe($correlation)
        ->and($trace->provider_response_id)->toStartWith('fake-')
        ->and($trace->request_id)->toBe($trace->provider_response_id)
        ->and($trace->estimated_cost)->not->toBeNull()
        ->and($trace->estimated_cost_cents)->toBeGreaterThan(0)
        ->and($trace->model_parameters['schema'] ?? null)->toBe('summary-v1')
        ->and($trace->model_parameters)->toHaveKey('temperature')
        ->and($trace->model_parameters)->toHaveKey('response_format');
});

test('queued photo job records queue_wait_ms and attempt on traces', function () {
    $intake = makeTraceFieldsIntake();
    $resolver = app(AiTraceRequestIdResolver::class);
    $job = new AssessUploadedPhotoJob(uploadId: 999001, correlationId: 'corr-queue-1', dispatchedAt: microtime(true) - 0.25);
    $resolver->rememberQueueMetrics(
        (int) max(0, round((microtime(true) - $job->dispatchedAt) * 1000)),
        2,
    );
    $resolver->rememberCorrelationId('corr-queue-1');

    $handle = app(AiTraceRecorder::class)->start($intake, AiTraceCallType::PhotoAssess, [
        'correlation_id' => 'corr-queue-1',
    ]);
    $trace = $handle->succeed();

    expect($trace->correlation_id)->toBe('corr-queue-1')
        ->and($trace->queue_wait_ms)->toBeGreaterThanOrEqual(200)
        ->and($trace->attempt)->toBe(2)
        ->and($trace->retry_count)->toBe(1)
        ->and($trace->request_id)->toBeString()->not->toBeEmpty();
});

test('AiTraceRedactor redacts GPS and EXIF location keys and coordinate pairs', function () {
    $redactor = app(AiTraceRedactor::class);

    $redacted = $redactor->redact([
        'exif' => [
            'GPSLatitude' => 52.370216,
            'GPSLongitude' => 4.895168,
            'Make' => 'Apple',
        ],
        'latitude' => 52.370216,
        'longitude' => 4.895168,
        'note' => 'Positie 52.370216, 4.895168 bij de gevel.',
    ]);

    $json = (string) json_encode($redacted, JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('52.370216')
        ->and($json)->not->toContain('4.895168')
        ->and($json)->toContain('[locatie verwijderd]')
        ->and($redacted['exif']['Make'])->toBe('Apple');
});

test('AiTraceRedactor does not treat huisnummer m2 or ids as phone secrets', function () {
    $redactor = app(AiTraceRedactor::class)->withKnownPii([
        'customer_phone' => '0612345678',
        'address_line' => 'Dorpsstraat 12',
    ]);

    $payload = [
        'free_text' => 'Kamer is 20 m², huisnummer 12, intake_id 884421 aan Dorpsstraat 12. Bel 0612345678.',
        'house_number' => '12',
        'room_area_m2' => 20.5,
        'intake_id' => 884421,
        'upload_id' => 55,
        'real_phone' => 'Bel +31 6 12345678 voor vragen.',
    ];

    $redacted = $redactor->redact($payload);
    $json = (string) json_encode($redacted, JSON_UNESCAPED_UNICODE);

    expect($redacted['house_number'])->toBe('12')
        ->and($redacted['room_area_m2'])->toBe(20.5)
        ->and($redacted['intake_id'])->toBe(884421)
        ->and($redacted['upload_id'])->toBe(55)
        ->and($json)->toContain('20 m²')
        ->and($json)->toContain('huisnummer 12')
        ->and($json)->toContain('intake_id 884421')
        ->and($json)->not->toContain('0612345678')
        ->and($json)->not->toContain('+31 6 12345678')
        ->and($json)->toContain('[telefoon verwijderd]')
        ->and($json)->not->toContain('Dorpsstraat 12');
});

test('ensureRequiredFields keeps request_id and correlation_id non-empty without provider', function () {
    $intake = makeTraceFieldsIntake();
    $trace = app(AiTraceRecorder::class)->start($intake, AiTraceCallType::AttentionPoints)->succeed();

    expect($trace->status)->toBe(AiTraceStatus::Succeeded)
        ->and($trace->request_id)->toBeString()->not->toBeEmpty()
        ->and($trace->correlation_id)->toBeString()->not->toBeEmpty()
        ->and(AiTrace::query()->whereKey($trace->id)->value('request_id'))->not->toBeNull();
});
