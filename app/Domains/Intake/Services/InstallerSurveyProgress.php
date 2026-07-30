<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;

/**
 * Keeps workflow provenance and legacy progress fields aligned when an installer
 * contributes to the central survey dossier.
 */
final class InstallerSurveyProgress
{
    public function markStarted(Intake $intake): Intake
    {
        if ($intake->status === IntakeStatus::Cancelled) {
            return $intake;
        }

        $updates = [];

        if ($intake->workflow_mode === ContributionMode::Customer) {
            $updates['workflow_mode'] = ContributionMode::Hybrid;
        }

        if ($intake->status === IntakeStatus::Draft || $intake->status === IntakeStatus::Sent) {
            $updates['status'] = IntakeStatus::InProgress;
        }

        if ($intake->started_at === null) {
            $updates['started_at'] = now();
        }

        if ($updates !== []) {
            $intake->update($updates);
        }

        return $intake;
    }
}
