<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeReview;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\ReviewDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitIntakeReview
{
    /**
     * @param  array{
     *     decision: ReviewDecision|string,
     *     site_visit_needed?: bool,
     *     enough_information?: bool,
     *     summary?: string|null,
     *     follow_up_items?: list<array{type: FollowUpItemType|string, prompt: string}>
     * }  $data
     */
    public function handle(Intake $intake, User $reviewer, array $data): IntakeReview
    {
        if (! in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)) {
            throw ValidationException::withMessages([
                'intake' => 'Alleen afgeronde opnames kunnen worden beoordeeld.',
            ]);
        }

        $decision = $data['decision'] instanceof ReviewDecision
            ? $data['decision']
            : ReviewDecision::from((string) $data['decision']);

        if ($decision === ReviewDecision::Pending) {
            throw ValidationException::withMessages([
                'decision' => 'Kies een definitieve beoordeling.',
            ]);
        }

        $followUpItems = $data['follow_up_items'] ?? [];

        if ($decision === ReviewDecision::NeedMoreInfo && $followUpItems === []) {
            throw ValidationException::withMessages([
                'follow_up_items' => 'Voeg minimaal één concrete vraag, foto- of documentopdracht toe.',
            ]);
        }

        return DB::transaction(function () use ($intake, $reviewer, $data, $decision, $followUpItems): IntakeReview {
            $reviewedAt = now();

            $review = IntakeReview::query()->updateOrCreate(
                ['intake_id' => $intake->id],
                [
                    'reviewer_id' => $reviewer->id,
                    'decision' => $decision,
                    'site_visit_needed' => (bool) ($data['site_visit_needed'] ?? ($decision === ReviewDecision::SiteVisitNeeded)),
                    'enough_information' => $decision === ReviewDecision::NeedMoreInfo
                        ? false
                        : (bool) ($data['enough_information'] ?? true),
                    'summary' => isset($data['summary']) ? trim((string) $data['summary']) : null,
                    'reviewed_at' => $reviewedAt,
                ],
            );

            $intake->update([
                'status' => $decision === ReviewDecision::NeedMoreInfo
                    ? IntakeStatus::AwaitingCustomer
                    : IntakeStatus::Reviewed,
                'reviewed_at' => $reviewedAt,
            ]);

            if ($decision === ReviewDecision::NeedMoreInfo) {
                $this->createFollowUpRound($intake, $reviewer, $followUpItems);
            }

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $reviewer->id,
                'event' => 'intake_reviewed',
                'properties' => [
                    'decision' => $decision->value,
                    'enough_information' => $review->enough_information,
                ],
                'created_at' => now(),
            ]);

            return $review;
        });
    }

    /**
     * @param  list<array{type: FollowUpItemType|string, prompt: string}>  $items
     */
    private function createFollowUpRound(Intake $intake, User $reviewer, array $items): void
    {
        if ($intake->followUpRounds()->where('status', FollowUpRoundStatus::Open)->exists()) {
            throw ValidationException::withMessages([
                'follow_up_items' => 'Rond eerst de openstaande aanvullende ronde af.',
            ]);
        }

        $roundNumber = ((int) $intake->followUpRounds()->max('round_number')) + 1;
        $maxRounds = (int) config('intake.follow_up.max_rounds', 3);

        if ($roundNumber > $maxRounds) {
            throw ValidationException::withMessages([
                'follow_up_items' => "Maximaal {$maxRounds} aanvullende rondes toegestaan. Plan nu gericht contact of een locatiebezoek.",
            ]);
        }

        $round = IntakeFollowUpRound::query()->create([
            'intake_id' => $intake->id,
            'requested_by' => $reviewer->id,
            'round_number' => $roundNumber,
            'status' => FollowUpRoundStatus::Open,
            'sent_at' => now(),
        ]);

        foreach ($items as $item) {
            $type = $item['type'] instanceof FollowUpItemType
                ? $item['type']
                : FollowUpItemType::from((string) $item['type']);

            $round->items()->create([
                'type' => $type,
                'prompt' => trim($item['prompt']),
            ]);
        }

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'user',
            'actor_id' => $reviewer->id,
            'event' => 'follow_up_requested',
            'properties' => [
                'round_number' => $roundNumber,
                'item_count' => count($items),
                'item_types' => collect($items)
                    ->map(static fn (array $item): string => $item['type'] instanceof FollowUpItemType
                        ? $item['type']->value
                        : (string) $item['type'])
                    ->values()
                    ->all(),
            ],
            'created_at' => now(),
        ]);
    }
}
