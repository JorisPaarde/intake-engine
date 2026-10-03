<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Risicovolle prefill-keys: afgeleide (inferred) waarden tellen nooit als bevestigd.
 * De klant moet ze bevestigen; in het installateursdossier staan ze als “aanname”.
 */
final class RiskRelevantPrefillKeys
{
    /** @var list<string> */
    public const KEYS = [
        'ownership',
        'noise_sensitive',
        'building_type',
        'natural_fall_possible',
        'pipe_route_description',
        'pipe_distance_indication',
        'drillings_needed',
        'free_group_known',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::KEYS;
    }

    public static function contains(string $questionKey): bool
    {
        return in_array($questionKey, self::KEYS, true)
            || TechnicalDecisionKeys::contains($questionKey);
    }

    /**
     * Inferred of unknown op een risicokey → voorstel, nooit confirmed/skip.
     */
    public static function requiresConfirmation(string $questionKey, FactProvenance $provenance): bool
    {
        if (! self::contains($questionKey)) {
            return false;
        }

        return $provenance !== FactProvenance::Stated;
    }
}
