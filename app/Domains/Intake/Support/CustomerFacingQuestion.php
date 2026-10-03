<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;

/**
 * Bepaalt of een vraag in de klantwizard/compleetheid hoort.
 *
 * Technische beslissingen (ADR-0015 / BL-116) vallen af via de gedeelde
 * sleutellijst én via `meta.installer_decision` op nieuwere templates.
 */
final class CustomerFacingQuestion
{
    public static function isCustomerFacing(IntakeQuestion $question): bool
    {
        if (TechnicalDecisionKeys::contains($question->key)) {
            return false;
        }

        $meta = is_array($question->meta) ? $question->meta : [];

        return ($meta['installer_decision'] ?? false) !== true;
    }
}
