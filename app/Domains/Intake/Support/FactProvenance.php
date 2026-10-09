<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Herkomst van een geëxtraheerd of afgeleid feit (prod-test intakes 82/84/86).
 *
 * - stated: de klant/aanvrager zei het letterlijk
 * - inferred: AI of code leidde het af (geen letterlijke uitspraak)
 * - unknown: geen bruikbare herkomst — niet als bevestigd behandelen
 */
enum FactProvenance: string
{
    case Stated = 'stated';
    case Inferred = 'inferred';
    case Unknown = 'unknown';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return self::tryFrom($normalized);
    }

    public function installerLabel(): string
    {
        return match ($this) {
            self::Stated => 'gezegd',
            self::Inferred => 'aanname',
            self::Unknown => 'onbekend',
        };
    }
}
