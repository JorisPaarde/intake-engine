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
     * @return array{label: string, link_active: bool}
     */
    public function present(Intake $intake): array
    {
        $intake->loadMissing(['followUpRounds.items.uploads']);

        $linkActive = $intake->isTokenValid();

        $openRound = $intake->followUpRounds
            ->filter(static fn (IntakeFollowUpRound $round): bool => $round->status === FollowUpRoundStatus::Open
                && $round->purpose === 'contribution')
            ->sortByDesc('round_number')
            ->first();

        if ($openRound instanceof IntakeFollowUpRound) {
            return [
                'label' => $this->roundLabel($openRound),
                'link_active' => $linkActive,
            ];
        }

        $completedRound = $intake->followUpRounds
            ->filter(static fn (IntakeFollowUpRound $round): bool => $round->status === FollowUpRoundStatus::Completed
                && $round->purpose === 'contribution')
            ->sortByDesc('round_number')
            ->first();

        if ($completedRound instanceof IntakeFollowUpRound) {
            return [
                'label' => $this->roundLabel($completedRound),
                'link_active' => $linkActive,
            ];
        }

        if (in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)
            && $intake->completed_at !== null) {
            return [
                'label' => 'Afgerond',
                'link_active' => $linkActive,
            ];
        }

        // Draft / installer-only without customer action: never show wizard % as klantvoortgang.
        if ($intake->status === IntakeStatus::Draft || ! $this->customerHasActed($intake)) {
            return [
                'label' => 'Nog niet gestart',
                'link_active' => $linkActive,
            ];
        }

        $percent = max(0, min(100, (int) $intake->progress_percent));

        return [
            'label' => $percent.'% beantwoord',
            'link_active' => $linkActive,
        ];
    }

    private function roundLabel(?IntakeFollowUpRound $round): string
    {
        if (! $round instanceof IntakeFollowUpRound) {
            return 'Nog niet gestart';
        }

        $progress = $this->followUpProgress->calculate($round->items);

        return sprintf(
            'Ronde %d: %d van %d ontvangen',
            $round->round_number,
            $progress['completed'],
            $progress['total'],
        );
    }

    private function customerHasActed(Intake $intake): bool
    {
        if (array_key_exists('has_customer_acted', $intake->getAttributes())) {
            return (bool) $intake->getAttribute('has_customer_acted');
        }

        $intake->loadMissing('answers');

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
