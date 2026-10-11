<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Support\DerivedClaimConfidenceGuard;

/**
 * Measurement-only scorer for report_claims fixtures (eval harness, never product).
 *
 * Scoring choice (brief P1-5): option (a) — per-fixture `must_not_assert` /
 * `must_hedge_phase_claims` / structured confidence checks. No LLM-judge.
 * Phrase lists live only in fixtures under tests/Eval; they do not interpret
 * free text in production paths (ADR-0013 / ADR-0016).
 */
final class ReportClaimsScorer
{
    public function __construct(
        private readonly FactScorer $factScorer,
        private readonly DerivedClaimConfidenceGuard $claimGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $expected
     * @param  array{summary: string, highlights: list<string>}|null  $summary
     * @param  list<array{code: string, label: string, confidence: string|null, evidence: list<array{source_type: string, reference: string}>}>|null  $points
     * @param  array<string, mixed>  $disputed
     * @param  list<string>  $components
     * @return array<string, array<string, mixed>>
     */
    public function scoreLayer(
        array $expected,
        ?array $summary,
        ?array $points,
        array $disputed,
        array $components,
    ): array {
        $out = [];
        $summaryCorpus = $this->summaryCorpus($summary);
        $attentionCorpus = $this->attentionCorpus($points);
        $combinedCorpus = trim($summaryCorpus.' '.$attentionCorpus);

        $mustNot = is_array($expected['must_not_assert'] ?? null)
            ? array_values(array_filter(
                $expected['must_not_assert'],
                static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
            ))
            : [];

        foreach ($mustNot as $index => $phrase) {
            $factKey = 'must_not_assert.'.$index;
            $found = $this->containsPhrase($combinedCorpus, $phrase);
            $meta = is_array($disputed[$factKey] ?? null) ? $disputed[$factKey] : null;
            $out[$factKey] = $this->factScorer->score(
                expected: false,
                got: $found,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
            $out[$factKey]['phrase'] = $phrase;
        }

        $mustHedge = (bool) ($expected['must_hedge_phase_claims'] ?? false);
        $allowUnhedged = (bool) ($expected['allow_unhedged_three_phase'] ?? false);
        if ($mustHedge || ! $allowUnhedged) {
            $unequivocal = $summary !== null && (
                $this->claimGuard->claimsUnequivocalElectricalFact($summary['summary'])
                || $this->anyUnequivocalHighlight($summary['highlights'])
            );
            $meta = is_array($disputed['summary_phase_hedge'] ?? null) ? $disputed['summary_phase_hedge'] : null;
            $out['summary_phase_hedge'] = $this->factScorer->score(
                expected: false,
                got: $unequivocal,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
        } elseif ($allowUnhedged) {
            // Control: stating three-phase is allowed; score presence of any phase mention as optional pass.
            $mentionsPhase = $this->mentionsPhase($summaryCorpus);
            $meta = is_array($disputed['summary_may_state_phase'] ?? null) ? $disputed['summary_may_state_phase'] : null;
            $out['summary_may_state_phase'] = $this->factScorer->score(
                expected: true,
                got: $mentionsPhase,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
        }

        $ceiling = $expected['attention_confidence_ceiling'] ?? null;
        if (is_string($ceiling) && in_array($ceiling, ['low', 'medium', 'high'], true) && is_array($points)) {
            $powerPoints = $this->powerRelatedPoints($points);
            $maxRank = $this->maxConfidenceRank($powerPoints);
            $ceilingRank = $this->confidenceRank($ceiling);
            $within = $maxRank === null || $maxRank <= $ceilingRank;
            $meta = is_array($disputed['attention_confidence_ceiling'] ?? null)
                ? $disputed['attention_confidence_ceiling']
                : null;
            $out['attention_confidence_ceiling'] = $this->factScorer->score(
                expected: true,
                got: $within,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
            $out['attention_confidence_ceiling']['ceiling'] = $ceiling;
            $out['attention_confidence_ceiling']['max_observed'] = $this->rankToConfidence($maxRank);
        }

        $forbidCodes = is_array($expected['forbid_attention_codes'] ?? null)
            ? array_values(array_filter(
                $expected['forbid_attention_codes'],
                static fn (mixed $v): bool => is_string($v) && $v !== '',
            ))
            : [];
        if ($forbidCodes !== [] && is_array($points)) {
            $codes = array_map(
                static fn (array $p): string => $p['code'],
                $points,
            );
            foreach ($forbidCodes as $index => $code) {
                $factKey = 'forbid_attention_code.'.$index;
                $present = in_array($code, $codes, true);
                $meta = is_array($disputed[$factKey] ?? null) ? $disputed[$factKey] : null;
                $out[$factKey] = $this->factScorer->score(
                    expected: false,
                    got: $present,
                    supported: true,
                    components: $components,
                    disputed: $meta !== null,
                    disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
                );
                $out[$factKey]['code'] = $code;
            }
        }

        if ((bool) ($expected['require_evidence_on_points'] ?? false) && is_array($points)) {
            $allHaveEvidence = true;
            foreach ($points as $point) {
                if ($point['evidence'] === []) {
                    $allHaveEvidence = false;
                    break;
                }
            }
            // Empty points list vacuously satisfies evidence requirement.
            $meta = is_array($disputed['attention_evidence'] ?? null) ? $disputed['attention_evidence'] : null;
            $out['attention_evidence'] = $this->factScorer->score(
                expected: true,
                got: $allHaveEvidence,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
        }

        if ((bool) ($expected['require_photo_conflict_signal'] ?? false)) {
            $signals = is_array($expected['conflict_signal_substrings'] ?? null)
                ? array_values(array_filter(
                    $expected['conflict_signal_substrings'],
                    static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
                ))
                : ['conflict', 'tegenstrijdig', 'foto 1', 'foto 2', 'eerste foto', 'tweede foto', 'onscherp', 'niet goed genoeg', 'retake', 'opnieuw'];
            $hit = false;
            foreach ($signals as $signal) {
                if ($this->containsPhrase($combinedCorpus, $signal)) {
                    $hit = true;
                    break;
                }
            }
            $meta = is_array($disputed['photo_conflict_signal'] ?? null) ? $disputed['photo_conflict_signal'] : null;
            $out['photo_conflict_signal'] = $this->factScorer->score(
                expected: true,
                got: $hit,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
        }

        if ((bool) ($expected['must_not_invent_phase'] ?? false)) {
            $invented = $this->mentionsPhase($combinedCorpus);
            $meta = is_array($disputed['must_not_invent_phase'] ?? null) ? $disputed['must_not_invent_phase'] : null;
            $out['must_not_invent_phase'] = $this->factScorer->score(
                expected: false,
                got: $invented,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
        }

        return $out;
    }

    /**
     * @param  array{summary: string, highlights: list<string>}|null  $summary
     */
    private function summaryCorpus(?array $summary): string
    {
        if ($summary === null) {
            return '';
        }

        return trim($summary['summary'].' '.implode(' ', $summary['highlights']));
    }

    /**
     * @param  list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>|null  $points
     */
    private function attentionCorpus(?array $points): string
    {
        if ($points === null) {
            return '';
        }

        $parts = [];
        foreach ($points as $point) {
            $parts[] = $point['label'];
            $parts[] = $point['code'];
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param  list<string>  $highlights
     */
    private function anyUnequivocalHighlight(array $highlights): bool
    {
        foreach ($highlights as $highlight) {
            if ($this->claimGuard->claimsUnequivocalElectricalFact($highlight)) {
                return true;
            }
        }

        return false;
    }

    private function containsPhrase(string $corpus, string $phrase): bool
    {
        $hay = mb_strtolower($corpus);
        $needle = mb_strtolower(trim($phrase));

        return $needle !== '' && str_contains($hay, $needle);
    }

    private function mentionsPhase(string $corpus): bool
    {
        $lower = mb_strtolower($corpus);

        return str_contains($lower, '3-fase')
            || str_contains($lower, '3-fasen')
            || str_contains($lower, 'driefase')
            || str_contains($lower, 'drie fase')
            || str_contains($lower, '1-fase')
            || str_contains($lower, '1-fasen')
            || str_contains($lower, 'eenfase');
    }

    /**
     * @param  list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>  $points
     * @return list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>
     */
    private function powerRelatedPoints(array $points): array
    {
        $out = [];
        foreach ($points as $point) {
            $blob = mb_strtolower($point['code'].' '.$point['label']);
            if (
                str_contains($blob, 'fase')
                || str_contains($blob, 'meterkast')
                || str_contains($blob, 'groepenkast')
                || str_contains($blob, 'groep')
                || str_contains($blob, 'stroom')
                || str_contains($blob, 'fusebox')
                || str_contains($blob, 'power')
                || str_contains($blob, 'uitbreiding')
                || str_contains($blob, 'module')
            ) {
                $out[] = $point;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{confidence: string|null}>  $points
     */
    private function maxConfidenceRank(array $points): ?int
    {
        $max = null;
        foreach ($points as $point) {
            $confidence = $point['confidence'] ?? null;
            if (! is_string($confidence)) {
                continue;
            }
            $rank = $this->confidenceRank($confidence);
            if ($max === null || $rank > $max) {
                $max = $rank;
            }
        }

        return $max;
    }

    private function confidenceRank(string $confidence): int
    {
        return match ($confidence) {
            'high' => 2,
            'medium' => 1,
            default => 0,
        };
    }

    private function rankToConfidence(?int $rank): ?string
    {
        return match ($rank) {
            2 => 'high',
            1 => 'medium',
            0 => 'low',
            default => null,
        };
    }
}
