<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Eén bron voor verdieping-labels (werkplek, dossier, historie).
 */
final class FloorLevelLabels
{
    public static function label(string $raw): ?string
    {
        return match ($raw) {
            'basement' => 'Kelder / souterrain',
            'ground' => 'Begane grond',
            '1' => '1e verdieping',
            '2' => '2e verdieping',
            '3_plus' => '3e verdieping of hoger',
            'attic' => 'Zolder',
            default => null,
        };
    }

    /**
     * Korte labelvorm voor kamernamen (zonder hoofdletter).
     */
    public static function shortLabel(string $raw): ?string
    {
        return match ($raw) {
            'basement' => 'kelder / souterrain',
            'ground' => 'begane grond',
            '1' => '1e verdieping',
            '2' => '2e verdieping',
            '3_plus' => '3e verdieping of hoger',
            'attic' => 'zolder',
            default => null,
        };
    }
}
