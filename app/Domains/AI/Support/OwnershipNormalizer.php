<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Maps Dutch ownership phrasings to catalog option values (`owned` / `rented`).
 * Deterministic code-side normaliser for catalogus-prefill (prompt examples alone
 * are not enough — models sometimes return `koop` / `huurwoning` instead of tokens).
 */
final class OwnershipNormalizer
{
    /**
     * @return 'owned'|'rented'|null
     */
    public function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = mb_strtolower(trim($raw), 'UTF-8');
        $value = str_replace(['’', '‘', '´'], "'", $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        if ($value === '') {
            return null;
        }

        if (in_array($value, ['owned', 'rented'], true)) {
            return $value;
        }

        if ($this->matchesOwned($value)) {
            return 'owned';
        }

        if ($this->matchesRented($value)) {
            return 'rented';
        }

        return null;
    }

    private function matchesOwned(string $value): bool
    {
        return (bool) preg_match(
            '/\b(?:'
            .'koop(?:woning|huis|appartement)?'
            .'|eigen\s+(?:woning|huis|appartement)'
            .'|in\s+eigendom'
            .'|eigendom'
            .'|wij\s+bezitten'
            .'|we\s+bezitten'
            .'|ons\s+eigen\s+huis'
            .')\b/u',
            $value,
        );
    }

    private function matchesRented(string $value): bool
    {
        return (bool) preg_match(
            '/\b(?:'
            .'huur(?:woning|huis|appartement)?'
            .'|gehuurd'
            .'|wij\s+huren'
            .'|we\s+huren'
            .'|ik\s+huur'
            .'|van\s+de\s+verhuurder'
            .'|huurders?'
            .')\b/u',
            $value,
        );
    }
}
