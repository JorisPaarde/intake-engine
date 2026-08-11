<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Haalt alleen evidente aanvraagfeiten lokaal uit een Nederlandse openingszin.
 *
 * Dit is bewust geen algemene taalparser. Hij past alleen een conclusie toe wanneer
 * functie, ruimtetype en exact aantal letterlijk uit een kleine set herkenbare
 * formuleringen volgen. Optioneel herkent hij ook een expliciete buitenunitplek
 * (dak/dakkapel/gevel/tuin/balkon). Alles daarbuiten blijft voor de normale vraag
 * of optionele externe tekstanalyse staan.
 */
final class LocalRequestIntentParser
{
    public const VERSION = 'request-intent-local-v2';

    private const MAX_ROOMS = 8;

    /**
     * @return array{
     *     cooling_heating: 'cooling'|'heating'|'both',
     *     rooms: list<'living_room'|'bedroom'|'office'|'attic'|'other'>,
     *     floor_level: 'attic'|null,
     *     outdoor_location: 'garden'|'side_passage'|'facade'|'balcony'|'flat_roof'|'pitched_roof'|null,
     *     outdoor_mount_type: 'wall'|'ground'|'roof'|'balcony'|null,
     *     confidence: 'high',
     *     evidence: string
     * }|null
     */
    public function parse(string $text): ?array
    {
        $normalized = $this->normalize($text);
        $function = $this->detectFunction($normalized);

        if ($function === null) {
            return null;
        }

        $roomText = $this->removeAtticAsLocation($normalized);
        $roomMatches = $this->roomMatches($roomText);

        if ($roomMatches === []) {
            return null;
        }

        $rooms = [];
        $hasUnquantifiedPlural = false;

        foreach ($roomMatches as $match) {
            $quantity = $match['quantity'];

            if ($quantity === null) {
                $rooms[] = $match['type'];
                $hasUnquantifiedPlural = $hasUnquantifiedPlural || $match['plural'];

                continue;
            }

            for ($index = 0; $index < $quantity; $index++) {
                $rooms[] = $match['type'];
            }
        }

        if (count($roomMatches) === 1 && $hasUnquantifiedPlural) {
            $unitCount = $this->explicitUnitCount($normalized);

            if ($unitCount === null) {
                return null;
            }

            $rooms = array_fill(0, $unitCount, $roomMatches[0]['type']);
        }

        $unitCount = $this->explicitUnitCount($normalized);

        if ($unitCount !== null && $unitCount !== count($rooms)) {
            return null;
        }

        if ($rooms === [] || count($rooms) > self::MAX_ROOMS) {
            return null;
        }

        $outdoor = $this->detectOutdoorPlacement($normalized);

        return [
            'cooling_heating' => $function,
            'rooms' => $rooms,
            'floor_level' => preg_match('/\bop\s+(?:de\s+)?zolder\b/u', $normalized) === 1
                ? 'attic'
                : null,
            'outdoor_location' => $outdoor['location'],
            'outdoor_mount_type' => $outdoor['mount'],
            'confidence' => 'high',
            'evidence' => $this->evidence($outdoor['location'] !== null || $outdoor['mount'] !== null),
        ];
    }

    private function normalize(string $text): string
    {
        return str_replace(
            ['’', '‘', '´'],
            "'",
            mb_strtolower(trim($text)),
        );
    }

    /**
     * @return 'cooling'|'heating'|'both'|null
     */
    private function detectFunction(string $text): ?string
    {
        $cooling = preg_match(
            '/\b(?:koelen|koeling|gekoeld|verkoelen|afkoelen|te\s+warm|hitte|koud\s+te\s+krijgen|koel\s+te\s+(?:houden|krijgen))\b/u',
            $text,
        ) === 1;
        $heating = preg_match(
            '/\b(?:verwarmen|verwarming|verwarmd|bijverwarmen|te\s+koud)\b/u',
            $text,
        ) === 1;

        return match (true) {
            $cooling && $heating => 'both',
            $cooling => 'cooling',
            $heating => 'heating',
            default => null,
        };
    }

    /**
     * @return array{
     *     location: 'garden'|'side_passage'|'facade'|'balcony'|'flat_roof'|'pitched_roof'|null,
     *     mount: 'wall'|'ground'|'roof'|'balcony'|null
     * }
     */
    private function detectOutdoorPlacement(string $text): array
    {
        if (! preg_match('/\bbuitenunit(?:s)?\b|\bbuiten\s*unit(?:s)?\b/u', $text)) {
            return ['location' => null, 'mount' => null];
        }

        if (preg_match('/\bdakkapel(?:len)?\b/u', $text) === 1
            || preg_match('/\b(?:schuin(?:e)?\s+dak|hellend(?:e)?\s+dak)\b/u', $text) === 1) {
            return ['location' => 'pitched_roof', 'mount' => 'roof'];
        }

        if (preg_match('/\bplat(?:te)?\s+dak\b/u', $text) === 1) {
            return ['location' => 'flat_roof', 'mount' => 'roof'];
        }

        if (preg_match('/\b(?:op|aan)\s+(?:het\s+|de\s+)?dak\b/u', $text) === 1) {
            // Alleen "op het dak" zonder plat/schuin: bevestigingstype is duidelijk, plektype niet.
            return ['location' => null, 'mount' => 'roof'];
        }

        if (preg_match('/\b(?:op|aan)\s+(?:het\s+|de\s+)?balkon\b/u', $text) === 1) {
            return ['location' => 'balcony', 'mount' => 'balcony'];
        }

        if (preg_match('/\b(?:in|op)\s+(?:de\s+)?tuin\b|\bachtererf\b/u', $text) === 1) {
            return ['location' => 'garden', 'mount' => 'ground'];
        }

        if (preg_match('/\b(?:aan|op)\s+(?:de\s+)?gevel\b|\bmuurbeugel\b/u', $text) === 1) {
            return ['location' => 'facade', 'mount' => 'wall'];
        }

        if (preg_match('/\bzijpad\b|\bsteeg\b/u', $text) === 1) {
            return ['location' => 'side_passage', 'mount' => 'ground'];
        }

        return ['location' => null, 'mount' => null];
    }

    private function evidence(bool $includesOutdoor): string
    {
        return $includesOutdoor
            ? 'Doel, aantal, gewenste ruimtes, eventuele zolderverdieping en buitenunitplek staan expliciet in de openingstekst.'
            : 'Doel, aantal, gewenste ruimtes en eventuele zolderverdieping staan expliciet in de openingstekst.';
    }

    private function removeAtticAsLocation(string $text): string
    {
        return preg_replace('/\bop\s+(?:de\s+)?zolder\b/u', '', $text) ?? $text;
    }

    /**
     * @return list<array{
     *     type: 'living_room'|'bedroom'|'office'|'attic'|'other',
     *     quantity: int|null,
     *     plural: bool
     * }>
     */
    private function roomMatches(string $text): array
    {
        $number = '[1-8]|één|een|twee|drie|vier|vijf|zes|zeven|acht';
        $room = 'slaapkamers?|woonkamers?|huiskamers?|werkkamers?|kantoor|kantoren|zolders?';
        $matches = [];

        preg_match_all(
            '/\b(?:(?<quantity>'.$number.')\s+)?(?<room>'.$room.')\b/u',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        $result = [];

        foreach ($matches as $match) {
            $roomWord = $match['room'];
            $type = $this->roomType($roomWord);

            if ($type === null) {
                continue;
            }

            $quantityWord = $match['quantity'];
            $result[] = [
                'type' => $type,
                'quantity' => $this->number($quantityWord),
                'plural' => $this->isPluralRoom($roomWord),
            ];
        }

        return $result;
    }

    /**
     * @return 'living_room'|'bedroom'|'office'|'attic'|'other'|null
     */
    private function roomType(string $room): ?string
    {
        return match (true) {
            str_starts_with($room, 'slaapkamer') => 'bedroom',
            str_starts_with($room, 'woonkamer'), str_starts_with($room, 'huiskamer') => 'living_room',
            str_starts_with($room, 'werkkamer'), $room === 'kantoor', $room === 'kantoren' => 'office',
            str_starts_with($room, 'zolder') => 'attic',
            default => null,
        };
    }

    private function isPluralRoom(string $room): bool
    {
        return in_array($room, [
            'slaapkamers',
            'woonkamers',
            'huiskamers',
            'werkkamers',
            'kantoren',
            'zolders',
        ], true);
    }

    private function explicitUnitCount(string $text): ?int
    {
        $number = '[1-8]|één|een|twee|drie|vier|vijf|zes|zeven|acht';
        $matches = [];

        if (preg_match(
            '/\b(?<quantity>'.$number.')\s+(?:airco(?:\'?s)?|binnenunits?)\b/u',
            $text,
            $matches,
        ) !== 1) {
            return null;
        }

        return $this->number($matches['quantity']);
    }

    private function number(string $value): ?int
    {
        return match ($value) {
            '1', 'een', 'één' => 1,
            '2', 'twee' => 2,
            '3', 'drie' => 3,
            '4', 'vier' => 4,
            '5', 'vijf' => 5,
            '6', 'zes' => 6,
            '7', 'zeven' => 7,
            '8', 'acht' => 8,
            default => null,
        };
    }
}
