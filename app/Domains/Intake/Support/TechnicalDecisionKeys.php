<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Technische beslisvragen die de klant nooit mag/moet beantwoorden
 * (klanttest 2026-10-02 P0 / BL-116 / ADR-0015).
 *
 * Enige bron voor klantwizard-filter, zichtbaarheidsbypass in klantmodus,
 * open-puntenlogica, known-summary-uitsluiting en blokkade van tekst-prefill
 * op deze sleutels (BL-118).
 */
final class TechnicalDecisionKeys
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'natural_fall_possible',
            'pipe_route_description',
            'pipe_distance_indication',
            'drillings_needed',
            'free_group_known',
        ];
    }

    public static function contains(string $questionKey): bool
    {
        return in_array($questionKey, self::all(), true);
    }
}
