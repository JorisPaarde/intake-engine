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
 * visible in laravel.log instead of only the exception class. Whitelist-based:
 * provider text is only logged for account/availability statuses (never for
 * 400/422, which may echo input), only our own fixed messages are logged
 * otherwise, and the key, key-shaped tokens, e-mail and phone numbers are
 * scrubbed. Never the prompt or customer data.
 */
final class AiFailureLogContext
{
    public const MESSAGE_LIMIT = 200;

    /**
     * HTTP statuses whose provider text is a standard account/availability
     * message (credits, auth, rate limit, outage) and not an echo of our input.
     * For other statuses (400/422 …) only status and code are logged.
     */
    private const MESSAGE_SAFE_STATUSES = [401, 402, 403, 404, 408, 409, 429, 500, 502, 503, 504];

    /**
     * Fixed messages built by OpenAiClient / AiBudgetGuard / NullAiClient.
     */
    private const SAFE_MESSAGE_PREFIXES = [
        'Externe AI-aanroep mislukt: cURL error',
        'Externe AI-provider gaf geen bruikbare inhoud',
        'Externe AI-provider gaf afgekapte JSON',
        'Externe AI-provider gaf ongeldige JSON',
        'AI-budgetlimiet bereikt',
        'AI-budgetcap ontbreekt',
        'AI_API_KEY ontbreekt',
        'AI-provider is niet geconfigureerd',
    ];

    /**
     * @return array{exception: class-string, error_message: string, http_status?: int, provider_error_code?: string, error_class?: string}
     */
    public static function from(Throwable $exception): array
    {
        $context = [
            'exception' => $exception::class,
            'error_message' => self::message($exception),
        ];

        if (! $exception instanceof AiClientException) {
            return $context;
        }

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

    /**
     * Whitelist: only text we know cannot contain customer data or prompts.
     * Everything else is reduced to the exception class; the full text stays
     * in ai_runs.error_message as before.
     */
    public static function message(Throwable $exception): string
    {
        if ($exception instanceof AiClientException && $exception->httpStatus !== null) {
            $message = 'Externe AI-provider gaf status '.$exception->httpStatus.'.';
            $provider = $exception->providerMessage !== null ? trim($exception->providerMessage) : '';

            if ($provider !== '' && in_array($exception->httpStatus, self::MESSAGE_SAFE_STATUSES, true)) {
                $message .= ' '.$provider;
            } elseif ($provider !== '') {
                $message .= ' (providermelding niet gelogd; zie ai_runs.error_message)';
            }

            return Str::limit(self::redact($message), self::MESSAGE_LIMIT);
        }

        $text = trim($exception->getMessage());
        if ($exception instanceof AiClientException) {
            foreach (self::SAFE_MESSAGE_PREFIXES as $prefix) {
                if (str_starts_with($text, $prefix)) {
                    return Str::limit(self::redact($text), self::MESSAGE_LIMIT);
                }
            }
        }

        $source = $exception instanceof AiClientException && $exception->getPrevious() !== null
            ? $exception->getPrevious()
            : $exception;

        return 'AI-fout zonder logbare melding ('.class_basename($source).'); zie ai_runs.error_message.';
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
