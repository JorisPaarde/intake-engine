<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Redacts secrets and customer-link tokens from AI-trace payloads before persistence.
 * Never logs API keys, Bearer auth headers, or /o/{token} customer access tokens.
 */
final class AiTraceRedactor
{
    private const SECRET_PATTERNS = [
        '/sk-[A-Za-z0-9_\-]{8,}/' => '[api-key-redacted]',
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [redacted]',
        '/(api[_-]?key|authorization|x-api-key)\s*[:=]\s*["\']?[^\s"\']+/i' => '$1=[redacted]',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        /** @var array<string, mixed> $redacted */
        $redacted = $this->walk($payload);

        return $redacted;
    }

    public function redactString(string $value): string
    {
        $safe = $value;

        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $safe = (string) preg_replace($pattern, $replacement, $safe);
        }

        // Customer intake tokens live under /o/{64-char token}.
        $safe = (string) preg_replace('#(/o/)[A-Za-z0-9]{32,}#', '$1[token-redacted]', $safe);

        // Opaque base64 blobs (never store image bytes in the standard log).
        if (strlen($safe) > 500 && preg_match('#^[A-Za-z0-9+/=\s]+$#', $safe) === 1) {
            return '[base64-omitted len='.strlen($safe).']';
        }

        return $safe;
    }

    private function walk(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? strtolower($key) : (string) $key;

            if ($this->isSecretKey($keyString)) {
                $out[$key] = '[redacted]';

                continue;
            }

            if (in_array($keyString, ['image_url', 'url', 'data', 'binary', 'base64', 'bytes'], true)
                && is_string($item)
                && (str_starts_with($item, 'data:') || strlen($item) > 200)) {
                $out[$key] = $this->photoRefPlaceholder($item);

                continue;
            }

            $out[$key] = $this->walk($item);
        }

        return $out;
    }

    private function isSecretKey(string $key): bool
    {
        return str_contains($key, 'api_key')
            || str_contains($key, 'apikey')
            || $key === 'authorization'
            || $key === 'auth'
            || str_contains($key, 'access_token')
            || str_contains($key, 'customer_token')
            || str_contains($key, 'customer_access')
            || $key === 'token' && ! str_contains($key, 'tokens');
    }

    private function photoRefPlaceholder(string $value): string
    {
        if (str_starts_with($value, 'data:')) {
            $mime = 'image';
            if (preg_match('#^data:([^;]+);base64,#', $value, $matches) === 1) {
                $mime = $matches[1];
            }

            return '[image-omitted mime='.$mime.' bytes≈'.max(0, strlen($value) - 30).']';
        }

        return '[binary-omitted len='.strlen($value).']';
    }
}
