<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Actions\ApplyFollowUpTextContribution;
use App\Domains\Intake\Models\AircoRoom;
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
use Illuminate\Support\Str;

/**
 * Presenter for “Nieuwe aanvulling ontvangen” on the installer workspace (BL-146).
 *
 * Shows evidence + AI facts + what the installer still decides, per completed
 * contribution item — next to the dossier part, not only in the general gallery.
 */
final class FollowUpContributionPresenter
{
    private const KEY_LENGTH_WIDTH = 'length_width';

    private const KEY_HEIGHT = 'height';

    private const KEY_AREA = 'area';

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
     *         review_href: string,
     *         review_label: string,
     *         room_id: int|null,
     *         room_name: string|null,
     *         highlight_field_ids: list<string>
     *     }>
     * }
     */
    public function present(Intake $intake): array
    {
        $intake->loadMissing([
            'aircoRooms',
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

            $room = $this->roomFor($intake, $task);
            $requestedKey = $this->requestedKey($task, $item->prompt);

            $items[] = [
                'task' => $task,
                'round_number' => (int) $latestRound->round_number,
                'decision_area_key' => $task->decision_area_key,
                'dossier_subject_id' => $task->dossier_subject_id,
                'prompt' => $item->prompt,
                'response_text' => $item->response_text,
                'uploads' => $uploads,
                'ai_facts' => array_values(array_unique($aiFacts)),
                'installer_decides' => $this->installerDecidesCopy($task->decision_area_key, $requestedKey),
                'review_href' => $room !== null ? '#room-'.$room->id : $this->reviewHref($task),
                'review_label' => $room !== null ? 'Beoordeel bij '.$room->name : 'Beoordeel in opname',
                'room_id' => $room?->id,
                'room_name' => $room?->name,
                'highlight_field_ids' => $room !== null ? $this->highlightFieldIds($room, $requestedKey) : [],
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

    /**
     * Capacity hints follow the requested key of the task (UX-uitkomst #15.2), not only the area.
     */
    private function installerDecidesCopy(?string $decisionAreaKey, ?string $requestedKey): string
    {
        if ($decisionAreaKey === 'capacity') {
            return match ($requestedKey) {
                self::KEY_LENGTH_WIDTH => 'Jij beslist of deze maten kloppen voor de capaciteit.',
                self::KEY_HEIGHT => 'Jij beslist welke hoogte telt voor de capaciteit.',
                self::KEY_AREA => 'Jij beslist of dit oppervlak klopt voor de capaciteit.',
                default => 'Jij beslist wat dit betekent voor de capaciteit.',
            };
        }

        return match ($decisionAreaKey) {
            'power' => 'Jij beslist of de meterkast leesbaar genoeg is voor 1- of 3-fase en de technische route.',
            'placement' => 'Jij beslist over unitplaats en montage; dit bewijs vult alleen de gevel-/tuincontext.',
            'refrigerant', 'condensate' => 'Jij beslist over de technische route en of er nog een controle op locatie nodig is.',
            default => 'Jij beslist wat dit bewijs betekent voor offerte en plaatsing.',
        };
    }

    /**
     * Requested key: explicit task meta first, otherwise derived from the (editable) prompt.
     */
    private function requestedKey(ContributionTask $task, string $itemPrompt): ?string
    {
        if ($task->decision_area_key !== 'capacity') {
            return null;
        }

        $meta = is_array($task->meta) ? $task->meta : [];
        $fromMeta = $meta[ApplyFollowUpTextContribution::META_REQUESTED_FIELD] ?? null;
        if ($fromMeta === ApplyFollowUpTextContribution::FIELD_HEIGHT) {
            return self::KEY_HEIGHT;
        }

        $prompt = Str::lower($itemPrompt.' '.$task->prompt);

        return match (true) {
            Str::contains($prompt, ['lengte', 'breedte']) => self::KEY_LENGTH_WIDTH,
            Str::contains($prompt, ['hoogte', 'knieschot', 'nok', 'schuin dak']) => self::KEY_HEIGHT,
            Str::contains($prompt, ['oppervlak', 'm²', 'm2']) => self::KEY_AREA,
            default => null,
        };
    }

    private function roomFor(Intake $intake, ContributionTask $task): ?AircoRoom
    {
        if ($task->dossier_subject_id === null) {
            return null;
        }

        $room = $intake->aircoRooms->first(
            static fn (AircoRoom $room): bool => $room->dossier_subject_id === $task->dossier_subject_id,
        );

        return $room instanceof AircoRoom ? $room : null;
    }

    /**
     * Field ids in the room card (workspace) that light up after “Beoordeel bij (ruimte)”.
     *
     * @return list<string>
     */
    private function highlightFieldIds(AircoRoom $room, ?string $requestedKey): array
    {
        $prefix = 'room-'.$room->id;

        return match ($requestedKey) {
            self::KEY_LENGTH_WIDTH => [$prefix.'-length', $prefix.'-width'],
            self::KEY_HEIGHT => [$prefix.'-height'],
            self::KEY_AREA => [$prefix.'-area'],
            default => [],
        };
    }

    private function reviewHref(ContributionTask $task): string
    {
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
