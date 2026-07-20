<?php

declare(strict_types=1);

use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Services\AiInputRedactor;
use Illuminate\Support\Facades\Http;

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
            'choices' => [['message' => ['content' => json_encode(['summary' => 'Klaar', 'highlights' => ['a']])]]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->complete(aiRequest());

    expect($result->provider)->toBe('openai')
        ->and($result->output['summary'])->toBe('Klaar');
});

test('openai client redacts PII in the outgoing payload', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key']);

    Http::fake([
        '*/chat/completions' => Http::response([
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

test('openai client soft-fails on an error status', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key']);

    Http::fake(['*/chat/completions' => Http::response(['error' => 'boom'], 500)]);

    app(OpenAiClient::class)->complete(aiRequest());
})->throws(AiClientException::class);

test('openai client requires an api key', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => '']);

    app(OpenAiClient::class)->complete(aiRequest());
})->throws(AiClientException::class);
