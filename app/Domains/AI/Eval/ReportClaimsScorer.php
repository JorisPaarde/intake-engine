<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Support\SchemaEnumLabelGuard;

/**
 * Measurement-only scorer for report_claims fixtures (eval harness, never product).
 *
 * Scoring choice (brief P1-5 rework): claim-level concepts with per-fixture
 * `claims[]` ({id, concept, allowed: assert|hedged_only|forbidden, terms[]}).
 * Units scored separately: summary sentences, each highlight, each attention
 * point (code + label). Hedge detection uses an eval-only word list (not
 * DerivedClaimConfidenceGuard / product paths). ADR-0013 / ADR-0016: this
 * code never runs in production interpretation.
 *
 * N20: also measures raw schema-enum leaks (`none_visible`, `needs_clearer`, …)
 * via {@see SchemaEnumLabelGuard}’s closed token list (measurement only here).
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
        'suggereert',
        'wijst op',
        'niet zeker',
        'onduidelijk',
        'indicatie',
        'te controleren',
        'controleer',
        'verifieer',
        'nagaan',
        'niet vast te stellen',
        'niet vastgesteld',
        'niet kunnen worden vastgesteld',
        'niet kan worden vastgesteld',
        'onbekend',
        'onzeker',
        'niet te bepalen',
        'zekerheid medium',
        'zekerheid laag',
        'zekerheid: medium',
        'zekerheid: laag',
        'gemiddelde zekerheid',
        'confidence medium',
        'confidence low',
    ];

    /**
     * "Cannot be determined" phrasing — never counts as a forbidden hard claim.
     *
     * @var list<string>
     */
    private const CANNOT_DETERMINE_MARKERS = [
        'niet vast te stellen',
        'niet vastgesteld',
        'niet kunnen worden vastgesteld',
        'niet kan worden vastgesteld',
        'onbekend',
        'niet te bepalen',
    ];

    /**
     * Extra check-instruction / to-be-done patterns treated as hedged.
     * Bare past "gecontroleerd" (already done) is NOT a hedge.
     *
     * @var list<string>
     */
    private const HEDGE_PATTERNS = [
        '/bevestiging.{0,48}(vereist|nodig)/iu',
        '/gecontroleerd te worden/iu',
        '/gecontroleerd worden/iu',
        '/\b(moet|moeten|dient|dienen)\b.{0,48}\bgecontroleerd\b/iu',
    ];

    /**
     * Named-photo attribution (hedged_only only when same layer also names conflict).
     *
     * @var list<string>
     */
    private const NAMED_PHOTO_ATTRIBUTION_PATTERNS = [
        '/\bde\s+(eerste|tweede|derde|\d+e)\s+meterkastfoto\b/iu',
        '/\bop\s+de\s+(eerste|tweede|derde|\d+e)\s+(meterkast)?foto\b/iu',
        '/\b(de\s+)?(eerste|tweede|derde|\d+e)\s+foto\s+(van\s+de\s+meterkast|toont|laat)\b/iu',
    ];

    /**
     * Observation verbs required for named-photo attribution to count as a hedge.
     *
     * @var list<string>
     */
    private const NAMED_PHOTO_OBSERVATION_MARKERS = [
        'toont',
        'zichtbaar',
        'te zien',
        'zien',
    ];

    /**
     * Conclusion / requirement wording — blocks named-photo observation hedge.
     *
     * @var list<string>
     */
    private const NAMED_PHOTO_CONCLUSION_MARKERS = [
        'uitbreiding nodig',
        'vereist',
        'noodzakelijk',
        'nodig',
        'moet',
    ];

    /**
     * Conflict / uncertainty-between-photos markers for the named-attribution rule.
     * Ordinal-only phrases (eerste/tweede foto) are attribution, not conflict.
     *
     * @var list<string>
     */
    private const PHOTO_CONFLICT_LAYER_TERMS = [
        'tegenstrijdig',
        'conflict',
        'wijken af',
        'verschil tussen',
        'wisselende beeldkwaliteit',
        'onvoldoende scherp',
        'niet goed genoeg',
        'onscherp',
        'scherpere',
        'retake',
        'onduidelijke foto',
        'niet scherp',
        'wazig',
        'beide foto',
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
            'onvoldoende scherp',
            'wisselende beeldkwaliteit',
            'onduidelijke foto',
            'niet scherp',
            'wazig',
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
     * @param  array{summary_rejection?: string|null, attention_rejection?: string|null, input_references?: list<string>}  $layerMeta
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
        $inputReferences = $this->normalizeInputReferences($layerMeta['input_references'] ?? null);

        $leakHits = $this->internalUploadReferenceHits($units, $inputReferences);
        $metaRefs = is_array($disputed['no_internal_upload_refs'] ?? null)
            ? $disputed['no_internal_upload_refs']
            : null;
        $out['no_internal_upload_refs'] = $this->factScorer->score(
            expected: true,
            got: $leakHits === [],
            supported: true,
            components: $components,
            disputed: $metaRefs !== null,
            disputedReason: is_string($metaRefs['reason'] ?? null) ? $metaRefs['reason'] : null,
        );
        $out['no_internal_upload_refs']['detail'] = $leakHits === []
            ? 'clean'
            : 'internal_upload_ref_in_text';
        $out['no_internal_upload_refs']['hits'] = $leakHits;

        $enumHits = $this->rawSchemaEnumHits($units);
        $metaEnums = is_array($disputed['no_raw_schema_enums'] ?? null)
            ? $disputed['no_raw_schema_enums']
            : null;
        $out['no_raw_schema_enums'] = $this->factScorer->score(
            expected: true,
            got: $enumHits === [],
            supported: true,
            components: $components,
            disputed: $metaEnums !== null,
            disputedReason: is_string($metaEnums['reason'] ?? null) ? $metaEnums['reason'] : null,
        );
        $out['no_raw_schema_enums']['detail'] = $enumHits === []
            ? 'clean'
            : 'raw_schema_enum_in_text';
        $out['no_raw_schema_enums']['hits'] = $enumHits;

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

        // Legacy invent-phase flag → phase_3/phase_1 hard claims on combined units
        // (cannot-determine phrasing does not count as inventing a phase).
        if ((bool) ($expected['must_not_invent_phase'] ?? false)) {
            $invented = $this->conceptPresentAsHardClaim('phase_3', self::DEFAULT_CONCEPT_TERMS['phase_3'], $units)
                || $this->conceptPresentAsHardClaim('phase_1', self::DEFAULT_CONCEPT_TERMS['phase_1'], $units);
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
            if ($this->markerPresent($lower, $marker)) {
                return true;
            }
        }
        foreach (self::HEDGE_PATTERNS as $pattern) {
            if (@preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public function textLooksCannotDetermine(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::CANNOT_DETERMINE_MARKERS as $marker) {
            if ($this->markerPresent($lower, $marker)) {
                return true;
            }
        }

        foreach (self::phaseAlternativePatterns() as $pattern) {
            if (@preg_match($pattern, $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * "X of Y" phase alternatives (eval-only). Used for cannot-determine and to
     * strip alternatives before judging remaining phase claims under hedged_only.
     *
     * @return list<string>
     */
    private static function phaseAlternativePatterns(): array
    {
        return [
            // 1-fase/3-fase (and reverse)
            '/\b1(-?\s?fasen?)?\s*(of|\/)\s*3-?\s?fasen?\b/iu',
            '/\b3(-?\s?fasen?)?\s*(of|\/)\s*1-?\s?fasen?\b/iu',
            // eenfasige/driefasige, enkelfasig/driefasig (and reverse)
            '/\b(een|enkel)fasig(e)?\s*(of|\/)\s*driefasig(e)?\b/iu',
            '/\bdriefasig(e)?\s*(of|\/)\s*(een|enkel)fasig(e)?\b/iu',
            // shortened: een- of driefasig / enkel- of driefasig (and reverse)
            '/\b(een|enkel)-?\s*of\s+driefasig(e)?\b/iu',
            '/\bdrie-?\s*of\s+(een|enkel)fasig(e)?\b/iu',
        ];
    }

    /**
     * Remove "X of Y" phase-alternative spans so remaining phase terms can be
     * judged on their own hedge (hedged_only phase claims).
     */
    public function stripPhaseAlternatives(string $clause): string
    {
        $out = $clause;
        foreach (self::phaseAlternativePatterns() as $pattern) {
            $out = preg_replace($pattern, ' ', $out) ?? $out;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $out) ?? $out;

        return trim($collapsed);
    }

    public function textLooksNamedPhotoAttribution(string $text): bool
    {
        foreach (self::NAMED_PHOTO_ATTRIBUTION_PATTERNS as $pattern) {
            if (@preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Named-photo hedge: attribution + observation verb, no conclusion word (same clause).
     */
    public function clauseLooksNamedPhotoObservation(string $clause): bool
    {
        if (! $this->textLooksNamedPhotoAttribution($clause)) {
            return false;
        }

        $lower = mb_strtolower($clause);
        $hasObservation = false;
        foreach (self::NAMED_PHOTO_OBSERVATION_MARKERS as $marker) {
            if ($this->markerPresent($lower, $marker)) {
                $hasObservation = true;
                break;
            }
        }
        if (! $hasObservation) {
            return false;
        }

        foreach (self::NAMED_PHOTO_CONCLUSION_MARKERS as $marker) {
            if ($this->markerPresent($lower, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function markerPresent(string $haystackLower, string $marker): bool
    {
        $marker = mb_strtolower($marker);
        if (str_contains($marker, ' ')) {
            return str_contains($haystackLower, $marker);
        }

        // Single-token markers need a word boundary at the start only
        // ("controleer" ∉ "gecontroleerd", but "indicatie" ∈ "indicatief").
        return @preg_match(
            '/(^|[^\p{L}\p{N}])'.preg_quote($marker, '/').'/u',
            $haystackLower,
        ) === 1;
    }

    /**
     * Split a unit for hedge / cannot-determine co-location.
     * Only sentence breaks (. ! ? newline) and ';' — commas and "maar" stay
     * in the same clause so a same-sentence caveat still hedges the claim.
     *
     * @return list<string>
     */
    public function splitClauses(string $text): array
    {
        $trimmedText = trim($text);
        if ($trimmedText === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:[.!?;]|\n+)\s*/u', $trimmedText) ?: [$trimmedText];

        $out = [];
        foreach ($parts as $part) {
            $trimmed = trim((string) $part);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out !== [] ? $out : [$trimmedText];
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
        $layerConflict = [
            'summary' => $this->layerNamesPhotoConflictOrUncertainty($units, ['summary_sentence', 'highlight']),
            'attention' => $this->layerNamesPhotoConflictOrUncertainty($units, ['attention']),
        ];

        // Match + hedge / cannot-determine per sentence/'; clause (.!?; / newline).
        /** @var list<array{kind: string, text: string, hedged: bool, cannot_determine: bool}> $matches */
        $matches = [];
        foreach ($units as $unit) {
            $layerKey = in_array($unit['kind'], ['summary_sentence', 'highlight'], true)
                ? 'summary'
                : 'attention';
            foreach ($this->splitClauses($unit['text']) as $clause) {
                if (! $this->clauseMatchesTerms($clause, $claim['terms'])) {
                    continue;
                }
                $hedged = $this->textLooksHedged($clause);
                if (! $hedged
                    && $claim['allowed'] === 'hedged_only'
                    && $this->clauseLooksNamedPhotoObservation($clause)
                    && $layerConflict[$layerKey]
                ) {
                    $hedged = true;
                }
                $matches[] = [
                    'kind' => $unit['kind'],
                    'text' => $clause,
                    'hedged' => $hedged,
                    'cannot_determine' => $this->textLooksCannotDetermine($clause),
                ];
            }
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
            // "Cannot be determined" only clears a match when marker is in the same clause.
            $hardMatches = [];
            foreach ($matches as $match) {
                if ($match['cannot_determine']) {
                    continue;
                }
                $hardMatches[] = $match;
            }
            if ($hardMatches === []) {
                return [
                    'expected' => false,
                    'got' => false,
                    'detail' => 'concept_absent_cannot_determine',
                    'matches' => $matches,
                ];
            }

            return [
                'expected' => false,
                'got' => true,
                'detail' => 'forbidden_concept_present',
                'matches' => $hardMatches,
            ];
        }

        if ($allowed === 'hedged_only') {
            $phaseConcept = in_array($claim['concept'], ['phase_3', 'phase_1'], true);
            $unhedged = [];
            $onlyAlternatives = true;
            foreach ($matches as $match) {
                if ($match['hedged']) {
                    $onlyAlternatives = false;

                    continue;
                }
                if ($phaseConcept) {
                    // Strip "X of Y" alternatives; judge any remaining phase term alone.
                    $remainder = $this->stripPhaseAlternatives($match['text']);
                    if (! $this->clauseMatchesTerms($remainder, $claim['terms'])) {
                        // Match was only both-options wording → OK.
                        continue;
                    }
                    $onlyAlternatives = false;
                    if ($this->textLooksHedged($remainder)) {
                        continue;
                    }
                    $unhedged[] = $match;

                    continue;
                }
                $onlyAlternatives = false;
                $unhedged[] = $match;
            }
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
                'detail' => $onlyAlternatives
                    ? 'all_matches_cannot_determine'
                    : 'all_matches_hedged',
                'matches' => $matches,
            ];
        }

        // allowed === assert: presence required.
        // photo_conflict: no over-hedge penalty (naming the conflict may sound soft).
        if ($claim['concept'] === 'photo_conflict') {
            return [
                'expected' => true,
                'got' => true,
                'detail' => 'assert_ok_photo_conflict',
                'matches' => $matches,
            ];
        }

        // Required claims: check summary and attention separately so an
        // over-hedged layer fails even when the other layer asserts firmly.
        $summaryMatches = array_values(array_filter(
            $matches,
            static fn (array $m): bool => in_array($m['kind'], ['summary_sentence', 'highlight'], true),
        ));
        if ($summaryMatches !== []) {
            $summaryUnhedged = array_values(array_filter(
                $summaryMatches,
                static fn (array $m): bool => ! $m['hedged'],
            ));
            if ($summaryUnhedged === []) {
                return [
                    'expected' => true,
                    'got' => false,
                    'detail' => 'unnecessary_hedging_for_assert_on_summary',
                    'matches' => $matches,
                ];
            }
        }

        $attentionMatches = array_values(array_filter(
            $matches,
            static fn (array $m): bool => $m['kind'] === 'attention',
        ));
        if ($attentionMatches !== []) {
            $attentionUnhedged = array_values(array_filter(
                $attentionMatches,
                static fn (array $m): bool => ! $m['hedged'],
            ));
            if ($attentionUnhedged === []) {
                return [
                    'expected' => true,
                    'got' => false,
                    'detail' => 'unnecessary_hedging_for_assert_on_attention',
                    'matches' => $matches,
                ];
            }
        }

        $unhedged = array_values(array_filter($matches, static fn (array $m): bool => ! $m['hedged']));
        $hedgedOnly = array_values(array_filter($matches, static fn (array $m): bool => $m['hedged']));
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
     * Human-facing text must not leak raw schema enum tokens (eval measurement).
     * Uses the same closed token list as {@see SchemaEnumLabelGuard}.
     * Does not treat prompt-wording "retake" as an enum (it is not one).
     *
     * @param  list<array{kind: string, text: string, code?: string}>  $units
     * @return list<array{kind: string, text: string, token: string}>
     */
    private function rawSchemaEnumHits(array $units): array
    {
        $guard = new SchemaEnumLabelGuard;
        $hits = [];
        foreach ($units as $unit) {
            // Attention units concatenate code + label; score label-only for enums.
            $text = $unit['kind'] === 'attention' && isset($unit['code'])
                ? trim(preg_replace(
                    '/^'.preg_quote($unit['code'], '/').'\s*/u',
                    '',
                    $unit['text'],
                ) ?? $unit['text'])
                : $unit['text'];
            foreach ($guard->findRawHits($text) as $token) {
                $hits[] = [
                    'kind' => $unit['kind'],
                    'text' => $unit['text'],
                    'token' => $token,
                ];
            }
        }

        return $hits;
    }

    /**
     * Human-facing text must not leak input upload/fact references (eval-only).
     * Matches full references and opaque tokens after @upload: / @fact:.
     * Codes like upload_quality_low are fine when not an input opaque token.
     *
     * @param  list<array{kind: string, text: string, code?: string}>  $units
     * @param  list<string>  $inputReferences
     * @return list<array{kind: string, text: string, reference: string}>
     */
    private function internalUploadReferenceHits(array $units, array $inputReferences): array
    {
        if ($inputReferences === []) {
            return [];
        }

        $hits = [];
        foreach ($units as $unit) {
            foreach ($inputReferences as $reference) {
                if ($reference === '' || ! $this->textContainsInputReference($unit['text'], $reference)) {
                    continue;
                }
                $hits[] = [
                    'kind' => $unit['kind'],
                    'text' => $unit['text'],
                    'reference' => $reference,
                ];
            }
        }

        return $hits;
    }

    public function textContainsInputReference(string $text, string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        // Full reference: substring is enough (includes @upload: / @fact: forms).
        if (str_contains($reference, '@')) {
            return str_contains($text, $reference);
        }

        // Opaque token: require a non-word boundary so upload_quality_low ≠ upload_blurry.
        return @preg_match(
            '/(^|[^\p{L}\p{N}_])'.preg_quote($reference, '/').'([^\p{L}\p{N}_]|$)/u',
            $text,
        ) === 1;
    }

    /**
     * @param  list<string>|mixed  $raw
     * @return list<string>
     */
    private function normalizeInputReferences(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }
            $out[] = $trimmed;
            if (@preg_match_all('/@(?:upload|fact):([A-Za-z0-9_-]+)/u', $trimmed, $refMatches) > 0) {
                foreach ($refMatches[1] as $opaque) {
                    $out[] = $opaque;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<array{kind: string, text: string, code?: string}>  $units
     * @param  list<string>  $kinds
     */
    private function layerNamesPhotoConflictOrUncertainty(array $units, array $kinds): bool
    {
        $layerUnits = array_values(array_filter(
            $units,
            static fn (array $u): bool => in_array($u['kind'], $kinds, true),
        ));

        return $this->conceptPresentInUnits(
            'photo_conflict_layer',
            self::PHOTO_CONFLICT_LAYER_TERMS,
            $layerUnits,
        );
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
     * @param  list<string>  $terms
     * @param  list<array{kind: string, text: string, code?: string}>  $units
     */
    private function conceptPresentAsHardClaim(string $concept, array $terms, array $units): bool
    {
        foreach ($units as $unit) {
            foreach ($this->splitClauses($unit['text']) as $clause) {
                if (! $this->clauseMatchesTerms($clause, $terms)) {
                    continue;
                }
                if ($this->textLooksCannotDetermine($clause)) {
                    continue;
                }

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
        return $this->clauseMatchesTerms($unit['text'], $terms);
    }

    /**
     * @param  list<string>  $terms
     */
    private function clauseMatchesTerms(string $clause, array $terms): bool
    {
        $hay = mb_strtolower($clause);
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
