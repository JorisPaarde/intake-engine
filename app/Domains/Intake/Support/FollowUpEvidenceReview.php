<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpItemType;
use Illuminate\Support\Collection;

/**
 * Per-round installer review of follow-up photo evidence (BL-129).
 *
 * A wrong-subject upload is superseded when a later round (same decision area)
 * has usable solving evidence.
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
        $solvingRoundByArea = $this->latestSolvingRoundByArea($intake, $rounds);

        $presented = [];

        foreach ($rounds->sortBy('round_number') as $round) {
            $items = [];

            foreach ($round->items as $item) {
                $areaKey = $this->decisionAreaKey($intake, $item);
                $uploads = [];

                foreach ($item->uploads as $upload) {
                    $assessment = $upload->contentAssessment();
                    $superseded = false;

                    if ($assessment instanceof PhotoContentAssessment
                        && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                        && is_string($areaKey)
                        && isset($solvingRoundByArea[$areaKey])
                        && $solvingRoundByArea[$areaKey] > (int) $round->round_number) {
                        $superseded = true;
                    }

                    $uploads[] = [
                        'upload' => $upload,
                        'assessment' => $assessment,
                        'installer_label' => $assessment?->installerLabel(),
                        'superseded' => $superseded,
                        'supersession_label' => $superseded
                            ? 'Vervangen door nieuwere ronde'
                            : null,
                    ];
                }

                $items[] = [
                    'item' => $item,
                    'uploads' => $uploads,
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
     * @return array<string, int> decision_area_key => latest solving round_number
     */
    private function latestSolvingRoundByArea(Intake $intake, Collection $rounds): array
    {
        $latest = [];

        foreach ($rounds as $round) {
            foreach ($round->items as $item) {
                if ($item->type !== FollowUpItemType::Photo) {
                    continue;
                }

                $areaKey = $this->decisionAreaKey($intake, $item);
                if ($areaKey === null) {
                    continue;
                }

                foreach ($item->uploads as $upload) {
                    $assessment = $upload->contentAssessment();
                    if ($assessment instanceof PhotoContentAssessment
                        && $assessment->status() === PhotoContentAssessment::STATUS_OK) {
                        $roundNumber = (int) $round->round_number;
                        $latest[$areaKey] = max($latest[$areaKey] ?? 0, $roundNumber);
                    }
                }
            }
        }

        return $latest;
    }

    private function decisionAreaKey(Intake $intake, IntakeFollowUpItem $item): ?string
    {
        $task = $intake->contributionTasks
            ->first(static fn (ContributionTask $task): bool => $task->intake_follow_up_item_id === $item->id);

        $key = $task?->decision_area_key;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
