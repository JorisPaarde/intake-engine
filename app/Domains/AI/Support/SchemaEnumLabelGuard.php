<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Measure-only detection of raw schema enum literals in model prose (N20).
 *
 * Closed list of our own identifiers (Rule::in / PhotoContentAssessment /
 * PhotoSubject). Used by the eval scorer (`no_raw_schema_enums`) and
 * ReportClaimsCaseRunner guard_meta — never rewrites product text (ADR-0016).
 * Ambiguous single English words (ok, yes, no, unknown, visible, high/medium/low)
 * are intentionally omitted to avoid false positives in Dutch installer copy.
 * `retake` is not a schema enum (prompt wording only).
 */
final class SchemaEnumLabelGuard
{
    /**
     * Schema tokens scanned for leaks, longest first.
     *
     * @return list<string>
     */
    public static function guardedTokens(): array
    {
        $tokens = [
            'none_visible',
            'one_phase',
            'three_phase',
            'needs_clearer',
            'wrong_subject',
            'not_assessed',
            'outdoor_location',
            'outdoor_unit',
            'pipe_route',
            'indoor_unit',
            'needs_clearer_photo',
        ];

        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $tokens;
    }

    /**
     * @return list<string>
     */
    public function findRawHits(string $text): array
    {
        $hits = [];
        foreach (self::guardedTokens() as $token) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($token, '/').'(?![A-Za-z0-9_])/u';
            if (@preg_match($pattern, $text) === 1) {
                $hits[] = $token;
            }
        }

        return $hits;
    }
}
