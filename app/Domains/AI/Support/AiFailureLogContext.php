<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\AI\Exceptions\AiClientException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Safe log context for a failed AI call (N103).
 *
 * Gives every AI failure log line the HTTP status, the provider error code and
 * the first ~200 characters of the message, so a 402 (credits) or 429 is
 * visible in laravel.log instead of only the exception class. Never includes
 * the API key or the prompt: AiClientException messages are built from the
 * provider's error text (key already redacted in OpenAiClient), and the key is
 * redacted here once more as a safety net.
 */
final class AiFailureLogContext
{
    public const MESSAGE_LIMIT = 200;

    /**
     * @return array{exception: class-string, error_message: string, http_status?: int, provider_error_code?: string, error_class?: string}
     */
    public static function from(Throwable $exception): array
    {
        // Only AiClientException messages are built from provider error text
        // and our own fixed strings. Other throwables (e.g. QueryException with
        // SQL bindings) can carry customer data, so for those we log the class
        // only; the full text stays in ai_runs.error_message as before.
        if (! $exception instanceof AiClientException) {
            return [
                'exception' => $exception::class,
                'error_message' => 'Geen AI-providerfout ('.class_basename($exception).'); zie ai_runs.error_message.',
            ];
        }

        $context = [
            'exception' => $exception::class,
            'error_message' => self::message($exception),
        ];

        if ($exception->httpStatus !== null) {
            $context['http_status'] = $exception->httpStatus;
        }
        $code = self::safeCode($exception->providerErrorCode);
        if ($code !== null) {
            $context['provider_error_code'] = $code;
        }
        if ($exception->errorClass !== null && $exception->errorClass !== '') {
            $context['error_class'] = $exception->errorClass;
        }

        return $context;
    }

    public static function message(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = '(geen melding) '.$exception::class;
        }

        return Str::limit(self::redact($message), self::MESSAGE_LIMIT);
    }

    /**
     * Provider codes are short identifiers (402, "insufficient_quota"). Anything
     * else is dropped rather than logged.
     */
    public static function safeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = trim($code);
        if ($code === '' || preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $code) !== 1) {
            return null;
        }

        return self::redact($code) === $code && ! str_starts_with(strtolower($code), 'sk-')
            ? $code
            : null;
    }

    private static function redact(string $text): string
    {
        $apiKey = config('ai.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $text = str_replace($apiKey, '[redacted]', $text);
        }

        // Key-shaped tokens, e-mail addresses and phone numbers never go to the log.
        $text = (string) preg_replace('/\bsk-[A-Za-z0-9_\-]{8,}/', '[redacted]', $text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[e-mail]', $text);

        return (string) preg_replace('/(?<![\d.])(?:\+31|0)[\s\-]?\d(?:[\s\-]?\d){7,9}(?!\d)/', '[telefoon]', $text);
    }
}
