<?php

declare(strict_types=1);

namespace App\Domains\Photos\Actions;

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\UploadSupersessionResolver;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\PhotoAssessmentStatus;

/**
 * Ensures meterkast analysis and AI clarity proposals follow the newest fusebox
 * evidence — including follow-up uploads that arrived under a power/fusebox task.
 *
 * Follow-up photos use question_key follow_up_{id}; without remapping them into
 * the fusebox assessment set, AssessFuseboxPhotos keeps judging the old wizard
 * photo (N49).
 */
final class RefreshFuseboxAssessmentFromEvidence
{
    public function __construct(
        private readonly AssessFuseboxPhotos $assessFuseboxPhotos,
    ) {}

    /**
     * Remap eligible follow-up fusebox uploads onto the fusebox_photo question key
     * (keeping intake_follow_up_item_id) and re-run assessment so clarity / facts
     * track the newest usable meterkast photo.
     */
    public function handle(Intake $intake, ?IntakeFollowUpRound $round = null): void
    {
        $intake->loadMissing([
            'uploads.followUpItem.round',
            'contributionTasks',
            'followUpRounds.items.uploads',
        ]);

        $needsRefresh = false;
        foreach ($this->candidateFollowUpUploads($intake, $round) as $upload) {
            if (! $this->isFuseboxEvidence($intake, $upload)) {
                continue;
            }

            $updates = [];
            if ($upload->question_key !== 'fusebox_photo'
                && $upload->question_key !== 'fusebox_photo_extra') {
                $updates['question_key'] = 'fusebox_photo';
            }

            // Subject-check already marked OK; clear so fusebox phase/clarity runs.
            if ($upload->content_assessment !== null) {
                $updates['content_assessment'] = null;
                $updates['assessment_status'] = PhotoAssessmentStatus::Pending;
            }

            if ($updates !== []) {
                $upload->update($updates);
                $needsRefresh = true;
            }
        }

        if (! $needsRefresh && ! $this->hasCurrentFuseboxPhoto($intake)) {
            return;
        }

        if (! $needsRefresh) {
            // Wizard replace path: still reassess so superseded extras / stale clarity drop.
            $needsRefresh = $this->hasSupersededFuseboxEvidence($intake);
        }

        if (! $needsRefresh) {
            return;
        }

        $this->assessFuseboxPhotos->handle($intake->fresh() ?? $intake);
    }

    /**
     * @return list<IntakeUpload>
     */
    private function candidateFollowUpUploads(Intake $intake, ?IntakeFollowUpRound $round): array
    {
        $uploads = [];

        if ($round instanceof IntakeFollowUpRound) {
            $round->loadMissing('items.uploads');
            foreach ($round->items as $item) {
                if ($item->type !== FollowUpItemType::Photo) {
                    continue;
                }
                foreach ($item->uploads as $upload) {
                    $uploads[] = $upload;
                }
            }

            return $uploads;
        }

        foreach ($intake->followUpRounds as $followUpRound) {
            if ($followUpRound->status === FollowUpRoundStatus::Open) {
                continue;
            }
            foreach ($followUpRound->items as $item) {
                if ($item->type !== FollowUpItemType::Photo) {
                    continue;
                }
                foreach ($item->uploads as $upload) {
                    $uploads[] = $upload;
                }
            }
        }

        return $uploads;
    }

    private function isFuseboxEvidence(Intake $intake, IntakeUpload $upload): bool
    {
        $assessment = $upload->contentAssessment();
        $detected = $assessment?->detectedSubject();
        if ($detected === PhotoSubject::Fusebox) {
            return true;
        }

        $task = $intake->contributionTasks
            ->first(static fn (ContributionTask $candidate): bool => $candidate->intake_follow_up_item_id === $upload->intake_follow_up_item_id);

        $accepted = PhotoSubject::acceptedSubjectsForTask($task);
        if (is_array($accepted) && count($accepted) === 1 && $accepted[0] === PhotoSubject::Fusebox) {
            return true;
        }

        $subtype = is_array($task?->meta) ? ($task->meta['photo_task_subtype'] ?? null) : null;

        return $subtype === 'fusebox';
    }

    private function hasCurrentFuseboxPhoto(Intake $intake): bool
    {
        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);

        foreach ($intake->uploads as $upload) {
            if (! in_array($upload->question_key, ['fusebox_photo', 'fusebox_photo_extra'], true)) {
                continue;
            }

            $info = $supersessions[(int) $upload->id] ?? null;
            if (is_array($info) && $info['superseded'] === true) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function hasSupersededFuseboxEvidence(Intake $intake): bool
    {
        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);

        foreach ($intake->uploads as $upload) {
            if (! in_array($upload->question_key, ['fusebox_photo', 'fusebox_photo_extra'], true)) {
                continue;
            }

            $info = $supersessions[(int) $upload->id] ?? null;
            if (is_array($info) && $info['superseded'] === true) {
                return true;
            }
        }

        return false;
    }
}
