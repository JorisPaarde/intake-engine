<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Redacts secrets, customer-link tokens, and obvious PII from AI-trace payloads
 * before persistence. Never logs API keys, Bearer auth headers, or /o/{token}
 * customer access tokens. Email/phone patterns mirror AiInputRedactor.
 */
final class AiTraceRedactor
{
    private const SECRET_PATTERNS = [
        '/sk-[A-Za-z0-9_\-]{8,}/' => '[api-key-redacted]',
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [redacted]',
        '/(api[_-]?key|authorization|x-api-key)\s*[:=]\s*["\']?[^\s"\']+/i' => '$1=[redacted]',
    ];

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // NL/intl telefoonnummers: +31/0031/0 gevolgd door 8+ cijfers met optionele spaties/streepjes.
    private const PHONE = '/(?<!\d)(?:\+31|0031|0)[\s\-]?(?:\d[\s\-]?){8,11}\d(?!\d)/';

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

        $safe = (string) preg_replace('#(/o/)[A-Za-z0-9]{32,}#', '$1[token-redacted]', $safe);
        $safe = (string) preg_replace(self::EMAIL, '[e-mail verwijderd]', $safe);
        $safe = (string) preg_replace(self::PHONE, '[telefoon verwijderd]', $safe);

        if ($this->looksLikeBase64Blob($safe)) {
            return '[base64-omitted len='.strlen($safe).']';
        }

        return $safe;
    }

    /**
     * Base64 only when data:-prefix or a long unbroken base64 alphabet string (no whitespace).
     * Long customer free-text without punctuation must stay intact.
     */
    public function looksLikeBase64Blob(string $value): bool
    {
        if (str_starts_with($value, 'data:') && str_contains($value, ';base64,')) {
            return true;
        }

        if (strlen($value) <= 500) {
            return false;
        }

        // No whitespace allowed — otherwise long NL customer text without punctuation is kept.
        if (preg_match('/\s/', $value) === 1) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9+/=]+$#', $value) === 1;
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
            || ($key === 'token');
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
