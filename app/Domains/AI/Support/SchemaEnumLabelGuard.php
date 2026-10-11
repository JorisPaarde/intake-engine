<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\Intake\Support\FuseboxAssessmentLabels;

/**
 * Replaces raw schema enum literals in model prose with existing Dutch labels.
 *
 * Closed list of our own identifiers (Rule::in / PhotoContentAssessment /
 * PhotoSubject). Not meaning interpretation (ADR-0016): only token → label.
 * Ambiguous single English words (ok, yes, no, unknown, visible, high/medium/low)
 * are intentionally omitted to avoid false positives in Dutch installer copy.
 */
final class SchemaEnumLabelGuard
{
    /**
     * Schema tokens eligible for replacement, longest first.
     *
     * @return array<string, string>
     */
    public static function dutchLabelsByToken(): array
    {
        $labels = [
            'none_visible' => FuseboxAssessmentLabels::emptyModuleSpace('none_visible'),
            'one_phase' => FuseboxAssessmentLabels::phase('one_phase'),
            'three_phase' => FuseboxAssessmentLabels::phase('three_phase'),
            'needs_clearer' => FuseboxAssessmentLabels::contentAssessmentStatus('needs_clearer') ?? 'scherpere foto gewenst',
            'wrong_subject' => FuseboxAssessmentLabels::contentAssessmentStatus('wrong_subject') ?? 'verkeerd onderwerp',
            'not_assessed' => FuseboxAssessmentLabels::contentAssessmentStatus('not_assessed') ?? 'nog niet automatisch beoordeeld',
            'outdoor_location' => PhotoSubject::OutdoorLocation->dutchShortLabel(),
            'outdoor_unit' => PhotoSubject::OutdoorUnit->dutchShortLabel(),
            'pipe_route' => PhotoSubject::PipeRoute->dutchShortLabel(),
            'indoor_unit' => PhotoSubject::IndoorUnit->dutchShortLabel(),
            'needs_clearer_photo' => 'scherpere foto gewenst',
        ];

        uksort($labels, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $labels;
    }

    /**
     * @return list<string>
     */
    public static function guardedTokens(): array
    {
        return array_keys(self::dutchLabelsByToken());
    }

    /**
     * @return array{text: string, replaced: list<string>}
     */
    public function apply(string $text): array
    {
        $replaced = [];
        $out = $text;

        foreach (self::dutchLabelsByToken() as $token => $label) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($token, '/').'(?![A-Za-z0-9_])/u';
            $count = 0;
            $out = preg_replace($pattern, $label, $out, -1, $count) ?? $out;
            if ($count > 0) {
                $replaced[] = $token;
            }
        }

        return [
            'text' => $out,
            'replaced' => array_values(array_unique($replaced)),
        ];
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
