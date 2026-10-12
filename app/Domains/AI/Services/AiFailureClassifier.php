<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AiAlertErrorType;

/**
 * Maps HTTP status codes from the external AI provider to alert categories.
 */
final class AiFailureClassifier
{
    public function classify(?int $httpStatus): ?AiAlertErrorType
    {
        if ($httpStatus === null) {
            return null;
        }

        return match (true) {
            $httpStatus === 401 => AiAlertErrorType::Auth,
            $httpStatus === 402 => AiAlertErrorType::Payment,
            $httpStatus === 429 => AiAlertErrorType::RateLimit,
            $httpStatus >= 500 && $httpStatus <= 599 => AiAlertErrorType::Server,
            default => null,
        };
    }

    public function isRetryable(?int $httpStatus): bool
    {
        if ($httpStatus === null) {
            return false;
        }

        return $httpStatus === 429 || ($httpStatus >= 500 && $httpStatus <= 599);
    }

    public function isAlertable(?int $httpStatus): bool
    {
        return $this->classify($httpStatus) !== null;
    }
}
