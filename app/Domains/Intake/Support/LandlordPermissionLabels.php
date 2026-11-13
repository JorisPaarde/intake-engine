<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Stabiele weergavelabels voor landlord_permission (UX-review 13 nov, punt 8).
 *
 * Opgeslagen waarden blijven yes / not_asked / no (ook op oudere templateversies).
 * Dashboard, rapport en bedankoverzicht tonen altijd deze klantvriendelijke teksten.
 */
final class LandlordPermissionLabels
{
    public const QUESTION_KEY = 'landlord_permission';

    public const QUESTION_LABEL = 'Weet je verhuurder ervan?';

    /** Korter label op het bedankoverzicht (UX-review punt 3 / BL-169). */
    public const SUMMARY_LABEL = 'Toestemming verhuurder';

    public const HELP_TEXT = 'Bij een huurwoning is meestal toestemming nodig.';

    /**
     * @return array<string, string>
     */
    public static function valueLabels(): array
    {
        return [
            'yes' => 'Ja, die vindt het goed',
            'not_asked' => 'Nog niet gevraagd',
            'no' => 'Nee, die is tegen',
        ];
    }

    public static function forValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::valueLabels()[$value] ?? null;
    }
}
