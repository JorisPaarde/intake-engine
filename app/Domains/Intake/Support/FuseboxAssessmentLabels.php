<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Single source of Dutch labels for fusebox assessment enum values
 * (ExternalFactPresenter + SchemaEnumLabelGuard). Rendering our own schema
 * tokens is not text interpretation (ADR-0016).
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

    /**
     * Content-assessment status → short Dutch phrase for installer-facing copy.
     * `ok` has no dedicated phrase (absence of a problem is not labeled).
     */
    public static function contentAssessmentStatus(string $status): ?string
    {
        return match ($status) {
            'needs_clearer' => 'scherpere foto gewenst',
            'wrong_subject' => 'verkeerd onderwerp',
            'not_assessed' => 'nog niet automatisch beoordeeld',
            default => null,
        };
    }
}
