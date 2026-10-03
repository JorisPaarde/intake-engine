<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;

/**
 * Questions that drive engine state (extra photo / AI clarity) but must never
 * appear as customer wizard steps — even when AI status is unknown or the
 * step list is rebuilt mid-flow (klanttest 2026-10-02).
 *
 * Alleen de KEYS-lijst; geen parallelle meta['internal']-route.
 */
final class InternalCustomerQuestions
{
    /** @var list<string> */
    public const KEYS = [
        'fusebox_clarity',
        'room_outlet_status',
    ];

    public static function hidesFromCustomer(IntakeQuestion $question): bool
    {
        return self::isInternalKey($question->key);
    }

    public static function isInternalKey(string $questionKey): bool
    {
        return in_array($questionKey, self::KEYS, true);
    }
}
