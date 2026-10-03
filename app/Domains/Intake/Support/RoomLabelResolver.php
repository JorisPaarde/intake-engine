<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Eén label-resolver voor klantwizard en installateurswerkplek (nummering per type).
 */
final class RoomLabelResolver
{
    /**
     * @param  array<string, int>  $typeCounts  mutable per-type counters (1-based after increment)
     */
    public static function label(?string $useType, array &$typeCounts): string
    {
        $typeKey = is_string($useType) && $useType !== '' ? $useType : 'other';
        $typeCounts[$typeKey] = ($typeCounts[$typeKey] ?? 0) + 1;

        return self::labelForIndex($useType, $typeCounts[$typeKey]);
    }

    public static function labelForIndex(?string $useType, int $index): string
    {
        $base = match ($useType) {
            'living_room' => 'Woonkamer',
            'bedroom' => 'Slaapkamer',
            'office' => 'Werkkamer',
            'attic' => 'Zolder',
            default => 'Ruimte',
        };

        return $base.' '.$index;
    }

    /**
     * Gegenereerd patroon (Woonkamer 1, Slaapkamer, …) vs. installateurs-/klantnaam.
     */
    public static function isGeneratedPlaceholder(string $name): bool
    {
        $name = trim($name);

        return preg_match('/^(Woonkamer|Slaapkamer|Werkkamer|Zolder|Ruimte)( \d+)?$/u', $name) === 1;
    }

    /**
     * Parse room_type-antwoorden naar instance → type (één plek voor klant + dossier).
     *
     * @param  array<string, array<string, mixed>|null>  $answers
     * @return array<string, string|null>
     */
    public static function typesByInstance(array $answers): array
    {
        $typesByInstance = [];
        foreach ($answers as $composite => $value) {
            if (! str_contains($composite, '__room_type')) {
                continue;
            }
            [$instance] = explode('__', $composite, 2);
            if (preg_match('/^room-\d+$/', $instance) !== 1) {
                continue;
            }
            $type = is_array($value) ? ($value['value'] ?? null) : null;
            $typesByInstance[$instance] = is_string($type) ? $type : null;
        }
        ksort($typesByInstance, SORT_NATURAL);

        return $typesByInstance;
    }

    /**
     * Maak een gevraagde naam uniek binnen de al gebruikte namen (index-suffix bij botsing).
     *
     * @param  list<string>  $usedNames
     */
    public static function uniqueAmong(string $desired, array $usedNames): string
    {
        $desired = trim($desired);
        if ($desired === '') {
            return $desired;
        }

        $normalizedUsed = array_map(
            static fn (string $name): string => mb_strtolower(trim($name)),
            $usedNames,
        );

        if (! in_array(mb_strtolower($desired), $normalizedUsed, true)) {
            return $desired;
        }

        $index = 2;
        while (in_array(mb_strtolower($desired.' '.$index), $normalizedUsed, true)) {
            $index++;
        }

        return $desired.' '.$index;
    }
}
