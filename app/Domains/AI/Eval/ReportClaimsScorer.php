<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

/**
 * Measurement-only scorer for report_claims fixtures (eval harness, never product).
 *
 * Scoring choice (brief P1-5 rework): claim-level concepts with per-fixture
 * `claims[]` ({id, concept, allowed: assert|hedged_only|forbidden, terms[]}).
 * Units scored separately: summary sentences, each highlight, each attention
 * point (code + label). Hedge detection uses an eval-only word list (not
 * DerivedClaimConfidenceGuard / product paths). ADR-0013 / ADR-0016: this
 * code never runs in production interpretation.
 */
final class ReportClaimsScorer
{
    /**
     * Eval-only hedge markers (measurement). Includes "oogt" so soft capacity
     * wording is still detectable as hedged — forbidden claims still fail.
     *
     * @var list<string>
     */
    private const HEDGE_MARKERS = [
        'lijkt',
        'oogt',
        'mogelijk',
        'waarschijnlijk',
        'vermoedelijk',
        'niet zeker',
        'onduidelijk',
        'indicatie',
        'te controleren',
        'zekerheid medium',
        'zekerheid laag',
        'zekerheid: medium',
        'zekerheid: laag',
        'confidence medium',
        'confidence low',
    ];

    /**
     * Default synonym sets per concept (fixtures may override via `terms`).
     *
     * @var array<string, list<string>>
     */
    private const DEFAULT_CONCEPT_TERMS = [
        'phase_3' => [
            'driefas',
            '3-fase',
            '3-fasen',
            '3 fase',
            '3 fasen',
            'drie fasen',
            'drie-fase',
            'krachtstroom',
        ],
        'phase_1' => [
            '1-fase',
            '1-fasen',
            '1 fase',
            '1 fasen',
            'eenfase',
            'een-fase',
            'enkelfase',
        ],
        'capacity_full' => [
            'volledig bezet',
            'vol bezet',
            'geen vrije',
            'geen ruimte',
            'geen moduleplek',
            'geen moduleplaats',
            'geen module',
            '/uitbreiding (is )?(nodig|vereist)/iu',
            'uitbreiding vereist',
            'uitbreiding nodig',
            'fusebox_full',
            'no_free_group',
            'expansion_required',
        ],
        'capacity_free' => [
            'vrije groep',
            'vrije module',
            'lege module',
            'moduleplek zichtbaar',
            'ruimte in de groepenkast',
        ],
        'photo_conflict' => [
            'tegenstrijdig',
            'conflict',
            'foto 1',
            'foto 2',
            'eerste foto',
            'tweede foto',
            'niet goed genoeg',
            'onscherp',
            'scherpere',
            'opnieuw',
            'retake',
            'verschil tussen',
            'wijken af',
        ],
    ];

    public function __construct(
        private readonly FactScorer $factScorer,
    ) {}

    /**
     * @param  array<string, mixed>  $expected
     * @param  array{summary: string, highlights: list<string>}|null  $summary
     * @param  list<array{code: string, label: string, confidence: string|null, evidence: list<array{source_type: string, reference: string}>}>|null  $points
     * @param  array<string, mixed>  $disputed
     * @param  list<string>  $components
     * @param  array{summary_rejection?: string|null, attention_rejection?: string|null}  $layerMeta
     * @return array<string, array<string, mixed>>
     */
    public function scoreLayer(
        array $expected,
        ?array $summary,
        ?array $points,
        array $disputed,
        array $components,
        array $layerMeta = [],
    ): array {
        $out = [];

        $summaryRejection = is_string($layerMeta['summary_rejection'] ?? null)
            ? $layerMeta['summary_rejection']
            : null;
        $attentionRejection = is_string($layerMeta['attention_rejection'] ?? null)
            ? $layerMeta['attention_rejection']
            : null;

        $summaryEmpty = $summary === null
            || trim($summary['summary']) === ''
            || ($summaryRejection !== null);
        $attentionEmpty = $points === null || $attentionRejection !== null;

        $out['summary_output'] = $this->factScorer->score(
            expected: true,
            got: ! $summaryEmpty,
            supported: true,
            components: $components,
        );
        $out['summary_output']['rejection'] = $summaryRejection
            ?? ($summary === null ? 'missing_or_invalid_summary' : ($summaryEmpty ? 'empty_summary' : null));

        $out['attention_output'] = $this->factScorer->score(
            expected: true,
            got: ! $attentionEmpty,
            supported: true,
            components: $components,
        );
        $out['attention_output']['rejection'] = $attentionRejection
            ?? ($points === null ? 'missing_or_rejected_attention_points' : null);

        // Empty / rejected layers: do not auto-pass claim checks — mark unsupported.
        if ($summaryEmpty && $attentionEmpty) {
            foreach ($this->claimsFromExpected($expected) as $claim) {
                $factKey = 'claim.'.$claim['id'];
                $meta = is_array($disputed[$factKey] ?? null) ? $disputed[$factKey] : null;
                $out[$factKey] = $this->factScorer->score(
                    expected: null,
                    got: null,
                    supported: false,
                    components: $components,
                    disputed: $meta !== null,
                    disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : 'layer_empty',
                );
                $out[$factKey]['claim'] = $claim;
                $out[$factKey]['detail'] = 'skipped_empty_layer';
            }

            return $out;
        }

        $units = $this->buildUnits($summaryEmpty ? null : $summary, $attentionEmpty ? null : $points);

        foreach ($this->claimsFromExpected($expected) as $claim) {
            $factKey = 'claim.'.$claim['id'];
            $meta = is_array($disputed[$factKey] ?? null) ? $disputed[$factKey] : null;
            $evaluation = $this->evaluateClaim($claim, $units);
            $out[$factKey] = $this->factScorer->score(
                expected: $evaluation['expected'],
                got: $evaluation['got'],
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
            );
            $out[$factKey]['claim'] = $claim;
            $out[$factKey]['detail'] = $evaluation['detail'];
            $out[$factKey]['matches'] = $evaluation['matches'];
        }

        // Legacy invent-phase flag → phase_3/phase_1 forbidden on combined units.
        if ((bool) ($expected['must_not_invent_phase'] ?? false)) {
            $invented = $this->conceptPresentInUnits('phase_3', self::DEFAULT_CONCEPT_TERMS['phase_3'], $units)
                || $this->conceptPresentInUnits('phase_1', self::DEFAULT_CONCEPT_TERMS['phase_1'], $units);
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

        $ceiling = $expected['attention_confidence_ceiling'] ?? null;
        if (is_string($ceiling) && in_array($ceiling, ['low', 'medium', 'high'], true) && is_array($points)) {
            $powerPoints = $this->powerRelatedPoints($points);
            $maxRank = $this->maxConfidenceRank($powerPoints);
            $within = $maxRank === null || $maxRank <= $this->confidenceRank($ceiling);
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

        if ((bool) ($expected['require_evidence_on_points'] ?? false) && is_array($points)) {
            $allHaveEvidence = true;
            foreach ($points as $point) {
                if ($point['evidence'] === []) {
                    $allHaveEvidence = false;
                    break;
                }
            }
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

        return $out;
    }

    /**
     * Public helper for tests: does corpus mention phase_3 / phase_1 via concept terms?
     */
    public function mentionsPhase(string $corpus): bool
    {
        $unit = ['kind' => 'text', 'text' => $corpus];

        return $this->unitMatchesTerms($unit, self::DEFAULT_CONCEPT_TERMS['phase_3'])
            || $this->unitMatchesTerms($unit, self::DEFAULT_CONCEPT_TERMS['phase_1']);
    }

    public function textLooksHedged(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::HEDGE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<array{id: string, concept: string, allowed: string, terms: list<string>}>
     */
    private function claimsFromExpected(array $expected): array
    {
        $raw = is_array($expected['claims'] ?? null) ? $expected['claims'] : [];
        $claims = [];
        foreach ($raw as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $id = is_string($claim['id'] ?? null) ? $claim['id'] : null;
            $concept = is_string($claim['concept'] ?? null) ? $claim['concept'] : $id;
            $allowed = is_string($claim['allowed'] ?? null) ? $claim['allowed'] : null;
            if ($id === null || $concept === null || $allowed === null) {
                continue;
            }
            if (! in_array($allowed, ['assert', 'hedged_only', 'forbidden'], true)) {
                continue;
            }
            $terms = is_array($claim['terms'] ?? null)
                ? array_values(array_filter(
                    $claim['terms'],
                    static fn (mixed $t): bool => is_string($t) && trim($t) !== '',
                ))
                : (self::DEFAULT_CONCEPT_TERMS[$concept] ?? []);
            if ($terms === [] && isset(self::DEFAULT_CONCEPT_TERMS[$concept])) {
                $terms = self::DEFAULT_CONCEPT_TERMS[$concept];
            }
            $claims[] = [
                'id' => $id,
                'concept' => $concept,
                'allowed' => $allowed,
                'terms' => $terms,
            ];
        }

        return $claims;
    }

    /**
     * @param  array{summary: string, highlights: list<string>}|null  $summary
     * @param  list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>|null  $points
     * @return list<array{kind: string, text: string, code?: string}>
     */
    private function buildUnits(?array $summary, ?array $points): array
    {
        $units = [];
        if ($summary !== null) {
            foreach ($this->splitSentences($summary['summary']) as $sentence) {
                $units[] = ['kind' => 'summary_sentence', 'text' => $sentence];
            }
            foreach ($summary['highlights'] as $highlight) {
                $units[] = ['kind' => 'highlight', 'text' => $highlight];
            }
        }
        if ($points !== null) {
            foreach ($points as $point) {
                $units[] = [
                    'kind' => 'attention',
                    'text' => trim($point['code'].' '.$point['label']),
                    'code' => $point['code'],
                ];
            }
        }

        return $units;
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\n+/u', trim($text)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out === [] && trim($text) !== '' ? [trim($text)] : $out;
    }

    /**
     * @param  array{id: string, concept: string, allowed: string, terms: list<string>}  $claim
     * @param  list<array{kind: string, text: string, code?: string}>  $units
     * @return array{expected: mixed, got: mixed, detail: string, matches: list<array<string, mixed>>}
     */
    private function evaluateClaim(array $claim, array $units): array
    {
        $matches = [];
        foreach ($units as $unit) {
            if (! $this->unitMatchesTerms($unit, $claim['terms'])) {
                continue;
            }
            $hedged = $this->textLooksHedged($unit['text']);
            $matches[] = [
                'kind' => $unit['kind'],
                'text' => $unit['text'],
                'hedged' => $hedged,
            ];
        }

        $allowed = $claim['allowed'];

        if ($matches === []) {
            if ($allowed === 'assert') {
                return [
                    'expected' => true,
                    'got' => false,
                    'detail' => 'assert_concept_missing',
                    'matches' => [],
                ];
            }

            // forbidden / hedged_only: absence is fine
            return [
                'expected' => false,
                'got' => false,
                'detail' => 'concept_absent',
                'matches' => [],
            ];
        }

        if ($allowed === 'forbidden') {
            return [
                'expected' => false,
                'got' => true,
                'detail' => 'forbidden_concept_present',
                'matches' => $matches,
            ];
        }

        if ($allowed === 'hedged_only') {
            $unhedged = array_values(array_filter($matches, static fn (array $m): bool => ! $m['hedged']));
            if ($unhedged !== []) {
                return [
                    'expected' => false,
                    'got' => true,
                    'detail' => 'unhedged_claim_for_hedged_only',
                    'matches' => $matches,
                ];
            }

            return [
                'expected' => false,
                'got' => false,
                'detail' => 'all_matches_hedged',
                'matches' => $matches,
            ];
        }

        // allowed === assert: presence required; unnecessary hedging fails.
        $hedgedOnly = array_values(array_filter($matches, static fn (array $m): bool => $m['hedged']));
        $unhedged = array_values(array_filter($matches, static fn (array $m): bool => ! $m['hedged']));
        if ($unhedged === [] && $hedgedOnly !== []) {
            return [
                'expected' => true,
                'got' => false,
                'detail' => 'unnecessary_hedging_for_assert',
                'matches' => $matches,
            ];
        }

        return [
            'expected' => true,
            'got' => true,
            'detail' => 'assert_ok',
            'matches' => $matches,
        ];
    }

    /**
     * @param  list<string>  $terms
     * @param  list<array{kind: string, text: string, code?: string}>  $units
     */
    private function conceptPresentInUnits(string $concept, array $terms, array $units): bool
    {
        foreach ($units as $unit) {
            if ($this->unitMatchesTerms($unit, $terms)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{kind: string, text: string, code?: string}  $unit
     * @param  list<string>  $terms
     */
    private function unitMatchesTerms(array $unit, array $terms): bool
    {
        $hay = mb_strtolower($unit['text']);
        foreach ($terms as $term) {
            if ($this->termMatches($hay, $term)) {
                return true;
            }
        }

        return false;
    }

    private function termMatches(string $haystackLower, string $term): bool
    {
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        if (str_starts_with($term, '/') && substr_count($term, '/') >= 2) {
            $last = (int) strrpos($term, '/');
            $pattern = substr($term, 0, $last + 1);
            $modifiers = substr($term, $last + 1);
            if (! str_contains($modifiers, 'i')) {
                $modifiers .= 'i';
            }
            if (! str_contains($modifiers, 'u')) {
                $modifiers .= 'u';
            }

            return @preg_match($pattern.$modifiers, $haystackLower) === 1;
        }

        $needle = mb_strtolower($term);
        // Short tokens: prefer word-ish boundary to avoid accidental hits.
        if (mb_strlen($needle) <= 3) {
            return @preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($needle, '/').'([^\p{L}\p{N}]|$)/u', $haystackLower) === 1;
        }

        return str_contains($haystackLower, $needle);
    }

    /**
     * @param  list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>  $points
     * @return list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>
     */
    private function powerRelatedPoints(array $points): array
    {
        $out = [];
        $terms = array_merge(
            self::DEFAULT_CONCEPT_TERMS['phase_3'],
            self::DEFAULT_CONCEPT_TERMS['phase_1'],
            self::DEFAULT_CONCEPT_TERMS['capacity_full'],
            self::DEFAULT_CONCEPT_TERMS['capacity_free'],
            ['meterkast', 'groepenkast', 'stroom', 'fusebox', 'power'],
        );
        foreach ($points as $point) {
            $unit = ['kind' => 'attention', 'text' => $point['code'].' '.$point['label']];
            if ($this->unitMatchesTerms($unit, $terms)) {
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
