<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\AiClientException;
use App\Enums\AiAlertErrorType;
use Throwable;

/**
 * Neat Dutch messages for installer/customer UI when AI fails.
 * Never expose raw provider errors, status bodies, or secrets.
 */
final class AiUserFacingFallback
{
    public const GENERIC = 'De AI-analyse is tijdelijk niet beschikbaar. Je kunt gewoon verder; we proberen het later opnieuw.';

    public const CATALOG = 'De AI-analyse van de aanvraagtekst is tijdelijk niet beschikbaar. De rest van de opname gaat gewoon door.';

    public function __construct(
        private readonly AiFailureClassifier $classifier = new AiFailureClassifier,
    ) {}

    public function forHttpStatus(?int $httpStatus): string
    {
        $type = $this->classifier->classify($httpStatus);

        return match ($type) {
            AiAlertErrorType::RateLimit => 'De AI-dienst is tijdelijk overbelast. Probeer het later opnieuw.',
            AiAlertErrorType::Auth,
            AiAlertErrorType::Payment,
            AiAlertErrorType::Server => 'De AI-dienst is tijdelijk niet beschikbaar. Probeer het later opnieuw.',
            default => self::GENERIC,
        };
    }

    public function forException(Throwable $exception): string
    {
        if ($exception instanceof AiClientException && $exception->httpStatus !== null) {
            return $this->forHttpStatus($exception->httpStatus);
        }

        return self::GENERIC;
    }

    public function forCatalogException(Throwable $exception): string
    {
        if ($exception instanceof AiClientException && $exception->httpStatus !== null) {
            $type = $this->classifier->classify($exception->httpStatus);

            return match ($type) {
                AiAlertErrorType::RateLimit => 'Catalogus-AI is tijdelijk overbelast. De rest van de analyse gaat gewoon door.',
                AiAlertErrorType::Auth,
                AiAlertErrorType::Payment,
                AiAlertErrorType::Server => 'Catalogus-AI is tijdelijk niet beschikbaar. De rest van de analyse gaat gewoon door.',
                default => self::CATALOG,
            };
        }

        return self::CATALOG;
    }
}
