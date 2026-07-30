<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\InstallerSurveyProgress;
use App\Domains\Intake\Services\IntakeAccessTokenGenerator;
use App\Enums\ContributionAudience;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateCustomerContributionRequest
{
    public function __construct(
        private readonly DossierManager $dossierManager,
        private readonly InstallerSurveyProgress $surveyProgress,
        private readonly IntakeAccessTokenGenerator $tokenGenerator,
    ) {}

    /**
     * @param  list<array{
     *     type: mixed,
     *     prompt: mixed,
     *     decision_area_key?: mixed,
     *     dossier_subject_id?: mixed
     * }>  $items
     */
    public function handle(Intake $intake, User $requester, array $items): IntakeFollowUpRound
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'contribution_items' => 'Voeg minimaal één concrete klantopdracht toe.',
            ]);
        }

        $maxItems = (int) config('intake.follow_up.max_items_per_round', 5);

        if (count($items) > $maxItems) {
            throw ValidationException::withMessages([
                'contribution_items' => "Voeg maximaal {$maxItems} gerichte klantopdrachten per ronde toe.",
            ]);
        }

        $allowedDecisionAreas = [
            'request',
            'capacity',
            'placement',
            'refrigerant',
            'condensate',
            'power',
            'cost_risks',
            'quote',
        ];

        foreach ($items as $item) {
            if (trim((string) $item['prompt']) === ''
                || mb_strlen((string) $item['prompt']) > 500
                || (array_key_exists('dossier_subject_id', $item)
                    && $item['dossier_subject_id'] !== null
                    && ! is_numeric($item['dossier_subject_id']))
                || (isset($item['decision_area_key'])
                    && ! in_array($item['decision_area_key'], $allowedDecisionAreas, true))) {
                throw ValidationException::withMessages([
                    'contribution_items' => 'Iedere klantopdracht moet concreet, kort en aan een geldig beslisgebied gekoppeld zijn.',
                ]);
            }
        }

        $round = DB::transaction(function () use ($intake, $requester, $items): IntakeFollowUpRound {
            $intake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($requester->company_id !== $intake->company_id
                || $intake->status === IntakeStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'intake' => 'Deze opname kan geen klantopdracht ontvangen.',
                ]);
            }

            if ($intake->followUpRounds()->where('status', FollowUpRoundStatus::Open)->exists()) {
                throw ValidationException::withMessages([
                    'contribution_items' => 'Rond eerst de openstaande klantopdracht af.',
                ]);
            }

            $subjectIds = collect($items)
                ->pluck('dossier_subject_id')
                ->filter(static fn (mixed $id): bool => is_numeric($id))
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            if ($subjectIds->isNotEmpty()
                && DossierSubject::query()
                    ->where('intake_id', $intake->id)
                    ->whereIn('id', $subjectIds)
                    ->count() !== $subjectIds->count()) {
                throw ValidationException::withMessages([
                    'contribution_items' => 'Een klantopdracht verwijst naar een onderdeel buiten deze opname.',
                ]);
            }

            $this->surveyProgress->markStarted($intake);
            $roundNumber = ((int) $intake->followUpRounds()->max('round_number')) + 1;
            $maxRounds = (int) config('intake.follow_up.max_rounds', 3);

            if ($roundNumber > $maxRounds) {
                throw ValidationException::withMessages([
                    'contribution_items' => "Maximaal {$maxRounds} klantrondes toegestaan. Kies nu gericht contact of een locatiebezoek.",
                ]);
            }

            $returnStatus = $intake->status;
            $round = IntakeFollowUpRound::query()->create([
                'intake_id' => $intake->id,
                'requested_by' => $requester->id,
                'round_number' => $roundNumber,
                'purpose' => 'contribution',
                'status' => FollowUpRoundStatus::Open,
                'return_status' => $returnStatus,
                'sent_at' => now(),
            ]);

            foreach ($items as $item) {
                $type = $item['type'] instanceof FollowUpItemType
                    ? $item['type']
                    : FollowUpItemType::from((string) $item['type']);
                $followUpItem = $round->items()->create([
                    'type' => $type,
                    'prompt' => trim((string) $item['prompt']),
                ]);

                ContributionTask::query()->create([
                    'intake_id' => $intake->id,
                    'company_id' => $intake->company_id,
                    'dossier_subject_id' => $item['dossier_subject_id'] ?? null,
                    'intake_follow_up_item_id' => $followUpItem->id,
                    'audience' => ContributionAudience::Customer,
                    'type' => $type,
                    'prompt' => trim((string) $item['prompt']),
                    'decision_area_key' => $item['decision_area_key'] ?? null,
                    'status' => ContributionTaskStatus::Open,
                    'requested_by' => $requester->id,
                    'meta' => ['round_number' => $roundNumber],
                ]);
            }

            $intake->update([
                'status' => IntakeStatus::AwaitingCustomer,
                'workflow_mode' => ContributionMode::Hybrid,
                'access_token' => $this->tokenGenerator->generate(),
                'customer_access_enabled' => true,
                'token_revoked_at' => null,
                'token_expires_at' => now()->addDays((int) config('intake.token_ttl_days', 60)),
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $requester->id,
                'event' => 'customer_contribution_requested',
                'properties' => [
                    'round_number' => $roundNumber,
                    'item_count' => count($items),
                    'decision_areas' => collect($items)
                        ->pluck('decision_area_key')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ],
                'created_at' => now(),
            ]);

            return $round->load('items');
        }, 3);

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        return $round;
    }
}
