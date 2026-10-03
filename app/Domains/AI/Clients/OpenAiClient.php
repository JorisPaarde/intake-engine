<?php

declare(strict_types=1);

namespace App\Domains\AI\Clients;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Services\AiBudgetGuard;
use App\Domains\AI\Services\AiInputRedactor;
use App\Domains\AI\Services\AiTraceRedactor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat client behind AiClientInterface (BL-006). Works with OpenAI
 * and OpenAI-compatible gateways (e.g. OpenRouter via AI_BASE_URL). Requires AI_API_KEY
 * and budget caps when enforced; default provider stays `null`. PII is redacted before
 * sending (AiInputRedactor). Failures raise AiClientException (soft-fail for callers).
 * API keys never appear in exception messages.
 *
 * When the caller passes a JSON schema, uses strict structured output
 * (`response_format.type=json_schema`); otherwise falls back to `json_object`.
 */
final class OpenAiClient implements AiClientInterface
{
    private const RAW_RESPONSE_LIMIT = 8000;

    public function __construct(
        private readonly AiInputRedactor $redactor,
        private readonly AiTraceRedactor $traceRedactor,
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
        $timeout = $request->timeoutSeconds
            ?? (int) config('ai.timeout_seconds', 20);

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

        $responseFormat = $this->responseFormat($request);
        $modelParameters = [
            'temperature' => 0.2,
            'response_format' => $responseFormat,
            'timeout_seconds' => $timeout,
            'base_url' => $baseUrl,
        ];

        $providerStarted = microtime(true);

        try {
            $response = $this->httpClient($baseUrl, $apiKey, $timeout)
                ->post('/chat/completions', [
                    'model' => $model,
                    'temperature' => $modelParameters['temperature'],
                    'response_format' => $responseFormat,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ]);
        } catch (\Throwable $e) {
            $providerMs = (int) round((microtime(true) - $providerStarted) * 1000);

            throw new AiClientException(
                'Externe AI-aanroep mislukt: '.$this->safeExceptionMessage($e->getMessage(), $apiKey),
                previous: $e,
                providerMs: $providerMs,
            );
        }

        $providerMs = (int) round((microtime(true) - $providerStarted) * 1000);
        $finishReason = $response->json('choices.0.finish_reason');
        $finishReason = is_string($finishReason) ? $finishReason : null;
        $usage = $this->usageFromResponse($response);
        $rawBody = $this->redactedRawBody((string) $response->body());

        if ($response->failed()) {
            throw new AiClientException(
                'Externe AI-provider gaf status '.$response->status().'.',
                providerMs: $providerMs,
                rawResponse: $rawBody,
                finishReason: $finishReason,
                usage: $usage,
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new AiClientException(
                'Externe AI-provider gaf geen bruikbare inhoud.',
                providerMs: $providerMs,
                rawResponse: $rawBody,
                finishReason: $finishReason,
                usage: $usage,
            );
        }

        /** @var array<string, mixed>|null $output */
        $output = json_decode($content, true);

        if (! is_array($output)) {
            throw new AiClientException(
                'Externe AI-provider gaf ongeldige JSON.',
                providerMs: $providerMs,
                rawResponse: $this->redactedRawBody($content),
                finishReason: $finishReason,
                usage: $usage,
            );
        }

        $inputTokens = $usage['input_tokens'];
        $outputTokens = $usage['output_tokens'];
        $totalTokens = $usage['total_tokens'];
        $imageCount = count($request->images);
        $actualModel = is_string($response->json('model')) ? $response->json('model') : $model;

        return new AiCompletionResult(
            output: $output,
            provider: 'openai',
            model: $actualModel,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            imageCount: $imageCount,
            estimatedCostCents: $this->budgetGuard->estimateCostCents($inputTokens, $outputTokens, $imageCount),
            finishReason: $finishReason,
            rawResponse: $content,
            providerMs: $providerMs,
            modelParameters: $modelParameters,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFormat(AiCompletionRequest $request): array
    {
        if ($request->responseSchema !== null && $request->responseSchema !== []) {
            $name = is_string($request->responseSchema['name'] ?? null)
                ? $request->responseSchema['name']
                : 'structured_output';
            $schema = is_array($request->responseSchema['schema'] ?? null)
                ? $request->responseSchema['schema']
                : $request->responseSchema;

            return [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $name,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ];
        }

        return ['type' => 'json_object'];
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

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     */
    private function usageFromResponse(Response $response): array
    {
        return [
            'input_tokens' => $this->integerUsage($response->json('usage.prompt_tokens')),
            'output_tokens' => $this->integerUsage($response->json('usage.completion_tokens')),
            'total_tokens' => $this->integerUsage($response->json('usage.total_tokens')),
        ];
    }

    private function redactedRawBody(string $body): string
    {
        $safe = $this->traceRedactor->redactString($body);

        if (strlen($safe) > self::RAW_RESPONSE_LIMIT) {
            return substr($safe, 0, self::RAW_RESPONSE_LIMIT).'…[truncated]';
        }

        return $safe;
    }
}
