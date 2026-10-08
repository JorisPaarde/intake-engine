<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Detecteert bestaande airco + vervanging/demontage in de aanvraagtekst.
 * Room/replacement alleen op de evidence-zin, niet op de hele tekst.
 */
final class ExistingAircoExtractor
{
    private const MENTION = 'oude\s+airco|bestaande\s+airco|huidige\s+airco|er\s+hangt\s+al\s+(?:een\s+)?(?:oude\s+)?airco';

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
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\b(?:'.self::MENTION.')\b/u', $normalized, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $byteStart = (int) $match[0][1];
        $charStart = mb_strlen(substr($normalized, 0, $byteStart), 'UTF-8');
        $matched = $match[0][0];

        // Negatie vlak vóór de match: "geen bestaande airco", "zonder oude airco", …
        $lookback = min(40, $charStart);
        $before = $lookback > 0
            ? mb_substr($normalized, $charStart - $lookback, $lookback, 'UTF-8')
            : '';
        if (preg_match('/(?:geen|zonder|niet)(?:\s+\S+){0,4}\s*$/u', $before) === 1) {
            return null;
        }

        preg_match(
            '/[^.?!]*(?:oude\s+airco|bestaande\s+airco|huidige\s+airco|er\s+hangt\s+al)[^.?!]*/iu',
            $text,
            $evidenceMatch,
        );
        $evidence = trim((string) ($evidenceMatch[0] ?? ''));
        if ($evidence === '') {
            // Altijd een echt citaat uit de brontekst — nooit een verzonnen fallback.
            $evidence = mb_substr($text, $charStart, mb_strlen($matched, 'UTF-8'), 'UTF-8');
        }
        if (trim($evidence) === '') {
            return null;
        }

        $evidenceNormalized = mb_strtolower($evidence, 'UTF-8');

        $replacement = preg_match(
            '/\b(?:vervangen|vervanging|demonteren|demontage|weghalen|verwijderen)\b/u',
            $evidenceNormalized,
        ) === 1;

        $roomType = null;
        if (preg_match(
            '/\b(?:oude\s+airco|bestaande\s+airco|huidige\s+airco|airco)\b.{0,40}?\b(woonkamer|huiskamer|slaapkamer|werkkamer|kantoor|zolder)\b|\b(woonkamer|huiskamer|slaapkamer|werkkamer|kantoor|zolder)\b.{0,40}?\b(?:oude\s+airco|bestaande\s+airco|huidige\s+airco|airco)\b/u',
            $evidenceNormalized,
            $matches,
        ) === 1) {
            $roomWord = $matches[1] !== '' ? $matches[1] : $matches[2];
            $roomType = match (true) {
                str_starts_with($roomWord, 'woon'), str_starts_with($roomWord, 'huis') => 'living_room',
                str_starts_with($roomWord, 'slaap') => 'bedroom',
                str_starts_with($roomWord, 'werk'), str_starts_with($roomWord, 'kantoor') => 'office',
                str_starts_with($roomWord, 'zolder') => 'attic',
                default => 'other',
            };
        }

        return [
            'present' => true,
            'replacement' => $replacement,
            'room_type' => $roomType,
            'evidence' => $evidence,
        ];
    }
}
