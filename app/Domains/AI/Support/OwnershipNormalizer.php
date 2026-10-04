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

        $value = $this->prepare($raw);

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

    /**
     * Short literal quote from the source text that proves owned/rented (for stated evidence).
     */
    public function matchedEvidenceQuote(string $sourceText): ?string
    {
        $normalized = $this->prepare($sourceText);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/'.$this->ownedPattern().'/u', $normalized, $matches) === 1) {
            return $this->originalCaseQuote($sourceText, $matches[0]);
        }

        if (preg_match('/'.$this->rentedPattern().'/u', $normalized, $matches) === 1) {
            return $this->originalCaseQuote($sourceText, $matches[0]);
        }

        return null;
    }

    private function matchesOwned(string $value): bool
    {
        return (bool) preg_match('/'.$this->ownedPattern().'/u', $value);
    }

    private function matchesRented(string $value): bool
    {
        return (bool) preg_match('/'.$this->rentedPattern().'/u', $value);
    }

    private function ownedPattern(): string
    {
        return '\b(?:'
            .'koop(?:woning|huis|appartement)?'
            .'|eigen\s+(?:woning|huis|appartement)'
            .'|in\s+eigendom'
            .'|eigendom'
            .'|wij\s+bezitten'
            .'|we\s+bezitten'
            .'|ons\s+eigen\s+huis'
            .')\b';
    }

    private function rentedPattern(): string
    {
        return '\b(?:'
            .'huur(?:woning|huis|appartement)?'
            .'|gehuurd'
            .'|wij\s+huren'
            .'|we\s+huren'
            .'|ik\s+huur'
            .'|van\s+de\s+verhuurder'
            .'|huurders?'
            .')\b';
    }

    private function prepare(string $raw): string
    {
        $value = mb_strtolower(trim($raw), 'UTF-8');
        $value = str_replace(['’', '‘', '´'], "'", $value);

        return (string) preg_replace('/\s+/u', ' ', $value);
    }

    private function originalCaseQuote(string $sourceText, string $normalizedMatch): string
    {
        $pattern = '/'.preg_quote($normalizedMatch, '/').'/iu';
        if (preg_match($pattern, $sourceText, $matches) === 1) {
            return $matches[0];
        }

        return $normalizedMatch;
    }
}
