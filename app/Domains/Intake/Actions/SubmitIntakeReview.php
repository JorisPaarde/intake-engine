<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeReview;
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
     *     summary?: string|null
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

        return DB::transaction(function () use ($intake, $reviewer, $data, $decision): IntakeReview {
            $reviewedAt = now();

            $review = IntakeReview::query()->updateOrCreate(
                ['intake_id' => $intake->id],
                [
                    'reviewer_id' => $reviewer->id,
                    'decision' => $decision,
                    'site_visit_needed' => (bool) ($data['site_visit_needed'] ?? ($decision === ReviewDecision::SiteVisitNeeded)),
                    'enough_information' => (bool) ($data['enough_information'] ?? ($decision !== ReviewDecision::NeedMoreInfo)),
                    'summary' => isset($data['summary']) ? trim((string) $data['summary']) : null,
                    'reviewed_at' => $reviewedAt,
                ],
            );

            $intake->update([
                'status' => IntakeStatus::Reviewed,
                'reviewed_at' => $reviewedAt,
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $reviewer->id,
                'event' => 'intake_reviewed',
                'properties' => [
                    'decision' => $decision->value,
                ],
                'created_at' => now(),
            ]);

            return $review;
        });
    }
}
