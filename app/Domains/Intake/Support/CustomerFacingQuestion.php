<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;

/**
 * Technische beslissingen (pomp, route, boringen, stroomvoorziening) horen bij
 * AI-voorstel + installateur, niet bij de klantwizard (klanttest 2026-10-02 P0).
 */
final class CustomerFacingQuestion
{
    public static function isCustomerFacing(IntakeQuestion $question): bool
    {
        $meta = is_array($question->meta) ? $question->meta : [];

        return ($meta['installer_decision'] ?? false) !== true;
    }
}
