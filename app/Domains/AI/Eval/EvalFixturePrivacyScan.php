<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

/**
 * Lightweight privacy check over eval fixture text (no import path).
 */
final class EvalFixturePrivacyScan
{
    /**
     * @return list<string>
     */
    public function hits(string $text): array
    {
        $hits = [];
        if (preg_match('/\b\d{4}\s?[A-Z]{2}\b/u', $text) === 1) {
            $hits[] = 'postcode';
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text) === 1) {
            $hits[] = 'email';
        }
        if (preg_match('/(\+?\d{1,3}[\s-]?)?\(?\d{2,4}\)?[\s-]?\d{3,4}[\s-]?\d{3,4}/', $text) === 1
            && preg_match('/\d{6,}/', $text) === 1) {
            $hits[] = 'telefoonpatroon';
        }
        if (preg_match('/\b([A-Z][a-z]+(?:straat|laan|weg|pad|singel|gracht|dijk))\s+\d{1,5}\b/u', $text) === 1) {
            $hits[] = 'straat+huisnummer';
        }

        return $hits;
    }
}
