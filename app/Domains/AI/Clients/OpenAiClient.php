<?php

declare(strict_types=1);

namespace App\Domains\AI\Clients;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Services\AiInputRedactor;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat client behind AiClientInterface (BL-006). Requires an API key
 * in .env (AI_API_KEY); default provider stays `null` until DPIA + key are in place.
 * PII is redacted before sending (AiInputRedactor). Any failure raises AiClientException,
 * which the callers treat as a soft-fail — the intake flow never depends on this.
 */
final class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private readonly AiInputRedactor $redactor,
    ) {}

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $apiKey = (string) config('ai.api_key');

        if ($apiKey === '') {
            throw new AiClientException('AI_API_KEY ontbreekt voor de externe provider.');
        }

        $baseUrl = rtrim((string) config('ai.base_url', 'https://api.openai.com/v1'), '/');
        $model = $request->model !== null && trim($request->model) !== ''
            ? trim($request->model)
            : (string) config('ai.model', 'gpt-4o-mini');
        $timeout = (int) config('ai.timeout_seconds', 20);

        $system = trim($request->prompt."\n\n".($request->system ?? ''));
        $redactedInput = $this->redactor->redact($request->input);
        $userContent = [
            [
                'type' => 'text',
                'text' => (string) json_encode($redactedInput, JSON_THROW_ON_ERROR),
            ],
        ];

        foreach ($request->images as $image) {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$image->mimeType.';base64,'.base64_encode($image->binary),
                    'detail' => $image->detail,
                ],
            ];
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout($timeout)
                ->withToken($apiKey)
                ->asJson()
                ->post('/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ]);
        } catch (\Throwable $e) {
            throw new AiClientException('Externe AI-aanroep mislukt: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiClientException('Externe AI-provider gaf status '.$response->status().'.');
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new AiClientException('Externe AI-provider gaf geen bruikbare inhoud.');
        }

        /** @var array<string, mixed>|null $output */
        $output = json_decode($content, true);

        if (! is_array($output)) {
            throw new AiClientException('Externe AI-provider gaf ongeldige JSON.');
        }

        return new AiCompletionResult(
            output: $output,
            provider: 'openai',
            model: is_string($response->json('model')) ? $response->json('model') : $model,
        );
    }
}
