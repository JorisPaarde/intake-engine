<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Alert categories for OpenRouter / external AI failures and balance warnings.
 * Cache throttle keys use the string value: ai-alert:{value}.
 */
enum AiAlertErrorType: string
{
    case Auth = '401';
    case Payment = '402';
    case RateLimit = '429';
    case Server = '5xx';
    case BalanceLow = 'balance_low';
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Auth => 'Authenticatiefout (401)',
            self::Payment => 'Betaling / credits (402)',
            self::RateLimit => 'Rate limit (429)',
            self::Server => 'Serverfout (5xx)',
            self::BalanceLow => 'Laag OpenRouter-saldo',
            self::Test => 'Testalert',
        };
    }

    public function throttleSeconds(): int
    {
        return match ($this) {
            self::BalanceLow => max(60, (int) config('ai.alerts.balance_throttle_seconds', 86400)),
            self::Test => 0,
            default => max(60, (int) config('ai.alerts.throttle_seconds', 3600)),
        };
    }

    public function cacheKey(): string
    {
        return 'ai-alert:'.$this->value;
    }
}
