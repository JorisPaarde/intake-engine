<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpItemType;
use Illuminate\Support\Collection;

/**
 * Per-round installer review of follow-up photo evidence (BL-130 / BL-146).
 *
 * A wrong-subject upload is superseded when a later round with the same evidence
 * scope (decision area + dossier subject + expected photo subject) has usable
 * solving evidence. Matching only on decision area is too broad when multiple
 * rooms or placement asks share one area key.
 */
final class FollowUpEvidenceReview
{
    /**
     * @param  Collection<int, IntakeFollowUpRound>  $rounds
     * @return array{
     *     rounds: list<array{
     *         round: IntakeFollowUpRound,
     *         items: list<array{
     *             item: IntakeFollowUpItem,
     *             uploads: list<array{
     *                 upload: IntakeUpload,
     *                 assessment: PhotoContentAssessment|null,
     *                 installer_label: string|null,
     *                 superseded: bool,
     *                 supersession_label: string|null
     *             }>
     *         }>
     *     }>
     * }
     */
    public function present(Intake $intake, Collection $rounds): array
    {
        $intake->loadMissing(['contributionTasks']);
        $solvingRoundByScope = $this->latestSolvingRoundByScope($intake, $rounds);

        $presented = [];

        foreach ($rounds->sortBy('round_number') as $round) {
            $items = [];

            foreach ($round->items as $item) {
                $scopeKey = $this->evidenceScopeKey($intake, $item);
                $uploads = [];

                foreach ($item->uploads as $upload) {
                    $assessment = $upload->contentAssessment();
                    $superseded = false;

                    if ($assessment instanceof PhotoContentAssessment
                        && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                        && is_string($scopeKey)
                        && isset($solvingRoundByScope[$scopeKey])
                        && $solvingRoundByScope[$scopeKey] > (int) $round->round_number) {
                        $superseded = true;
                    }

                    // After a solving contribution, do not let the old mismatch
                    // label stay dominant in the installer review.
                    $installerLabel = $superseded ? null : $assessment?->installerLabel();

                    $uploads[] = [
                        'upload' => $upload,
                        'assessment' => $assessment,
                        'installer_label' => $installerLabel,
                        'superseded' => $superseded,
                        'supersession_label' => $superseded
                            ? 'Vervangen door nieuwere ronde'
                            : null,
                    ];
                }

                $items[] = [
                    'item' => $item,
                    'uploads' => $uploads,
                    'scope_key' => $scopeKey,
                ];
            }

            $presented[] = [
                'round' => $round,
                'items' => $items,
            ];
        }

        return ['rounds' => $presented];
    }

    /**
     * @param  Collection<int, IntakeFollowUpRound>  $rounds
     * @return array<string, int> evidence_scope_key => latest solving round_number
     */
    private function latestSolvingRoundByScope(Intake $intake, Collection $rounds): array
    {
        $latest = [];

        foreach ($rounds as $round) {
            foreach ($round->items as $item) {
                if ($item->type !== FollowUpItemType::Photo) {
                    continue;
                }

                $scopeKey = $this->evidenceScopeKey($intake, $item);
                if ($scopeKey === null) {
                    continue;
                }

                foreach ($item->uploads as $upload) {
                    $assessment = $upload->contentAssessment();
                    if ($assessment instanceof PhotoContentAssessment
                        && $assessment->status() === PhotoContentAssessment::STATUS_OK) {
                        $roundNumber = (int) $round->round_number;
                        $latest[$scopeKey] = max($latest[$scopeKey] ?? 0, $roundNumber);
                    }
                }
            }
        }

        return $latest;
    }

    /**
     * Stable scope for supersession: area + subject + expected photo subject.
     * Falls back to area alone only when subject and expected subject are unknown.
     */
    private function evidenceScopeKey(Intake $intake, IntakeFollowUpItem $item): ?string
    {
        $task = $this->taskForItem($intake, $item);
        $areaKey = is_string($task?->decision_area_key) && $task->decision_area_key !== ''
            ? $task->decision_area_key
            : null;

        if ($areaKey === null) {
            return null;
        }

        $subjectId = $task->dossier_subject_id;
        $expected = PhotoSubject::expectedFromDecisionArea($areaKey);

        // Prefer expected subject from a stored assessment when the area itself
        // is not category-checkable (e.g. placement facade asks).
        if ($expected === null) {
            foreach ($item->uploads as $upload) {
                $assessment = $upload->contentAssessment();
                if ($assessment instanceof PhotoContentAssessment
                    && $assessment->expectedSubject() instanceof PhotoSubject) {
                    $expected = $assessment->expectedSubject();
                    break;
                }
            }
        }

        $parts = [$areaKey];
        $parts[] = $subjectId !== null ? 'subject:'.$subjectId : 'subject:none';
        $parts[] = $expected instanceof PhotoSubject ? 'photo:'.$expected->value : 'photo:any';

        return implode('|', $parts);
    }

    private function taskForItem(Intake $intake, IntakeFollowUpItem $item): ?ContributionTask
    {
        return $intake->contributionTasks
            ->first(static fn (ContributionTask $task): bool => $task->intake_follow_up_item_id === $item->id);
    }
}
