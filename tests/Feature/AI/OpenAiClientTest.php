<?php

declare(strict_types=1);

use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiInputRedactor;
use App\Domains\AI\Services\DossierSynthesisJsonSchema;
use App\Domains\Intake\Models\Intake;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ai.budget.daily_cents' => 1000,
        'ai.budget.monthly_cents' => 10000,
        'ai.budget.reserve_cents_per_call' => 1,
        'ai.budget.input_cents_per_1k_tokens' => 1,
        'ai.budget.output_cents_per_1k_tokens' => 2,
    ]);
});

function aiRequest(): AiCompletionRequest
{
    return new AiCompletionRequest(
        prompt: 'Vat samen als JSON.',
        input: ['answers' => ['request_reason' => ['text' => 'Bel 06-12345678 of jan@example.com']]],
        promptVersion: 'summary-v1',
    );
}

test('redactor strips email and phone but keeps other text', function () {
    $out = app(AiInputRedactor::class)->redact([
        'answers' => [
            'note' => ['text' => 'Mail jan@example.com of bel 06 1234 5678, kamer is 20 m2'],
            'count' => ['number' => 3],
        ],
    ]);

    $text = $out['answers']['note']['text'];

    expect($text)->not->toContain('jan@example.com')
        ->and($text)->not->toContain('1234')
        ->and($text)->toContain('kamer is 20 m2')
        ->and($out['answers']['count']['number'])->toBe(3);
});

test('openai client parses JSON output on success', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key', 'ai.model' => 'gpt-test']);

    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'gpt-test',
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'Klaar', 'highlights' => ['a']])]]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->complete(aiRequest());

    expect($result->provider)->toBe('openai')
        ->and($result->output['summary'])->toBe('Klaar')
        ->and($result->inputTokens)->toBe(1000)
        ->and($result->outputTokens)->toBe(500)
        ->and($result->totalTokens)->toBe(1500)
        ->and($result->estimatedCostCents)->toBe(2);
});

test('openai client redacts PII in the outgoing payload', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key']);

    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => ['x']])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(aiRequest());

    Http::assertSent(function ($request) {
        $body = json_encode($request->data());

        return ! str_contains($body, 'jan@example.com') && ! str_contains($body, '12345678');
    });
});

test('openai client sends private image bytes as a data url only in the request', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key']);

    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'choices' => [['message' => ['content' => json_encode([
                'free_group' => 'yes',
                'phase' => 'three_phase',
                'confidence' => 'high',
                'evidence' => 'Vrije positie zichtbaar.',
                'retake_instruction' => null,
            ])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Beoordeel als JSON.',
        input: ['task' => 'fusebox'],
        promptVersion: 'fusebox-assessment-v1',
        images: [new AiImageInput('image/jpeg', 'private-image-bytes')],
    ));

    Http::assertSent(function ($request): bool {
        $content = $request->data()['messages'][1]['content'] ?? [];

        return ($content[0]['type'] ?? null) === 'text'
            && ($content[1]['type'] ?? null) === 'image_url'
            && ($content[1]['image_url']['detail'] ?? null) === 'high'
            && ($content[1]['image_url']['url'] ?? null) === 'data:image/jpeg;base64,'.base64_encode('private-image-bytes');
    });
});

test('openai client fails closed when budget caps are not configured', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.budget.daily_cents' => null,
        'ai.budget.monthly_cents' => null,
    ]);

    Http::fake();

    try {
        app(OpenAiClient::class)->complete(aiRequest());
    } finally {
        Http::assertNothingSent();
    }
})->throws(AiClientException::class, 'AI-budgetcap ontbreekt');

test('openai client blocks before sending when the daily cap is reached', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.budget.daily_cents' => 10,
        'ai.budget.monthly_cents' => 100,
        'ai.budget.reserve_cents_per_call' => 1,
    ]);

    $intake = Intake::factory()->create();
    AiRun::query()->create([
        'intake_id' => $intake->id,
        'type' => AiRunType::Summary,
        'provider' => 'openai',
        'model' => 'gpt-test',
        'prompt_version' => 'summary-v1',
        'input_hash' => str_repeat('a', 64),
        'status' => AiRunStatus::Succeeded,
        'estimated_cost_cents' => 10,
        'started_at' => now(),
    ]);

    Http::fake();

    try {
        app(OpenAiClient::class)->complete(aiRequest());
    } finally {
        Http::assertNothingSent();
    }
})->throws(AiClientException::class, 'AI-budgetlimiet bereikt voor vandaag');

test('openai client soft-fails on an error status', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key']);

    Http::fake(['*/chat/completions' => Http::response(['error' => 'boom'], 500)]);

    app(OpenAiClient::class)->complete(aiRequest());
})->throws(AiClientException::class);

test('openai client requires an api key', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => '']);

    app(OpenAiClient::class)->complete(aiRequest());
})->throws(AiClientException::class);

test('openai client posts to the configured OpenAI-compatible base URL', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.base_url' => 'https://openrouter.ai/api/v1',
        'ai.model' => 'google/gemini-2.5-flash-lite',
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'model' => 'google/gemini-2.5-flash-lite',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => ['x']])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(aiRequest());

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && ($request->data()['model'] ?? null) === 'google/gemini-2.5-flash-lite'
            && $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('openai client sends optional OpenRouter attribution headers', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.base_url' => 'https://openrouter.ai/api/v1',
        'ai.http_referer' => 'https://staging.intake-engine.nl',
        'ai.app_title' => 'Digitale Opname',
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => []])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(aiRequest());

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('HTTP-Referer', 'https://staging.intake-engine.nl')
            && $request->hasHeader('X-Title', 'Digitale Opname')
            && $request->hasHeader('X-OpenRouter-Title', 'Digitale Opname');
    });
});

test('openai client uses vision_model for image requests when set', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.model' => 'text-only-model',
        'ai.vision_model' => 'vision-model-id',
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'choices' => [['message' => ['content' => json_encode(['ok' => true])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Beoordeel als JSON.',
        input: ['task' => 'fusebox'],
        promptVersion: 'fusebox-assessment-v1',
        images: [new AiImageInput('image/jpeg', 'bytes')],
    ));

    Http::assertSent(fn ($request): bool => ($request->data()['model'] ?? null) === 'vision-model-id');
});

test('openai client falls back to AI_MODEL for images when vision_model is empty', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.model' => 'shared-multimodal',
        'ai.vision_model' => null,
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'choices' => [['message' => ['content' => json_encode(['ok' => true])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Beoordeel als JSON.',
        input: ['task' => 'fusebox'],
        promptVersion: 'fusebox-assessment-v1',
        images: [new AiImageInput('image/jpeg', 'bytes')],
    ));

    Http::assertSent(fn ($request): bool => ($request->data()['model'] ?? null) === 'shared-multimodal');
});

test('openai client sends json_schema response_format when a schema is provided', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key', 'ai.model' => 'gpt-test']);

    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'gpt-test',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => []])]]],
        ], 200),
    ]);

    $schema = [
        'name' => 'dossier_synthesis',
        'schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary'],
            'properties' => [
                'summary' => ['type' => 'string'],
            ],
        ],
    ];

    app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Vat samen als JSON.',
        input: ['x' => 1],
        promptVersion: 'dossier-synthesis-v5',
        responseSchema: $schema,
        timeoutSeconds: 45,
    ));

    Http::assertSent(function ($request): bool {
        $format = $request->data()['response_format'] ?? null;

        return is_array($format)
            && ($format['type'] ?? null) === 'json_schema'
            && ($format['json_schema']['strict'] ?? null) === true
            && ($format['json_schema']['name'] ?? null) === 'dossier_synthesis'
            && ($format['json_schema']['schema']['required'][0] ?? null) === 'summary';
    });
});

test('openai client honors request temperature override', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.temperature' => 0.2,
        'ai.classification_temperature' => 0,
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => []])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Classificeer.',
        input: ['x' => 1],
        promptVersion: 'fusebox-assessment-v3',
        temperature: 0.0,
    ));

    Http::assertSent(function ($request): bool {
        return ($request->data()['temperature'] ?? null) === 0.0;
    });
});

test('openai client uses json_object fallback without a schema', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key']);

    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => []])]]],
        ], 200),
    ]);

    app(OpenAiClient::class)->complete(aiRequest());

    Http::assertSent(fn ($request): bool => ($request->data()['response_format']['type'] ?? null) === 'json_object');
});

test('openai client never puts the api key in exception messages', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'super-secret-openrouter-key',
        'ai.base_url' => 'https://openrouter.ai/api/v1',
    ]);

    Http::fake(function () {
        throw new RuntimeException('upstream failed with key=super-secret-openrouter-key');
    });

    try {
        app(OpenAiClient::class)->complete(aiRequest());
        expect(false)->toBeTrue();
    } catch (AiClientException $e) {
        expect($e->getMessage())->not->toContain('super-secret-openrouter-key')
            ->and($e->getMessage())->toContain('[redacted]');
    }
});

test('openai client uses json_object for Google/Gemini models even when a schema is provided', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.base_url' => 'https://openrouter.ai/api/v1',
        'ai.model' => 'google/gemini-3.1-flash-lite',
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'google/gemini-3.1-flash-lite',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok'])]]],
        ], 200),
    ]);

    $schema = app(DossierSynthesisJsonSchema::class)->schema();

    $result = app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Synthetiseer als JSON.',
        input: ['rooms' => []],
        promptVersion: 'dossier-synthesis-v7',
        model: 'google/gemini-3.1-flash-lite',
        responseSchema: [
            'name' => 'dossier_synthesis',
            'schema' => $schema,
        ],
    ));

    Http::assertSent(function ($request) use ($schema): bool {
        $data = $request->data();
        $format = $data['response_format'] ?? null;

        // Gemini path must not send json_schema (prod 400 on unsupported keywords).
        return ($data['model'] ?? null) === 'google/gemini-3.1-flash-lite'
            && is_array($format)
            && ($format['type'] ?? null) === 'json_object'
            && ! isset($format['json_schema'])
            && app(DossierSynthesisJsonSchema::class)->unsupportedKeywordsIn($schema) === [];
    });

    expect($result->modelParameters['response_format_type'] ?? null)->toBe('json_object')
        ->and($result->modelParameters['structured_output_mode'] ?? null)->toBe('json_object_for_google_model');
});

test('openai client keeps json_schema for non-Google models with a Gemini-safe schema payload', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key', 'ai.model' => 'openai/gpt-4o-mini']);

    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'openai/gpt-4o-mini',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok'])]]],
        ], 200),
    ]);

    $schemaService = app(DossierSynthesisJsonSchema::class);
    $schema = $schemaService->schema();

    app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Synthetiseer als JSON.',
        input: ['rooms' => []],
        promptVersion: 'dossier-synthesis-v7',
        model: 'openai/gpt-4o-mini',
        responseSchema: [
            'name' => 'dossier_synthesis',
            'schema' => $schema,
        ],
    ));

    Http::assertSent(function ($request) use ($schemaService): bool {
        $format = $request->data()['response_format'] ?? null;
        $wireSchema = is_array($format) ? ($format['json_schema']['schema'] ?? null) : null;

        return is_array($format)
            && ($format['type'] ?? null) === 'json_schema'
            && ($format['json_schema']['strict'] ?? null) === true
            && is_array($wireSchema)
            && $schemaService->unsupportedKeywordsIn($wireSchema) === [];
    });
});

test('openai client includes provider error.message on HTTP 400 without PII', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.model' => 'google/gemini-3.1-flash-lite',
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'error' => [
                'message' => 'Invalid schema for response_format \'json_schema\': schema must be a valid JSON schema containing `pattern`.',
                'code' => 400,
            ],
        ], 400),
    ]);

    try {
        app(OpenAiClient::class)->complete(new AiCompletionRequest(
            prompt: 'Synthetiseer.',
            input: ['rooms' => []],
            promptVersion: 'dossier-synthesis-v7',
            model: 'google/gemini-3.1-flash-lite',
        ));
        expect(false)->toBeTrue();
    } catch (AiClientException $e) {
        expect($e->getMessage())->toContain('status 400')
            ->and($e->getMessage())->toContain('Invalid schema for response_format')
            ->and($e->errorClass)->toBe('provider_error')
            ->and($e->rawResponse)->toContain('Invalid schema')
            ->and($e->model)->toBe('google/gemini-3.1-flash-lite');
    }
});

test('openai client retries once on truncated JSON then classifies truncated/provider_error', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key', 'ai.model' => 'gpt-test']);

    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push([
                'model' => 'gpt-test',
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                'choices' => [[
                    'finish_reason' => 'length',
                    'message' => ['content' => "{\n  \"points\":"],
                ]],
            ], 200)
            ->push([
                'model' => 'gpt-test',
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                'choices' => [[
                    'finish_reason' => 'length',
                    'message' => ['content' => "{\n  \"points\":"],
                ]],
            ], 200),
    ]);

    try {
        app(OpenAiClient::class)->complete(aiRequest());
        expect(false)->toBeTrue();
    } catch (AiClientException $e) {
        expect($e->errorClass)->toBe('truncated/provider_error')
            ->and($e->getMessage())->toContain('afgekapte JSON')
            ->and($e->getMessage())->not->toContain('ongeldige JSON');
    }

    Http::assertSentCount(2);
});
