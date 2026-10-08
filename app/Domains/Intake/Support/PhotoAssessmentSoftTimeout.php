<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\PhotoAssessmentStatus;
use Carbon\CarbonInterface;

/**
 * Shared soft-timeout for customer photo gates (wizard + CompleteFollowUpRound).
 * Uses config('ai.photo_assessment.ui_soft_timeout_seconds') — same key as the UI.
 */
final class PhotoAssessmentSoftTimeout
{
    public static function seconds(): int
    {
        $configured = (int) config('ai.photo_assessment.ui_soft_timeout_seconds', 15);

        return $configured > 0 ? $configured : 15;
    }

    /**
     * True while a non-terminal photo is still younger than the soft-timeout.
     * After the soft-timeout the customer may continue; assessment finishes in the background.
     */
    public static function blocksCustomerProgress(IntakeUpload $upload): bool
    {
        $status = $upload->assessment_status;
        if ($status instanceof PhotoAssessmentStatus && $status->isTerminal()) {
            return false;
        }

        return ! self::hasElapsed($upload);
    }

    public static function hasElapsed(IntakeUpload $upload): bool
    {
        $anchor = $upload->assessment_queued_at ?? $upload->created_at;
        if (! $anchor instanceof CarbonInterface) {
            return false;
        }

        return now()->subSeconds(self::seconds())->gte($anchor);
    }
}
