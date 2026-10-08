<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Koppelt verdiepingen uit vrije tekst alleen aan de ruimte waar de tekst ze noemt.
 *
 * Geen stille default naar begane grond. Onduidelijke koppeling → null (klant bevestigt).
 */
final class RoomFloorLevelExtractor
{
    /**
     * @param  list<'living_room'|'bedroom'|'office'|'attic'|'other'>  $rooms
     * @return list<'basement'|'ground'|'1'|'2'|'3_plus'|'attic'|null>
     */
    public function floorsForRooms(string $text, array $rooms): array
    {
        $count = count($rooms);
        if ($count === 0) {
            return [];
        }

        $normalized = $this->normalize($text);
        $floorCues = $this->absoluteFloorCues($normalized);
        // "op zolder" is locatie, geen derde kamer — zelfde lengte houden voor offsets.
        $roomText = preg_replace_callback(
            '/\bop\s+(?:de\s+)?zolder\b/u',
            static fn (array $match): string => str_repeat(' ', strlen($match[0])),
            $normalized,
        ) ?? $normalized;
        $roomMentions = $this->roomMentions($roomText);

        /** @var list<'basement'|'ground'|'1'|'2'|'3_plus'|'attic'|null> $floors */
        $floors = array_fill(0, $count, null);

        if ($roomMentions === []) {
            return $floors;
        }

        /** @var array<int, 'basement'|'ground'|'1'|'2'|'3_plus'|'attic'|null> $mentionFloors */
        $mentionFloors = [];
        /** @var array<int, true> $claimedCueStarts */
        $claimedCueStarts = [];

        // 1) Alleen trailing cues (ná deze kamer, vóór de volgende) — voorkomt lek van
        //    "werkkamer op de 1e … woonkamer beneden" naar de woonkamer via before-cues.
        //    Cue direct gevolgd door de volgende kamernaam ("… 1e verdieping de slaapkamer")
        //    telt als before-cue van die volgende kamer, niet als trailing van deze.
        foreach ($roomMentions as $index => $mention) {
            $nextMention = $roomMentions[$index + 1] ?? null;
            $nextStart = $nextMention['start'] ?? mb_strlen($normalized);
            $candidates = [];

            foreach ($floorCues as $cueIndex => $cue) {
                if ($cue['start'] >= $mention['end'] && $cue['start'] < $nextStart) {
                    if ($nextMention !== null
                        && $this->cueDirectlyFollowedByRoom($normalized, $cue, $nextMention)) {
                        continue;
                    }
                    $candidates[] = $cue['value'];
                    $claimedCueStarts[$cueIndex] = true;
                }
            }

            $mentionFloors[$index] = $this->pickBestFloor($candidates);
        }

        // 2) Relatief ("beneden"/"boven") vóór before-cues, zodat die niet door een
        //    gelekte absolute cue van de vorige kamer worden geblokkeerd.
        $this->applyRelativeFloors($normalized, $roomMentions, $mentionFloors);

        // 3) Before-cues alleen voor nog lege kamers én ongeclaimde cues
        //    ("op de begane grond de woonkamer").
        foreach ($roomMentions as $index => $mention) {
            if (($mentionFloors[$index] ?? null) !== null) {
                continue;
            }

            $prevEnd = $index === 0 ? -1 : $roomMentions[$index - 1]['end'];
            $candidates = [];

            foreach ($floorCues as $cueIndex => $cue) {
                if (isset($claimedCueStarts[$cueIndex])) {
                    continue;
                }
                if ($cue['end'] <= $mention['start'] && $cue['start'] > $prevEnd) {
                    $candidates[] = $cue['value'];
                    $claimedCueStarts[$cueIndex] = true;
                }
            }

            $mentionFloors[$index] = $this->pickBestFloor($candidates);
        }

        // "woonkamer en slaapkamer op de 1e verdieping" → gedeelde trailing floor terugvullen.
        $this->propagateTrailingFloors($mentionFloors);

        // Eén duidelijke floor-cue zonder kameranker: alleen bij één ruimtetype/-vermelding.
        if ($this->allNull($mentionFloors) && count($floorCues) === 1) {
            $uniqueTypes = array_values(array_unique(array_column($roomMentions, 'type')));
            if (count($roomMentions) === 1 || count($uniqueTypes) === 1) {
                foreach (array_keys($roomMentions) as $mentionIndex) {
                    $mentionFloors[$mentionIndex] = $floorCues[0]['value'];
                }
            }
        }

        $expanded = $this->expandMentionsToRooms($roomMentions, $rooms);

        foreach ($expanded as $roomIndex => $mentionIndex) {
            if ($mentionIndex === null) {
                continue;
            }

            $floor = $mentionFloors[$mentionIndex] ?? null;
            if ($floor !== null) {
                $floors[$roomIndex] = $floor;
            }
        }

        return $floors;
    }

    /**
     * Genummerde verdieping in de tekst (voor attic-conflict), zonder begane-grond-default.
     *
     * @return '1'|'2'|'3_plus'|null
     */
    public function numberedFloorFromText(string $text): ?string
    {
        $normalized = $this->normalize($text);

        if (preg_match(
            '/\b(?P<ord>1(?:e|ste)?|eerste|2(?:e|de)?|tweede|3(?:e|de)?|derde|[4-9](?:e|de)?)\s+verdieping\b/u',
            $normalized,
            $matches,
        ) !== 1) {
            return null;
        }

        $ord = mb_strtolower((string) $matches['ord'], 'UTF-8');

        return match (true) {
            str_starts_with($ord, '1') || $ord === 'eerste' => '1',
            str_starts_with($ord, '2') || $ord === 'tweede' => '2',
            default => '3_plus',
        };
    }

    private function normalize(string $text): string
    {
        return str_replace(
            ['’', '‘', '´'],
            "'",
            mb_strtolower(trim($text), 'UTF-8'),
        );
    }

    /**
     * @return list<array{type: 'living_room'|'bedroom'|'office'|'attic'|'other', start: int, end: int}>
     */
    private function roomMentions(string $text): array
    {
        $number = '[1-8]|één|een|twee|drie|vier|vijf|zes|zeven|acht';
        // Kinderkamer / kinderslaapkamer tellen als slaapkamer-anker voor floor-koppeling.
        $room = 'kinderslaapkamers?|kinderkamers?|slaapkamers?|woonkamers?|huiskamers?|werkkamers?|kantoor|kantoren|zolders?';
        $matches = [];

        preg_match_all(
            '/\b(?:(?:'.$number.')\s+)?(?<room>'.$room.')\b/u',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $result = [];

        foreach ($matches as $match) {
            $roomWord = $match['room'][0];
            $type = $this->roomType($roomWord);

            if ($type === null) {
                continue;
            }

            // Volledige match (incl. cijfer/telwoord) zodat "drie slaapkamers" niet
            // een gap "drie" laat die cueDirectlyFollowedByRoom als trailing van de
            // vorige kamer misbruikt.
            $full = $match[0][0];
            $byteStart = (int) $match[0][1];
            $start = mb_strlen(substr($text, 0, $byteStart), 'UTF-8');
            $end = $start + mb_strlen($full, 'UTF-8');

            $result[] = [
                'type' => $type,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{value: 'basement'|'ground'|'1'|'2'|'3_plus'|'attic', start: int, end: int}>
     */
    private function absoluteFloorCues(string $text): array
    {
        $patterns = [
            ['/\b(?:kelder|souterrain)\b/u', 'basement'],
            ['/\bbegane\s+grond\b/u', 'ground'],
            ['/\b(?P<ord>1(?:e|ste)?|eerste|2(?:e|de)?|tweede|3(?:e|de)?|derde|[4-9](?:e|de)?)\s+verdieping\b/u', 'numbered'],
            ['/\bop\s+(?:de\s+)?zolder\b/u', 'attic'],
        ];

        $cues = [];

        foreach ($patterns as [$pattern, $kind]) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $full = $match[0][0];
                $byteStart = (int) $match[0][1];
                $start = mb_strlen(substr($text, 0, $byteStart), 'UTF-8');
                $end = $start + mb_strlen($full, 'UTF-8');

                $value = $kind;
                if ($kind === 'numbered') {
                    $ord = mb_strtolower((string) ($match['ord'][0] ?? ''), 'UTF-8');
                    $value = match (true) {
                        str_starts_with($ord, '1') || $ord === 'eerste' => '1',
                        str_starts_with($ord, '2') || $ord === 'tweede' => '2',
                        default => '3_plus',
                    };
                }

                $cues[] = [
                    'value' => $value,
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        usort(
            $cues,
            static fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end'],
        );

        $deduped = [];
        $lastEnd = -1;
        foreach ($cues as $cue) {
            if ($cue['start'] < $lastEnd) {
                continue;
            }
            $deduped[] = $cue;
            $lastEnd = $cue['end'];
        }

        return $deduped;
    }

    /**
     * @param  list<'basement'|'ground'|'1'|'2'|'3_plus'|'attic'>  $candidates
     * @return 'basement'|'ground'|'1'|'2'|'3_plus'|'attic'|null
     */
    private function pickBestFloor(array $candidates): ?string
    {
        if ($candidates === []) {
            return null;
        }

        // Genummerde verdieping wint van zolder/andere cues in hetzelfde kamervenster.
        foreach (array_reverse($candidates) as $value) {
            if (in_array($value, ['1', '2', '3_plus'], true)) {
                return $value;
            }
        }

        return $candidates[array_key_last($candidates)];
    }

    /**
     * "slaapkamer boven" / "woonkamer beneden" — alleen direct na de kamernaam,
     * nooit losse "boven de bank"-plaatsing.
     *
     * @param  list<array{type: string, start: int, end: int}>  $roomMentions
     * @param  array<int, 'basement'|'ground'|'1'|'2'|'3_plus'|'attic'|null>  $mentionFloors
     */
    private function applyRelativeFloors(string $text, array $roomMentions, array &$mentionFloors): void
    {
        foreach ($roomMentions as $index => $mention) {
            if (($mentionFloors[$index] ?? null) !== null) {
                continue;
            }

            $window = mb_substr($text, $mention['end'], 24, 'UTF-8');
            // Negatieve lookahead: "boven de/het/een …" is plaatsing, geen verdieping.
            if (preg_match('/^\s*,?\s*boven(?!\s+(?:de|het|een)\b)\b/u', $window) === 1) {
                $mentionFloors[$index] = '1';
            } elseif (preg_match('/^\s*,?\s*beneden(?!\s+(?:de|het|een)\b)\b/u', $window) === 1) {
                $mentionFloors[$index] = 'ground';
            }
        }
    }

    /**
     * Cue eindigt net vóór de volgende kamernaam (alleen leeg of lidwoord de/het/een).
     * Geen en/op/van/voor/naar — die horen bij trailing van de vorige kamer
     * ("woonkamer op de begane grond en de slaapkamer…").
     *
     * @param  array{value: string, start: int, end: int}  $cue
     * @param  array{type: string, start: int, end: int}  $nextMention
     */
    private function cueDirectlyFollowedByRoom(string $text, array $cue, array $nextMention): bool
    {
        if ($cue['end'] > $nextMention['start']) {
            return false;
        }

        $between = trim(mb_substr($text, $cue['end'], $nextMention['start'] - $cue['end'], 'UTF-8'));

        // Leeg, lidwoord, of kort koppelwerkwoord + lidwoord ("zijn de slaapkamers").
        return $between === ''
            || preg_match('/^(?:(?:is|zijn|staat|staan|ligt|liggen)\s+)?(?:de|het|een)$/u', $between) === 1;
    }

    /**
     * @param  array<int, 'basement'|'ground'|'1'|'2'|'3_plus'|'attic'|null>  $mentionFloors
     */
    private function propagateTrailingFloors(array &$mentionFloors): void
    {
        $pending = [];
        foreach ($mentionFloors as $index => $floor) {
            if ($floor === null) {
                $pending[] = $index;

                continue;
            }

            foreach ($pending as $emptyIndex) {
                $mentionFloors[$emptyIndex] = $floor;
            }
            $pending = [];
        }
    }

    /**
     * @param  array<int, mixed>  $mentionFloors
     */
    private function allNull(array $mentionFloors): bool
    {
        foreach ($mentionFloors as $floor) {
            if ($floor !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{type: 'living_room'|'bedroom'|'office'|'attic'|'other', start: int, end: int}>  $mentions
     * @param  list<'living_room'|'bedroom'|'office'|'attic'|'other'>  $rooms
     * @return list<int|null> mention index per room slot
     */
    private function expandMentionsToRooms(array $mentions, array $rooms): array
    {
        $map = array_fill(0, count($rooms), null);
        $mentionCursor = 0;

        foreach ($rooms as $roomIndex => $roomType) {
            while ($mentionCursor < count($mentions) && $mentions[$mentionCursor]['type'] !== $roomType) {
                $mentionCursor++;
            }

            if ($mentionCursor >= count($mentions)) {
                for ($i = count($mentions) - 1; $i >= 0; $i--) {
                    if ($mentions[$i]['type'] === $roomType) {
                        $map[$roomIndex] = $i;
                        break;
                    }
                }

                continue;
            }

            $map[$roomIndex] = $mentionCursor;
            $mentionCursor++;
        }

        return $map;
    }

    /**
     * @return 'living_room'|'bedroom'|'office'|'attic'|'other'|null
     */
    private function roomType(string $room): ?string
    {
        return match (true) {
            str_starts_with($room, 'kinderslaapkamer'),
            str_starts_with($room, 'kinderkamer'),
            str_starts_with($room, 'slaapkamer') => 'bedroom',
            str_starts_with($room, 'woonkamer'), str_starts_with($room, 'huiskamer') => 'living_room',
            str_starts_with($room, 'werkkamer'), $room === 'kantoor', $room === 'kantoren' => 'office',
            str_starts_with($room, 'zolder') => 'attic',
            default => null,
        };
    }
}
