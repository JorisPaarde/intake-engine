<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Detecteert bestaande airco + vervanging/demontage in de aanvraagtekst.
 */
final class ExistingAircoExtractor
{
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

        $mentionsExisting = preg_match(
            '/\b(?:oude\s+airco|bestaande\s+airco|huidige\s+airco|er\s+hangt\s+al\s+(?:een\s+)?(?:oude\s+)?airco|al\s+een\s+airco)\b/u',
            $normalized,
        ) === 1;

        if (! $mentionsExisting) {
            return null;
        }

        $replacement = preg_match(
            '/\b(?:vervangen|vervanging|demonteren|demontage|weghalen|verwijderen)\b/u',
            $normalized,
        ) === 1;

        $roomType = null;
        if (preg_match(
            '/\b(?:oude\s+airco|bestaande\s+airco|huidige\s+airco|airco)\b.{0,40}?\b(woonkamer|huiskamer|slaapkamer|werkkamer|kantoor|zolder)\b|\b(woonkamer|huiskamer|slaapkamer|werkkamer|kantoor|zolder)\b.{0,40}?\b(?:oude\s+airco|bestaande\s+airco|huidige\s+airco|airco)\b/u',
            $normalized,
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

        preg_match(
            '/[^.?!]*(?:oude\s+airco|bestaande\s+airco|huidige\s+airco|er\s+hangt\s+al)[^.?!]*/u',
            $text,
            $evidenceMatch,
        );
        $evidence = trim((string) ($evidenceMatch[0] ?? 'bestaande airco'));

        return [
            'present' => true,
            'replacement' => $replacement,
            'room_type' => $roomType,
            'evidence' => $evidence !== '' ? $evidence : 'bestaande airco',
        ];
    }
}
