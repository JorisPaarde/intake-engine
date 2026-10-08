<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Services\FollowUpProgressCalculator;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;

/**
 * Klanttaakstatus for dashboard/overview — not technical readiness.
 *
 * Prefill does not count as customer progress. Follow-up rounds use the same
 * numbers as the workspace (“Ronde N: X van Y ontvangen”).
 */
final class CustomerTaskStatusPresenter
{
    public function __construct(
        private readonly FollowUpProgressCalculator $followUpProgress,
    ) {}

    /**
     * @return array{
     *     label: string,
     *     short_label: string,
     *     link_active: bool,
     *     percent: int|null
     * }
     */
    public function present(Intake $intake): array
    {
        $intake->loadMissing(['followUpRounds.items', 'answers']);

        $linkActive = $intake->isTokenValid();

        $openRound = $intake->followUpRounds
            ->filter(static fn (IntakeFollowUpRound $round): bool => $round->status === FollowUpRoundStatus::Open
                && $round->purpose === 'contribution')
            ->sortByDesc('round_number')
            ->first();

        if ($openRound instanceof IntakeFollowUpRound) {
            $progress = $this->followUpProgress->calculate($openRound->items);
            $label = sprintf(
                'Ronde %d: %d van %d ontvangen',
                $openRound->round_number,
                $progress['completed'],
                $progress['total'],
            );

            return [
                'label' => $label,
                'short_label' => $label,
                'link_active' => $linkActive,
                'percent' => $progress['percent'],
            ];
        }

        $completedRound = $intake->followUpRounds
            ->filter(static fn (IntakeFollowUpRound $round): bool => $round->status === FollowUpRoundStatus::Completed
                && $round->purpose === 'contribution')
            ->sortByDesc('round_number')
            ->first();

        if ($completedRound instanceof IntakeFollowUpRound) {
            $total = $completedRound->items->count();
            $label = sprintf('Ronde %d: %d van %d ontvangen', $completedRound->round_number, $total, $total);

            return [
                'label' => $label,
                'short_label' => $label,
                'link_active' => $linkActive,
                'percent' => $total === 0 ? null : 100,
            ];
        }

        if (in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)
            && $intake->completed_at !== null) {
            return [
                'label' => 'Afgerond',
                'short_label' => 'Afgerond',
                'link_active' => $linkActive,
                'percent' => 100,
            ];
        }

        // Draft / installer-only without customer action: never show wizard % as klantvoortgang.
        if ($intake->status === IntakeStatus::Draft || ! $this->customerHasActed($intake)) {
            return [
                'label' => 'Nog niet gestart',
                'short_label' => 'Nog niet gestart',
                'link_active' => $linkActive,
                'percent' => 0,
            ];
        }

        $percent = max(0, min(100, (int) $intake->progress_percent));

        return [
            'label' => $percent.'% beantwoord',
            'short_label' => $percent.'% beantwoord',
            'link_active' => $linkActive,
            'percent' => $percent,
        ];
    }

    private function customerHasActed(Intake $intake): bool
    {
        return $intake->answers->contains(
            static function (IntakeAnswer $answer): bool {
                if ($answer->prefill_source !== null && $answer->prefill_source !== '') {
                    return false;
                }

                $value = is_array($answer->value) ? $answer->value : null;

                return $value !== null && $value !== [];
            },
        );
    }
}
