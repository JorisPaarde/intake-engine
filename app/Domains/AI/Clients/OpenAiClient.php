<?php

declare(strict_types=1);

namespace App\Domains\AI\Clients;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Services\AiBudgetGuard;
use App\Domains\AI\Services\AiInputRedactor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat client behind AiClientInterface (BL-006). Works with OpenAI
 * and OpenAI-compatible gateways (e.g. OpenRouter via AI_BASE_URL). Requires AI_API_KEY
 * and budget caps when enforced; default provider stays `null`. PII is redacted before
 * sending (AiInputRedactor). Failures raise AiClientException (soft-fail for callers).
 * API keys never appear in exception messages.
 */
final class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private readonly AiInputRedactor $redactor,
        private readonly AiBudgetGuard $budgetGuard,
    ) {}

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $apiKey = (string) config('ai.api_key');

        if ($apiKey === '') {
            throw new AiClientException('AI_API_KEY ontbreekt voor de externe provider.');
        }

        $baseUrl = rtrim((string) config('ai.base_url', 'https://api.openai.com/v1'), '/');
        $model = $this->resolveModel($request);
        $timeout = (int) config('ai.timeout_seconds', 20);

        $this->budgetGuard->ensureOpenAiBudgetAvailable();

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
            $response = $this->httpClient($baseUrl, $apiKey, $timeout)
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
            throw new AiClientException(
                'Externe AI-aanroep mislukt: '.$this->safeExceptionMessage($e->getMessage(), $apiKey),
                previous: $e,
            );
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

        $inputTokens = $this->integerUsage($response->json('usage.prompt_tokens'));
        $outputTokens = $this->integerUsage($response->json('usage.completion_tokens'));
        $totalTokens = $this->integerUsage($response->json('usage.total_tokens'));
        $imageCount = count($request->images);

        return new AiCompletionResult(
            output: $output,
            provider: 'openai',
            model: is_string($response->json('model')) ? $response->json('model') : $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            imageCount: $imageCount,
            estimatedCostCents: $this->budgetGuard->estimateCostCents($inputTokens, $outputTokens, $imageCount),
        );
    }

    private function resolveModel(AiCompletionRequest $request): string
    {
        if ($request->model !== null && trim($request->model) !== '') {
            return trim($request->model);
        }

        if ($request->images !== []) {
            $visionModel = trim((string) config('ai.vision_model', ''));

            if ($visionModel !== '') {
                return $visionModel;
            }
        }

        return (string) config('ai.model', 'gpt-4o-mini');
    }

    private function httpClient(string $baseUrl, string $apiKey, int $timeout): PendingRequest
    {
        $client = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->withToken($apiKey)
            ->asJson();

        $headers = [];
        $referer = trim((string) config('ai.http_referer', ''));
        $appTitle = trim((string) config('ai.app_title', ''));

        if ($referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }

        if ($appTitle !== '') {
            // OpenRouter historically accepted X-Title; current docs also use X-OpenRouter-Title.
            $headers['X-Title'] = $appTitle;
            $headers['X-OpenRouter-Title'] = $appTitle;
        }

        if ($headers !== []) {
            $client = $client->withHeaders($headers);
        }

        return $client;
    }

    private function safeExceptionMessage(string $message, string $apiKey): string
    {
        $safe = $message;

        if ($apiKey !== '' && str_contains($safe, $apiKey)) {
            $safe = str_replace($apiKey, '[redacted]', $safe);
        }

        return $safe;
    }

    private function integerUsage(mixed $value): ?int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
