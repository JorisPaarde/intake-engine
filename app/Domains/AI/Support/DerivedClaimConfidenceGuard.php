<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Caps confidence and hedges wording of derived claims so they never sound
 * stronger than their source observations (e.g. "lijkt" → never "aanwezig" at high).
 *
 * Applies to dossier synthesis, attention points, AI summary / report copy
 * ("AI-voorstel (niet bindend)") and assistant summaries alike.
 */
final class DerivedClaimConfidenceGuard
{
    private const HEDGE_PATTERN = '/\b(lijkt|mogelijk|waarschijnlijk|vermoedelijk|niet\s+zeker|onduidelijk|te\s+controleren)\b/u';

    /**
     * Matches 1-/3-fase(n) and free-group mentions, including Dutch plural "fasen".
     */
    private const PHASE_OR_GROUP_PATTERN = '/\b(1[\s-]?fasen?|3[\s-]?fasen?|driefasen?|vrije\s+groep(?:en)?)\b/u';

    /**
     * Hard phase mention only (not free-group check instructions).
     */
    private const PHASE_PATTERN = '/\b(1[\s-]?fasen?|3[\s-]?fasen?|driefasen?)\b/u';

    /**
     * Hard factual phrasing: phase/group + certainty verb, optionally with words in between
     * (e.g. "3-fase aansluiting aanwezig", "voorzien van een 3-fasen hoofdschakelaar").
     */
    private const OVERCONFIDENT_FACT_PATTERN = '/\b(1[\s-]?fasen?|3[\s-]?fasen?|driefasen?|vrije\s+groep(?:en)?)((?:\s+\w+){0,6})\s+(?:aanwezig|is\s+aanwezig|vastgesteld|bevestigd|aanwezig\s+is)\b/iu';

    /**
     * "voorzien van / uitgevoerd met … fase" — positive electrical claim without
     * the presence verb that OVERCONFIDENT_FACT_PATTERN requires.
     */
    private const OVERCONFIDENT_CONSTRUCTION_PATTERN = '/\b(?:is\s+)?(?:uitgevoerd\s+met|voorzien\s+van(?:\s+een)?)\s+(?:een\s+)?((?:1[\s-]?|3[\s-]?|drie)fasen?(?:\s+\w+){0,2})\b/iu';

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
        return (bool) preg_match(self::OVERCONFIDENT_FACT_PATTERN, $text)
            || (bool) preg_match(self::OVERCONFIDENT_CONSTRUCTION_PATTERN, $text);
    }

    /**
     * Mentions phase/electrical capacity without hedging language.
     * Check-instructions ("controleer op vrije groepen") are not positive claims.
     */
    public function claimsUnequivocalElectricalFact(string $text): bool
    {
        $normalized = mb_strtolower($text);
        if ($this->textLooksHedged($normalized)) {
            return false;
        }

        // Absence / check-instructions about free groups are not positive electrical claims.
        if ((bool) preg_match('/\bgeen\s+vrije\s+groep(?:en)?\b/u', $normalized)
            || (bool) preg_match('/\b(controleer|check|nagaan)\b.*\bvrije\s+groep(?:en)?\b/u', $normalized)
            || (bool) preg_match('/\bvrije\s+groep(?:en)?\b.*\b(controleer|te\s+controleren|nagaan)\b/u', $normalized)) {
            return $this->claimsOverconfidentFact($text);
        }

        // Bare "vrije groepen" without presence language is not an unequivocal fact;
        // only hard phase mentions or overconfident presence constructions qualify.
        return (bool) preg_match(self::PHASE_PATTERN, $normalized)
            || $this->claimsOverconfidentFact($text);
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
     * Resolve ceiling from the SummarizeIntake payload shape (answers + external_facts).
     * Used so "AI-voorstel (niet bindend)" never hardens hedged meter observations.
     *
     * @param  array<string, mixed>  $payload
     * @return 'low'|'medium'|'high'|null
     */
    public function ceilingFromSummaryPayload(array $payload): ?string
    {
        $ceilings = [];

        $externalFacts = is_array($payload['external_facts'] ?? null) ? $payload['external_facts'] : [];
        foreach ($externalFacts as $factKey => $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $confidence = $fact['confidence'] ?? null;
            if (is_string($confidence) && in_array($confidence, ['low', 'medium', 'high'], true)) {
                // Fusebox / electrical assessments always cap derived phase claims.
                if (is_string($factKey) && str_contains($factKey, 'fusebox')) {
                    $ceilings[] = $confidence;
                }
            }

            foreach ($this->stringLeaves($fact['value'] ?? null) as $text) {
                $ceilings[] = $this->ceilingFromObservationText($text);
            }
            if (is_string($fact['label'] ?? null)) {
                $ceilings[] = $this->ceilingFromObservationText((string) $fact['label']);
            }
        }

        foreach ([
            $payload['external_fact_context'] ?? null,
            $payload['uploads'] ?? null,
            $payload['answer_context'] ?? null,
        ] as $bucket) {
            if (! is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (['display', 'evidence', 'label', 'value', 'summary'] as $field) {
                    if (is_string($row[$field] ?? null)) {
                        $ceilings[] = $this->ceilingFromObservationText((string) $row[$field]);
                    } elseif (is_array($row[$field] ?? null)) {
                        foreach ($this->stringLeaves($row[$field]) as $leaf) {
                            $ceilings[] = $this->ceilingFromObservationText($leaf);
                        }
                    }
                }
                $rowConfidence = $row['confidence'] ?? null;
                if (is_string($rowConfidence) && in_array($rowConfidence, ['low', 'medium', 'high'], true)) {
                    $reference = (string) ($row['reference'] ?? '');
                    if (str_contains($reference, 'fusebox')) {
                        $ceilings[] = $rowConfidence;
                    }
                }
            }
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
     * Mark an overconfident derived claim without rewriting the model text (ADR-0016).
     *
     * @param  'low'|'medium'|'high'|null  $sourceCeiling
     * @return array{text: string, hedged: bool}
     */
    public function normalizeDerivedText(string $text, ?string $sourceCeiling): array
    {
        $sourceIsSoft = $sourceCeiling !== null && $sourceCeiling !== 'high';
        $shouldMarkUncertain = $this->claimsOverconfidentFact($text)
            || ($sourceIsSoft && $this->claimsUnequivocalElectricalFact($text))
            || ($sourceIsSoft && ! $this->textLooksHedged($text)
                && (bool) preg_match('/\b(aanwezig|vastgesteld|bevestigd)\b/iu', $text)
                && (bool) preg_match(self::PHASE_OR_GROUP_PATTERN, mb_strtolower($text)));

        return ['text' => $text, 'hedged' => $shouldMarkUncertain];
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
