<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\RebuildIntakeReportHtml;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteFollowUpRound
{
    public function __construct(
        private readonly RebuildIntakeReportHtml $rebuildIntakeReportHtml,
        private readonly DossierManager $dossierManager,
        private readonly DecisionReadinessService $decisionReadiness,
    ) {}

    /** @param array<int, string|null> $textResponses */
    public function handle(Intake $intake, IntakeFollowUpRound $round, array $textResponses): Intake
    {
        $completed = DB::transaction(function () use ($intake, $round, $textResponses): Intake {
            $intake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $round = IntakeFollowUpRound::query()
                ->with(['items.uploads'])
                ->lockForUpdate()
                ->findOrFail($round->id);

            if ($round->intake_id !== $intake->id
                || $round->status !== FollowUpRoundStatus::Open
                || $intake->status !== IntakeStatus::AwaitingCustomer) {
                throw ValidationException::withMessages([
                    'follow_up' => 'Deze aanvullende ronde is niet meer beschikbaar.',
                ]);
            }

            $missing = [];

            foreach ($round->items as $item) {
                if ($item->type === FollowUpItemType::Text) {
                    $response = trim((string) ($textResponses[$item->id] ?? ''));

                    if ($response === '') {
                        $missing[] = $item->id;

                        continue;
                    }

                    $item->update([
                        'response_text' => $response,
                        'answered_at' => now(),
                    ]);

                    continue;
                }

                if ($item->uploads->isEmpty()) {
                    $missing[] = $item->id;
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'follow_up' => 'Beantwoord alle vragen en voeg bij elke foto- of documentopdracht minimaal één bestand toe.',
                ]);
            }

            $round->update([
                'status' => FollowUpRoundStatus::Completed,
                'completed_at' => now(),
            ]);

            $returnStatus = $round->return_status;
            $intakeUpdates = [
                'status' => $returnStatus instanceof IntakeStatus
                    ? $returnStatus
                    : IntakeStatus::Completed,
                'reviewed_at' => null,
            ];

            if ($round->purpose === 'contribution') {
                $intakeUpdates['customer_access_enabled'] = false;
            }

            $intake->update($intakeUpdates);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'customer',
                'actor_id' => null,
                'event' => 'follow_up_completed',
                'properties' => [
                    'round_number' => $round->round_number,
                    'item_count' => $round->items->count(),
                ],
                'created_at' => now(),
            ]);

            return $intake->fresh() ?? $intake;
        });

        $this->dossierManager->initialize($completed);
        $this->decisionReadiness->recalculate($completed);
        SynthesizeSurveyDossierJob::dispatch($completed->id);

        $this->rebuildIntakeReportHtml->handle($completed);

        if ($completed->status !== IntakeStatus::Completed) {
            return $completed;
        }

        if ($completed->is_demo) {
            app(SuggestAttentionPoints::class)->handle($completed);
        } else {
            SuggestAttentionPointsJob::dispatch($completed->id);
            GenerateIntakePdfJob::dispatch($completed->id);
            app(SendInstallerIntakeCompleted::class)->handle($completed);
        }

        return $completed;
    }
}
