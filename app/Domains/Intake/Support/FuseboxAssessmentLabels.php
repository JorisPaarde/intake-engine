<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Single source of Dutch labels for fusebox assessment enum values
 * (ExternalFactPresenter deterministic display). Rendering our own schema
 * tokens in presenters is not text interpretation (ADR-0016).
 */
final class FuseboxAssessmentLabels
{
    public static function emptyModuleSpace(mixed $value): string
    {
        $normalized = self::normalizeEmptyModuleSpace($value);

        return match ($normalized) {
            'visible' => 'Vrije moduleplek lijkt zichtbaar',
            'none_visible' => 'Geen vrije moduleplek zichtbaar (groepenkast mogelijk vol)',
            default => 'Vrije moduleplek niet betrouwbaar te bepalen',
        };
    }

    public static function phase(mixed $value): string
    {
        return match ($value) {
            'one_phase' => '1-fase lijkt zichtbaar',
            'three_phase' => '3-fase lijkt zichtbaar',
            default => 'fase niet betrouwbaar te bepalen',
        };
    }

    /**
     * Map legacy free_group yes/no onto empty_module_space enums.
     */
    public static function normalizeEmptyModuleSpace(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return match ($value) {
            'yes' => 'visible',
            'no' => 'none_visible',
            'visible', 'none_visible', 'unknown' => $value,
            default => $value,
        };
    }
}
