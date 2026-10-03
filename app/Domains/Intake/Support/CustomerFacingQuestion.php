<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;

/**
 * Bepaalt of een vraag in de klantwizard/compleetheid hoort.
 *
 * Technische beslissingen (ADR-0015 / BL-116) vallen af via TechnicalDecisionKeys.
 */
final class CustomerFacingQuestion
{
    public static function isCustomerFacing(IntakeQuestion $question): bool
    {
        return ! TechnicalDecisionKeys::contains($question->key);
    }
}
