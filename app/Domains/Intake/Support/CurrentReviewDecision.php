<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeReview;
use App\Enums\ReviewDecision;

/**
 * Current installer review decision for UI copy.
 *
 * After a follow-up round or a new survey completion, `reviewed_at` is cleared
 * while the old IntakeReview row may remain — same rule as GenerateIntakeReportHtml.
 */
final class CurrentReviewDecision
{
    public static function for(Intake $intake): ?ReviewDecision
    {
        if ($intake->reviewed_at === null) {
            return null;
        }

        if (! $intake->relationLoaded('review') && $intake->exists) {
            $intake->loadMissing('review');
        }

        $review = $intake->relationLoaded('review')
            ? $intake->getRelation('review')
            : $intake->review;

        if (! $review instanceof IntakeReview) {
            return null;
        }

        $decision = $review->decision;
        if ($decision === ReviewDecision::Pending) {
            return null;
        }

        return $decision;
    }

    public static function isPrepareQuote(Intake $intake): bool
    {
        return self::for($intake) === ReviewDecision::PrepareQuote;
    }
}
