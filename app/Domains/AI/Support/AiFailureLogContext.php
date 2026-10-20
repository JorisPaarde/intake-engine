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
 * a fixed label per status, provider text only when it is a known standard
 * account/availability message (anything else may echo input), our own fixed
 * messages, and otherwise only the exception class. The key, key-shaped
 * tokens, e-mail and phone numbers are scrubbed. Never the prompt or
 * customer data; the full text stays in ai_runs.error_message.
 */
final class AiFailureLogContext
{
    public const MESSAGE_LIMIT = 200;

    /**
     * Fixed local explanation per HTTP status, so a 402 is always readable.
     */
    private const STATUS_LABELS = [
        400 => 'ongeldig verzoek',
        401 => 'ongeldige of ontbrekende API-key',
        402 => 'onvoldoende krediet of te hoge max_tokens',
        403 => 'geweigerd (rechten of moderatie)',
        404 => 'model of endpoint niet gevonden',
        408 => 'time-out bij provider',
        413 => 'verzoek te groot',
        422 => 'ongeldig verzoek',
        429 => 'rate limit',
        500 => 'providerstoring',
        502 => 'providerstoring',
        503 => 'provider niet beschikbaar',
        504 => 'time-out bij provider',
    ];

    /**
     * Provider texts known to be standard account/availability messages
     * (OpenRouter/OpenAI). Only these are logged verbatim (capped): any other
     * provider text might echo input and is left to ai_runs.error_message.
     */
    private const SAFE_PROVIDER_MESSAGE_PREFIXES = [
        'This request requires more credits',
        'Insufficient credits',
        'Key limit exceeded',
        'No auth credentials found',
        'User not found',
        'Invalid API key',
        'Incorrect API key',
        'Rate limit exceeded',
        'You exceeded your current quota',
        'No endpoints found',
        'Request timed out',
    ];

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
            $status = $exception->httpStatus;
            $label = self::STATUS_LABELS[$status] ?? ($status >= 500 ? 'providerstoring' : 'providerfout');
            $message = 'Externe AI-provider gaf status '.$status.' ('.$label.').';
            $provider = $exception->providerMessage !== null ? trim($exception->providerMessage) : '';

            if ($provider !== '' && self::isSafeProviderMessage($provider)) {
                $message .= ' '.$provider;
            } elseif ($provider !== '') {
                $message .= ' Providermelding niet gelogd; zie ai_runs.error_message.';
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

    private static function isSafeProviderMessage(string $text): bool
    {
        foreach (self::SAFE_PROVIDER_MESSAGE_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
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
