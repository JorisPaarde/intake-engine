<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use Illuminate\Support\Str;

/**
 * Short, secret-free snippets for AI alert e-mails. Never include API keys or tokens.
 */
final class AiAlertMessageSanitizer
{
    private const MAX_LENGTH = 280;

    public function sanitize(string $message, ?string $apiKey = null): string
    {
        $safe = $message;
        $key = $apiKey ?? (string) config('ai.api_key', '');

        if ($key !== '' && str_contains($safe, $key)) {
            $safe = str_replace($key, '[redacted]', $safe);
        }

        $safe = (string) preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', '[api-key-redacted]', $safe);
        $safe = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $safe);
        $safe = (string) preg_replace(
            '/(api[_-]?key|authorization|x-api-key)\s*[:=]\s*["\']?[^\s"\']+/i',
            '$1=[redacted]',
            $safe,
        );
        $safe = (string) preg_replace('/or-v1-[A-Za-z0-9_\-]{8,}/', '[api-key-redacted]', $safe);

        $safe = trim(preg_replace('/\s+/', ' ', $safe) ?? $safe);

        return Str::limit($safe, self::MAX_LENGTH, '…');
    }
}
