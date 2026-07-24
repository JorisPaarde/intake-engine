<?php

declare(strict_types=1);

use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiInputRedactor;
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
