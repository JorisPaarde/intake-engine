<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Het dossieronderdeel heeft geen AI-constatering (alleen ruimtes/plekken).
 * De foto is opgeslagen; de aanroeper toont geen afwijzing.
 */
final class PhotoSubjectMismatchException extends ValidationException
{
    public static function unsupportedSubject(): self
    {
        return self::withMessages([
            'photo' => 'Deze foto hoort niet bij dit onderdeel van de opname.',
        ]);
    }
}
