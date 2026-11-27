<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;

/**
 * Eén definitie van “geen eigen klantstap” voor step-builder én voortgang.
 *
 * - vaste engine-keys ({@see InternalCustomerQuestions})
 * - meta `audience: installer|internal` of `internal: true` (AI/dossier/vervolgvraag wél)
 * - meta `companion_of`: getoond op het scherm van een andere vraag (BL-172 gevelkant bij kamerfoto)
 */
final class CustomerAudience
{
    public static function hidesFromCustomer(IntakeQuestion $question): bool
    {
        if (InternalCustomerQuestions::hidesFromCustomer($question)) {
            return true;
        }

        $meta = is_array($question->meta) ? $question->meta : [];
        $audience = is_string($meta['audience'] ?? null) ? $meta['audience'] : null;

        return ($meta['internal'] ?? false) === true
            || $audience === 'installer'
            || $audience === 'internal'
            || self::companionOf($question) !== null;
    }

    /**
     * Key van de vraag op wiens scherm deze vraag meekomt, of null.
     */
    public static function companionOf(IntakeQuestion $question): ?string
    {
        $meta = is_array($question->meta) ? $question->meta : [];
        $primary = $meta['companion_of'] ?? null;

        return is_string($primary) && $primary !== '' ? $primary : null;
    }
}
