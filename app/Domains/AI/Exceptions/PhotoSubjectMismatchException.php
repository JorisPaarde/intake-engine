<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use App\Domains\AI\Support\PhotoSubject;
use Illuminate\Validation\ValidationException;

/**
 * De foto hoort niet bij het type dossieronderdeel waarvoor de AI-constatering
 * is bedoeld. Draagt het gestructureerde `detected_subject` (indien bekend) voor
 * een zachte hint; nooit afgeleid uit tekst.
 */
final class PhotoSubjectMismatchException extends ValidationException
{
    public ?PhotoSubject $detectedSubject = null;

    public static function forDetected(?PhotoSubject $detected): self
    {
        $exception = self::withMessages([
            'photo' => 'Deze foto hoort niet bij dit onderdeel van de opname.',
        ]);
        $exception->detectedSubject = $detected;

        return $exception;
    }
}
