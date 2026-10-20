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
        $context = [
            'exception' => $exception::class,
            'error_message' => self::message($exception),
        ];

        if ($exception instanceof AiClientException) {
            if ($exception->httpStatus !== null) {
                $context['http_status'] = $exception->httpStatus;
            }
            if ($exception->providerErrorCode !== null && $exception->providerErrorCode !== '') {
                $context['provider_error_code'] = $exception->providerErrorCode;
            }
            if ($exception->errorClass !== null && $exception->errorClass !== '') {
                $context['error_class'] = $exception->errorClass;
            }
        }

        return $context;
    }

    public static function message(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = '(geen melding) '.$exception::class;
        }

        $apiKey = config('ai.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $message = str_replace($apiKey, '[redacted]', $message);
        }

        return Str::limit($message, self::MESSAGE_LIMIT);
    }
}
