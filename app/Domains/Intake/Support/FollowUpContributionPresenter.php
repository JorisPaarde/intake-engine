<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Actions\ApplyFollowUpTextContribution;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use Illuminate\Support\Collection;

/**
 * Presenter for “Nieuwe aanvulling ontvangen” on the installer workspace (BL-146).
 *
 * Shows evidence + AI facts + what the installer still decides, per completed
 * contribution item — next to the dossier part, not only in the general gallery.
 */
final class FollowUpContributionPresenter
{
    /**
     * @return array{
     *     has_new: bool,
     *     banner_label: string|null,
     *     items: list<array{
     *         task: ContributionTask,
     *         round_number: int,
     *         decision_area_key: string|null,
     *         dossier_subject_id: int|null,
     *         prompt: string,
     *         response_text: string|null,
     *         uploads: list<IntakeUpload>,
     *         ai_facts: list<string>,
     *         installer_decides: string,
     *         review_href: string
     *     }>
     * }
     */
    public function present(Intake $intake): array
    {
        $intake->loadMissing([
            'contributionTasks.followUpItem.uploads',
            'contributionTasks.followUpItem.round',
            'followUpRounds.items.uploads',
        ]);

        $completedRounds = $intake->followUpRounds
            ->filter(static fn (IntakeFollowUpRound $round): bool => $round->status === FollowUpRoundStatus::Completed
                && $round->purpose === 'contribution')
            ->sortByDesc('round_number');

        $latestRound = $completedRounds->first();
        if (! $latestRound instanceof IntakeFollowUpRound) {
            return [
                'has_new' => false,
                'banner_label' => null,
                'items' => [],
            ];
        }

        /** @var Collection<int, ContributionTask> $tasks */
        $tasks = $intake->contributionTasks
            ->filter(static function (ContributionTask $task) use ($latestRound): bool {
                if ($task->status !== ContributionTaskStatus::Completed) {
                    return false;
                }

                $item = $task->followUpItem;
                if ($item === null) {
                    return false;
                }

                return (int) $item->intake_follow_up_round_id === (int) $latestRound->id;
            })
            ->values();

        $items = [];
        foreach ($tasks as $task) {
            $item = $task->followUpItem;
            if ($item === null) {
                continue;
            }

            $uploads = $item->uploads->all();
            $aiFacts = [];
            foreach ($uploads as $upload) {
                $assessment = $upload->contentAssessment();
                if ($assessment instanceof PhotoContentAssessment) {
                    $label = $assessment->installerLabel();
                    if (is_string($label) && $label !== '') {
                        $aiFacts[] = $label;
                    } elseif ($assessment->status() === PhotoContentAssessment::STATUS_OK) {
                        $aiFacts[] = 'Bruikbaar bewijs';
                    }
                }
            }

            if ($item->type === FollowUpItemType::Text && is_string($item->response_text) && trim($item->response_text) !== '') {
                $heightProposal = DossierRecord::query()
                    ->where('intake_id', $intake->id)
                    ->where('key', ApplyFollowUpTextContribution::RECORD_KEY_HEIGHT)
                    ->where('source_type', 'intake_follow_up_item')
                    ->where('source_id', $item->id)
                    ->where('status', DossierRecordStatus::Proposed)
                    ->whereNull('superseded_by_id')
                    ->first();

                if ($heightProposal !== null) {
                    $aiFacts[] = 'Hoogteantwoord als voorstel — nog te bevestigen';
                }
            }

            $items[] = [
                'task' => $task,
                'round_number' => (int) $latestRound->round_number,
                'decision_area_key' => $task->decision_area_key,
                'dossier_subject_id' => $task->dossier_subject_id,
                'prompt' => $item->prompt,
                'response_text' => $item->response_text,
                'uploads' => $uploads,
                'ai_facts' => array_values(array_unique($aiFacts)),
                'installer_decides' => $this->installerDecidesCopy($task->decision_area_key),
                'review_href' => $this->reviewHref($task),
            ];
        }

        return [
            'has_new' => $items !== [],
            'banner_label' => $items !== [] ? 'Nieuwe aanvulling ontvangen' : null,
            'items' => $items,
        ];
    }

    /**
     * Contributions linked to a specific dossier subject (room / connection / root).
     *
     * @return list<array<string, mixed>>
     */
    public function forSubject(Intake $intake, ?int $subjectId): array
    {
        if ($subjectId === null) {
            return [];
        }

        return array_values(array_filter(
            $this->present($intake)['items'],
            static fn (array $item): bool => (int) ($item['dossier_subject_id'] ?? 0) === $subjectId,
        ));
    }

    /**
     * Contributions for a decision area (placement / power / capacity, …).
     *
     * @return list<array<string, mixed>>
     */
    public function forDecisionArea(Intake $intake, string $areaKey): array
    {
        return array_values(array_filter(
            $this->present($intake)['items'],
            static fn (array $item): bool => ($item['decision_area_key'] ?? null) === $areaKey,
        ));
    }

    private function installerDecidesCopy(?string $decisionAreaKey): string
    {
        return match ($decisionAreaKey) {
            'power' => 'Jij beslist of de meterkast leesbaar genoeg is voor 1- of 3-fase en de technische route.',
            'placement' => 'Jij beslist over unitplaats en montage; dit bewijs vult alleen de gevel-/tuincontext.',
            'capacity' => 'Jij beslist welke hoogte voor capaciteit telt — niet blind het hoogste punt overnemen.',
            'refrigerant', 'condensate' => 'Jij beslist over de technische route en of er nog een controle op locatie nodig is.',
            default => 'Jij beslist wat dit bewijs betekent voor offerte en plaatsing.',
        };
    }

    private function reviewHref(ContributionTask $task): string
    {
        if ($task->dossier_subject_id !== null) {
            $roomId = $task->subject?->meta['airco_room_id'] ?? null;
            // Room subjects use key airco.room.* — link via area when room unknown.
        }

        return match ($task->decision_area_key) {
            'capacity' => '#workspace-rooms',
            'placement' => '#dossier-area-placement',
            'power' => '#dossier-area-power',
            'refrigerant' => '#dossier-area-refrigerant',
            'condensate' => '#dossier-area-condensate',
            default => '#workspace-open-items',
        };
    }
}
