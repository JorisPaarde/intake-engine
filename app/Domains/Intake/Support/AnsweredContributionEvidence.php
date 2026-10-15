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
        // General tasks ("Algemene opname") have no area; match other area-less tasks.
        $area = is_string($area) && $area !== '' ? $area : null;

        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        // Once meta is normalized it is authoritative: a discarded subtype means ambiguous overlap.
        $subtype = array_key_exists('meta', $item)
            ? ($meta['photo_task_subtype'] ?? null)
            : ($item['photo_task_subtype'] ?? null);
        $field = $meta['requested_field'] ?? null;
        $tasks = ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('audience', ContributionAudience::Customer)
            ->where('type', $type)
            ->when(
                $area === null,
                static fn ($query) => $query->where(static fn ($inner) => $inner
                    ->whereNull('decision_area_key')
                    ->orWhere('decision_area_key', '')),
                static fn ($query) => $query->where('decision_area_key', $area),
            )
            // An absent subject link on either side is ambiguous overlap; explicit subjects must match.
            ->when(
                is_numeric($item['dossier_subject_id'] ?? null),
                static fn ($query) => $query->where(static fn ($inner) => $inner
                    ->whereNull('dossier_subject_id')
                    ->orWhere('dossier_subject_id', (int) $item['dossier_subject_id'])),
            )
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
