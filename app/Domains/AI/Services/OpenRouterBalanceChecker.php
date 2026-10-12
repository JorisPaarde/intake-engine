<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads remaining OpenRouter credit via GET /api/v1/key (fallback /api/v1/credits).
 */
final class OpenRouterBalanceChecker
{
    /**
     * @return array{remaining_usd: float, source: string, raw: array<string, mixed>}
     */
    public function check(?string $apiKey = null): array
    {
        $key = $apiKey ?? (string) config('ai.api_key', '');
        if ($key === '') {
            throw new RuntimeException('AI_API_KEY ontbreekt; saldo kan niet worden gecontroleerd.');
        }

        $client = $this->httpClient($key);

        try {
            $keyResponse = $client->get('/key');
            if ($keyResponse->successful()) {
                $parsed = $this->parseKeyResponse($keyResponse->json());
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        } catch (\Throwable) {
            // Fall through to /credits.
        }

        $creditsResponse = $client->get('/credits');
        if (! $creditsResponse->successful()) {
            throw new RuntimeException(
                'OpenRouter saldo-check mislukt (HTTP '.$creditsResponse->status().').',
            );
        }

        $parsed = $this->parseCreditsResponse($creditsResponse->json());
        if ($parsed === null) {
            throw new RuntimeException('OpenRouter saldo-check gaf geen herkenbaar creditsaldo.');
        }

        return $parsed;
    }

    private function httpClient(string $apiKey): PendingRequest
    {
        $client = Http::baseUrl('https://openrouter.ai/api/v1')
            ->timeout(15)
            ->withToken($apiKey)
            ->acceptJson();

        $headers = [];
        $referer = trim((string) config('ai.http_referer', ''));
        $appTitle = trim((string) config('ai.app_title', ''));

        if ($referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }
        if ($appTitle !== '') {
            $headers['X-Title'] = $appTitle;
            $headers['X-OpenRouter-Title'] = $appTitle;
        }
        if ($headers !== []) {
            $client = $client->withHeaders($headers);
        }

        return $client;
    }

    /**
     * @return array{remaining_usd: float, source: string, raw: array<string, mixed>}|null
     */
    private function parseKeyResponse(mixed $json): ?array
    {
        $data = $this->unwrapDataPayload($json);
        if ($data === null) {
            return null;
        }

        if (isset($data['limit_remaining']) && is_numeric($data['limit_remaining'])) {
            return [
                'remaining_usd' => max(0.0, (float) $data['limit_remaining']),
                'source' => 'key.limit_remaining',
                'raw' => $data,
            ];
        }

        $limit = $data['limit'] ?? null;
        $usage = $data['usage'] ?? null;
        if (is_numeric($limit) && is_numeric($usage)) {
            return [
                'remaining_usd' => max(0.0, (float) $limit - (float) $usage),
                'source' => 'key.limit_minus_usage',
                'raw' => $data,
            ];
        }

        return null;
    }

    /**
     * @return array{remaining_usd: float, source: string, raw: array<string, mixed>}|null
     */
    private function parseCreditsResponse(mixed $json): ?array
    {
        $data = $this->unwrapDataPayload($json);
        if ($data === null) {
            return null;
        }

        $totalCredits = $data['total_credits'] ?? $data['credits'] ?? null;
        $totalUsage = $data['total_usage'] ?? $data['usage'] ?? 0;

        if (! is_numeric($totalCredits)) {
            return null;
        }

        $usage = is_numeric($totalUsage) ? (float) $totalUsage : 0.0;

        return [
            'remaining_usd' => max(0.0, (float) $totalCredits - $usage),
            'source' => 'credits.total_credits_minus_usage',
            'raw' => $data,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function unwrapDataPayload(mixed $json): ?array
    {
        if (! is_array($json)) {
            return null;
        }

        $nested = $json['data'] ?? null;
        if (is_array($nested)) {
            return $this->stringKeyed($nested);
        }

        return $this->stringKeyed($json);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    private function stringKeyed(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
