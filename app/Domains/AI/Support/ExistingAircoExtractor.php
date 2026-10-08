<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Detecteert bestaande airco + vervanging/demontage in de aanvraagtekst.
 * Room/replacement alleen op de evidence-zin rond de gekozen match.
 */
final class ExistingAircoExtractor
{
    /**
     * Positieve vermeldingen. Geen losse "al een airco" (planning); wel
     * "hebben/heb/hangt/zit al een airco".
     */
    private const MENTION = 'oude\s+airco|bestaande\s+airco|huidige\s+airco'
        .'|er\s+hangt\s+al\s+(?:een\s+)?(?:oude\s+)?airco'
        .'|(?:hebben|heb|hangt|zit)\s+al\s+een\s+airco';

    /**
     * @return array{
     *     present: bool,
     *     replacement: bool,
     *     room_type: 'living_room'|'bedroom'|'office'|'attic'|'other'|null,
     *     evidence: string
     * }|null
     */
    public function extract(string $text): ?array
    {
        $text = trim($text);
        $normalized = mb_strtolower($text, 'UTF-8');
        if ($normalized === '') {
            return null;
        }

        if (preg_match_all(
            '/\b(?:'.self::MENTION.')\b/u',
            $normalized,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) === false || $matches[0] === []) {
            return null;
        }

        foreach ($matches[0] as [$matched, $byteStart]) {
            $charStart = mb_strlen(substr($normalized, 0, (int) $byteStart), 'UTF-8');

            if ($this->isNegatedInClause($normalized, $charStart)) {
                continue;
            }

            $evidence = $this->sentenceAround($text, $charStart);
            if ($evidence === '') {
                continue;
            }

            $evidenceNormalized = mb_strtolower($evidence, 'UTF-8');

            $replacement = preg_match(
                '/\b(?:vervangen|vervanging|demonteren|demontage|weghalen|verwijderen|weg)\b/u',
                $evidenceNormalized,
            ) === 1;

            // Kamer alleen rond de gekozen match — niet een eerdere (genegeerde) vermelding.
            $roomType = $this->roomTypeNearMatch($normalized, $charStart, mb_strlen($matched, 'UTF-8'));

            return [
                'present' => true,
                'replacement' => $replacement,
                'room_type' => $roomType,
                'evidence' => $evidence,
            ];
        }

        return null;
    }

    /**
     * Negatie alleen binnen dezelfde clausule (na laatste .?!,;:).
     * geen/zonder: ≤2 woorden tot de match; niet: alleen direct vóór.
     */
    private function isNegatedInClause(string $normalized, int $charStart): bool
    {
        $before = mb_substr($normalized, 0, $charStart, 'UTF-8');
        $clause = $before;
        if (preg_match('/^(.*)[.?!,;:]([^.?!,;:]*)$/us', $before, $parts) === 1) {
            $clause = $parts[2];
        }

        if (preg_match('/\b(?:geen|zonder)\b(?:\s+\S+){0,2}\s*$/u', $clause) === 1) {
            return true;
        }

        return preg_match('/\bniet\s+$/u', $clause) === 1;
    }

    /**
     * Kamerwoord direct vóór of ná de gekozen match (niet een eerdere vermelding).
     *
     * @return 'living_room'|'bedroom'|'office'|'attic'|'other'|null
     */
    private function roomTypeNearMatch(string $normalized, int $charStart, int $matchLen): ?string
    {
        $room = 'woonkamer|huiskamer|slaapkamer|werkkamer|kantoor|zolder';
        $before = mb_substr($normalized, max(0, $charStart - 56), min(56, $charStart), 'UTF-8');
        $after = mb_substr($normalized, $charStart + $matchLen, 56, 'UTF-8');

        $roomWord = null;
        if (preg_match('/\b('.$room.')\b(?:\s+\S+){0,10}\s*$/u', $before, $m) === 1) {
            $roomWord = $m[1];
        } elseif (preg_match('/^(?:\s+\S+){0,10}\s*\b('.$room.')\b/u', $after, $m) === 1) {
            $roomWord = $m[1];
        }

        if ($roomWord === null) {
            return null;
        }

        return match (true) {
            str_starts_with($roomWord, 'woon'), str_starts_with($roomWord, 'huis') => 'living_room',
            str_starts_with($roomWord, 'slaap') => 'bedroom',
            str_starts_with($roomWord, 'werk'), str_starts_with($roomWord, 'kantoor') => 'office',
            str_starts_with($roomWord, 'zolder') => 'attic',
            default => 'other',
        };
    }

    /**
     * Zin rondom de match in de brontekst (échte quote, geen fallback).
     */
    private function sentenceAround(string $text, int $charStart): string
    {
        $length = mb_strlen($text, 'UTF-8');
        if ($charStart < 0 || $charStart >= $length) {
            return '';
        }

        $start = 0;
        for ($i = 0; $i < $charStart; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if ($ch === '.' || $ch === '?' || $ch === '!') {
                $start = $i + 1;
            }
        }

        $end = $length;
        for ($i = $charStart; $i < $length; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if ($ch === '.' || $ch === '?' || $ch === '!') {
                $end = $i + 1;
                break;
            }
        }

        return trim(mb_substr($text, $start, $end - $start, 'UTF-8'));
    }
}
