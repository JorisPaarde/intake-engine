<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Caps confidence and hedges wording of derived claims so they never sound
 * stronger than their source observations (e.g. "lijkt" → never "aanwezig" at high).
 *
 * Applies to dossier synthesis, attention points and AI summary highlights alike.
 */
final class DerivedClaimConfidenceGuard
{
    private const HEDGE_PATTERN = '/\b(lijkt|mogelijk|waarschijnlijk|vermoedelijk|niet\s+zeker|onduidelijk|te\s+controleren)\b/u';

    private const OVERCONFIDENT_FACT_PATTERN = '/\b(1[\s-]?fase|3[\s-]?fase|driefase|vrije\s+groep(en)?)\s+(aanwezig|is\s+aanwezig|vastgesteld|bevestigd|aanwezig\s+is)\b/iu';

    /**
     * @param  'low'|'medium'|'high'|null  $sourceCeiling
     * @param  'low'|'medium'|'high'  $claimed
     * @return 'low'|'medium'|'high'
     */
    public function capConfidence(string $claimed, ?string $sourceCeiling): string
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];
        if (! array_key_exists($claimed, $order)) {
            return 'low';
        }
        $claimedRank = $order[$claimed];
        if ($sourceCeiling === null || ! array_key_exists($sourceCeiling, $order)) {
            return $claimed;
        }
        $ceilingRank = $order[$sourceCeiling];

        return array_search(min($claimedRank, $ceilingRank), $order, true) ?: 'low';
    }

    public function textLooksHedged(string $text): bool
    {
        return (bool) preg_match(self::HEDGE_PATTERN, mb_strtolower($text));
    }

    public function claimsOverconfidentFact(string $text): bool
    {
        return (bool) preg_match(self::OVERCONFIDENT_FACT_PATTERN, $text);
    }

    /**
     * Mentions phase/electrical capacity without hedging language.
     */
    public function claimsUnequivocalElectricalFact(string $text): bool
    {
        $normalized = mb_strtolower($text);
        if ($this->textLooksHedged($normalized)) {
            return false;
        }

        return (bool) preg_match('/\b(1[\s-]?fase|3[\s-]?fase|driefase|vrije\s+groep(en)?)\b/u', $normalized)
            || $this->claimsOverconfidentFact($text);
    }

    /**
     * Rewrite hard factual electrical claims into hedged "lijkt / te controleren" form.
     */
    public function hedgeOverconfidentClaim(string $text): string
    {
        $hedged = preg_replace(
            self::OVERCONFIDENT_FACT_PATTERN,
            '$1 lijkt zichtbaar — te controleren',
            $text,
        );
        $result = trim(is_string($hedged) ? $hedged : $text);

        if (! $this->textLooksHedged($result)
            && (bool) preg_match('/\b(1[\s-]?fase|3[\s-]?fase|driefase)\b/u', mb_strtolower($result))) {
            $withPhaseHedge = preg_replace(
                '/\b(1[\s-]?fase|3[\s-]?fase|driefase)\b/iu',
                '$1 lijkt zichtbaar — te controleren',
                $result,
            );
            $result = trim(is_string($withPhaseHedge) ? $withPhaseHedge : $result);
        }

        if ($this->textLooksHedged($result)) {
            return $result;
        }

        $softened = preg_replace(
            '/\b(aanwezig|vastgesteld|bevestigd)\b/iu',
            'lijkt zichtbaar — te controleren',
            $result,
        );

        return trim(is_string($softened) ? $softened : $result);
    }

    /**
     * Highest confidence a derived claim may carry given source observation text.
     * Hedged source language → medium ceiling (never high).
     *
     * @return 'low'|'medium'|'high'|null null = no cap from this text alone
     */
    public function ceilingFromObservationText(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        if ($this->textLooksHedged($text)) {
            return 'medium';
        }

        return null;
    }

    /**
     * @param  'low'|'medium'|'high'|null  ...$ceilings
     * @return 'low'|'medium'|'high'|null
     */
    public function mergeCeilings(?string ...$ceilings): ?string
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];
        $lowest = null;
        foreach ($ceilings as $ceiling) {
            if ($ceiling === null || ! array_key_exists($ceiling, $order)) {
                continue;
            }
            if ($lowest === null || $order[$ceiling] < $order[$lowest]) {
                $lowest = $ceiling;
            }
        }

        return $lowest;
    }

    /**
     * Resolve a confidence ceiling from dossier-synthesis input (photo assessments,
     * external facts, legacy evidence strings).
     *
     * @param  array<string, mixed>  $input
     * @return 'low'|'medium'|'high'|null
     */
    public function ceilingFromSynthesisInput(array $input): ?string
    {
        $ceilings = [];

        foreach ($this->stringLeaves($input['image_manifest'] ?? null) as $text) {
            $ceilings[] = $this->ceilingFromObservationText($text);
        }

        $legacy = is_array($input['legacy_evidence'] ?? null) ? $input['legacy_evidence'] : [];
        foreach ($this->stringLeaves($legacy['external_fact_context'] ?? null) as $text) {
            $ceilings[] = $this->ceilingFromObservationText($text);
        }
        foreach ($this->stringLeaves($legacy['uploads'] ?? null) as $text) {
            $ceilings[] = $this->ceilingFromObservationText($text);
        }

        foreach ($this->stringLeaves($input['synthesis_policy'] ?? null) as $text) {
            $ceilings[] = $this->ceilingFromObservationText($text);
        }

        return $this->mergeCeilings(...$ceilings);
    }

    /**
     * Resolve ceiling from attention-points payload for the cited evidence refs.
     *
     * @param  list<array{source_type: string, reference: string}>  $evidence
     * @param  array<string, mixed>  $payload
     * @return 'low'|'medium'|'high'|null
     */
    public function ceilingFromAttentionEvidence(array $evidence, array $payload): ?string
    {
        $ceilings = [];
        $indexed = $this->indexAttentionEvidence($payload);

        foreach ($evidence as $item) {
            $key = $item['source_type'].'|'.$item['reference'];
            $texts = $indexed[$key] ?? [];
            foreach ($texts as $text) {
                $ceilings[] = $this->ceilingFromObservationText($text);
            }

            // Explicit confidence on the source fact caps derived claims.
            $sourceConfidence = $indexed[$key.'#confidence'][0] ?? null;
            if (is_string($sourceConfidence) && in_array($sourceConfidence, ['low', 'medium', 'high'], true)) {
                $ceilings[] = $sourceConfidence;
            }
        }

        return $this->mergeCeilings(...$ceilings);
    }

    /**
     * Apply hedge to a free-text derived claim when the source is soft or the
     * claim itself states an overconfident electrical fact.
     *
     * @param  'low'|'medium'|'high'|null  $sourceCeiling
     * @return array{text: string, hedged: bool}
     */
    public function normalizeDerivedText(string $text, ?string $sourceCeiling): array
    {
        $sourceIsSoft = $sourceCeiling !== null && $sourceCeiling !== 'high';
        $shouldHedge = $this->claimsOverconfidentFact($text)
            || ($sourceIsSoft && $this->claimsUnequivocalElectricalFact($text))
            || ($sourceIsSoft && ! $this->textLooksHedged($text)
                && (bool) preg_match('/\b(aanwezig|vastgesteld|bevestigd)\b/iu', $text));

        if (! $shouldHedge) {
            return ['text' => $text, 'hedged' => false];
        }

        $hedged = $this->hedgeOverconfidentClaim($text);

        return ['text' => $hedged, 'hedged' => $hedged !== $text];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function indexAttentionEvidence(array $payload): array
    {
        $index = [];

        $buckets = [
            'answer' => $payload['answer_context'] ?? [],
            'external_fact' => $payload['external_fact_context'] ?? [],
            'upload' => $payload['uploads'] ?? [],
            'follow_up' => [],
            'installer_review' => is_array($payload['installer_review'] ?? null)
                ? [$payload['installer_review']]
                : [],
            'pipe_route' => $payload['pipe_routes'] ?? [],
            'system_attention_point' => $payload['system_attention_points'] ?? [],
        ];

        foreach (is_array($payload['follow_up'] ?? null) ? $payload['follow_up'] : [] as $round) {
            if (! is_array($round)) {
                continue;
            }
            foreach (is_array($round['items'] ?? null) ? $round['items'] : [] as $item) {
                if (is_array($item)) {
                    $buckets['follow_up'][] = $item;
                }
            }
        }

        foreach ($buckets as $sourceType => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['reference'] ?? null)) {
                    continue;
                }
                $key = $sourceType.'|'.$row['reference'];
                $texts = [];
                foreach (['label', 'display', 'evidence', 'value', 'summary', 'prompt', 'note'] as $field) {
                    if (is_string($row[$field] ?? null) && trim((string) $row[$field]) !== '') {
                        $texts[] = (string) $row[$field];
                    }
                }
                if (is_array($row['value'] ?? null)) {
                    foreach ($this->stringLeaves($row['value']) as $leaf) {
                        $texts[] = $leaf;
                    }
                }
                $index[$key] = array_values(array_unique($texts));
                if (is_string($row['confidence'] ?? null)) {
                    $index[$key.'#confidence'] = [(string) $row['confidence']];
                }
            }
        }

        return $index;
    }

    /** @return list<string> */
    private function stringLeaves(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) === '' ? [] : [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            foreach ($this->stringLeaves($item) as $leaf) {
                $out[] = $leaf;
            }
        }

        return $out;
    }
}
