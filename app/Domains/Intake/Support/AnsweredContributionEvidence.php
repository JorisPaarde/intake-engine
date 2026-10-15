<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Enums\ContributionAudience;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;

final class AnsweredContributionEvidence
{
    /**
     * Warn about overlap, without inferring task meaning from its wording.
     * Missing subtype/field means the scope is ambiguous, so warn conservatively.
     *
     * @param  array<string, mixed>  $item
     */
    public function warning(Intake $intake, array $item): ?string
    {
        $type = $item['type'] ?? null;
        $type = $type instanceof FollowUpItemType ? $type->value : $type;
        $area = $item['decision_area_key'] ?? null;
        if (! is_string($area) || $area === '') {
            return null;
        }

        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        $subtype = $item['photo_task_subtype'] ?? ($meta['photo_task_subtype'] ?? null);
        $field = $meta['requested_field'] ?? null;
        $tasks = ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('audience', ContributionAudience::Customer)
            ->where('type', $type)
            ->where('decision_area_key', $area)
            ->where('dossier_subject_id', $item['dossier_subject_id'] ?? null)
            ->with('followUpItem.round', 'followUpItem.uploads')
            ->get();

        foreach ($tasks as $task) {
            $answer = $task->followUpItem;
            if ($answer?->answered_at === null || $answer->round->status !== FollowUpRoundStatus::Completed) {
                continue;
            }
            if ($subtype !== null && isset($task->meta['photo_task_subtype'])
                && $subtype !== $task->meta['photo_task_subtype']) {
                continue;
            }
            if ($field !== null && isset($task->meta['requested_field'])
                && $field !== $task->meta['requested_field']) {
                continue;
            }

            $usable = match ($type) {
                FollowUpItemType::Photo->value => $answer->uploads->contains(static fn ($upload): bool => $upload->contentAssessment()?->status() === PhotoContentAssessment::STATUS_OK),
                FollowUpItemType::Document->value => $answer->uploads->contains(static fn ($upload): bool => $upload->mime_type === 'application/pdf'
                    && $upload->size_bytes > 0
                    && $upload->isDossierEvidenceEligible()),
                default => filled($answer->response_text),
            };
            if ($usable) {
                return 'Voor dit onderdeel is in klantronde '.$answer->round->round_number.' al een antwoord met bruikbaar bewijs ontvangen. Controleer dit voordat je opnieuw iets vraagt.';
            }
        }

        return null;
    }
}
