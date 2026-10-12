<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AiAlertErrorType;
use App\Mail\AiFailureAlertMail;
use App\Support\Logging\AppErrorLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends throttled ops alerts when external AI calls fail or balance is low.
 * Alert delivery failures are logged and never break the caller.
 */
final class AiFailureAlertService
{
    public function __construct(
        private readonly AiFailureClassifier $classifier,
        private readonly AiAlertMessageSanitizer $sanitizer,
        private readonly AppErrorLogger $logger,
    ) {}

    public function alertHttpFailure(
        int $httpStatus,
        string $message,
        ?string $apiKey = null,
        bool $force = false,
    ): bool {
        $type = $this->classifier->classify($httpStatus);
        if ($type === null) {
            return false;
        }

        return $this->send(
            type: $type,
            message: $message,
            httpStatus: $httpStatus,
            apiKey: $apiKey,
            force: $force,
        );
    }

    public function alertBalanceLow(float $remainingUsd, float $thresholdUsd, bool $force = false): bool
    {
        $formatted = number_format($remainingUsd, 2, '.', '');
        $threshold = number_format($thresholdUsd, 2, '.', '');

        return $this->send(
            type: AiAlertErrorType::BalanceLow,
            message: "OpenRouter-saldo ongeveer \${$formatted} (drempel \${$threshold}).",
            httpStatus: null,
            force: $force,
        );
    }

    public function sendTestAlert(bool $force = true): bool
    {
        return $this->send(
            type: AiAlertErrorType::Test,
            message: 'Dit is een testalert van ai:alert-test. Geen echte storing.',
            httpStatus: null,
            force: $force,
            isTest: true,
        );
    }

    public function send(
        AiAlertErrorType $type,
        string $message,
        ?int $httpStatus = null,
        ?string $apiKey = null,
        bool $force = false,
        bool $isTest = false,
    ): bool {
        try {
            if (! (bool) config('ai.alerts.enabled', true) && ! $isTest && ! $force) {
                return false;
            }

            $recipient = trim((string) config('ai.alerts.mail_to', 'info@jpwebcreation.nl'));
            if ($recipient === '') {
                return false;
            }

            if (! $force && ! $this->acquireThrottle($type)) {
                return false;
            }

            $appName = (string) config('app.name', 'Digitale Opname');
            $sanitized = $this->sanitizer->sanitize($message, $apiKey);

            Mail::to($recipient)->send(new AiFailureAlertMail(
                errorType: $type,
                appName: $appName,
                occurredAt: now()->timezone((string) config('app.timezone', 'Europe/Amsterdam'))->format('Y-m-d H:i:s T'),
                httpStatus: $httpStatus,
                sanitizedMessage: $sanitized,
                isTest: $isTest || $type === AiAlertErrorType::Test,
            ));

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning('AI alert e-mail mislukt', [
                'alert_type' => $type->value,
                'http_status' => $httpStatus,
            ], $exception);

            return false;
        }
    }

    private function acquireThrottle(AiAlertErrorType $type): bool
    {
        $seconds = $type->throttleSeconds();
        if ($seconds <= 0) {
            return true;
        }

        return Cache::add($type->cacheKey(), 1, $seconds);
    }
}
