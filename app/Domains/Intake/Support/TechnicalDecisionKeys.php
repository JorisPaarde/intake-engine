<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Technische beslisvragen die de klant nooit mag/moet beantwoorden
 * (klanttest 2026-10-02 P0 / BL-116 / ADR-0015).
 *
 * Gedeelde bron voor klantwizard-filter, open-puntenlogica en (stroom 3)
 * blokkade van tekst-prefill op deze sleutels.
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

    /**
     * Prefill-bronnen die een AI-voorstel zijn, geen installateursbesluit.
     *
     * @return list<string>
     */
    public static function aiPrefillSources(): array
    {
        return ['ai', 'ai_suggestion'];
    }

    public static function isAiPrefillSource(?string $prefillSource): bool
    {
        return $prefillSource !== null
            && in_array($prefillSource, self::aiPrefillSources(), true);
    }

    public static function isInstallerPrefillSource(?string $prefillSource): bool
    {
        return $prefillSource === 'installer';
    }
}
