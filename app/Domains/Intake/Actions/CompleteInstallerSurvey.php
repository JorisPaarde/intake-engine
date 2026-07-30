<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\InstallerSurveyProgress;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoOptionStatus;
use App\Enums\DecisionAreaStatus;
use App\Enums\IntakeStatus;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteInstallerSurvey
{
    public function __construct(
        private readonly DecisionReadinessService $decisionReadiness,
        private readonly InstallerSurveyProgress $surveyProgress,
    ) {}

    public function handle(Intake $intake, User $installer): Intake
    {
        if ($installer->company_id !== $intake->company_id
            || in_array($intake->status, [IntakeStatus::Cancelled, IntakeStatus::AwaitingCustomer], true)) {
            throw ValidationException::withMessages([
                'intake' => 'Deze opname kan nu niet door de installateur worden afgerond.',
            ]);
        }

        $quote = $this->decisionReadiness
            ->recalculate($intake)
            ->firstWhere('key', 'quote');

        if ($quote === null || in_array(
            $quote->status,
            [DecisionAreaStatus::Blocked, DecisionAreaStatus::Unknown],
            true,
        )) {
            throw ValidationException::withMessages([
                'intake' => 'Los eerst de beslissende open punten op voordat u het voorstel integraal goedkeurt.',
            ]);
        }

        $completed = DB::transaction(function () use ($intake, $installer): Intake {
            $intake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($installer->company_id !== $intake->company_id
                || in_array($intake->status, [IntakeStatus::Cancelled, IntakeStatus::AwaitingCustomer], true)) {
                throw ValidationException::withMessages([
                    'intake' => 'Deze opname kan nu niet door de installateur worden afgerond.',
                ]);
            }

            $this->surveyProgress->markStarted($intake);
            $selected = AircoInstallationOption::query()
                ->with('connections.routeSession')
                ->where('intake_id', $intake->id)
                ->where('status', AircoOptionStatus::Selected)
                ->lockForUpdate()
                ->first();
            $approvedConnectionCount = 0;

            if ($selected === null) {
                throw ValidationException::withMessages([
                    'intake' => 'Selecteer eerst één installatievoorstel om integraal goed te keuren.',
                ]);
            }

            $alreadyApproved = in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)
                && $selected->connections->isNotEmpty()
                && $selected->connections->every(
                    static fn (AircoConnection $connection): bool => $connection->status === AircoConnectionStatus::Approved
                        && ($connection->routeSession === null
                            || $connection->routeSession->status === PipeRouteStatus::Approved),
                );

            if ($alreadyApproved) {
                return $intake->fresh() ?? $intake;
            }

            foreach ($selected->connections as $connection) {
                if (! in_array($connection->status, [
                    AircoConnectionStatus::Proposed,
                    AircoConnectionStatus::Plausible,
                    AircoConnectionStatus::Approved,
                ], true)) {
                    throw ValidationException::withMessages([
                        'intake' => 'Minimaal één technische verbinding mist nog beslissend bewijs.',
                    ]);
                }

                if ($connection->routeSession !== null
                    && ! in_array($connection->routeSession->status, [
                        PipeRouteStatus::Proposed,
                        PipeRouteStatus::Approved,
                    ], true)) {
                    throw ValidationException::withMessages([
                        'intake' => 'Rond eerst de gekoppelde routebeoordeling af.',
                    ]);
                }

                if ($connection->status !== AircoConnectionStatus::Approved) {
                    $connection->update([
                        'status' => AircoConnectionStatus::Approved,
                        'approved_by' => $installer->id,
                        'approved_at' => now(),
                    ]);
                    $approvedConnectionCount++;
                }

                if ($connection->routeSession?->status === PipeRouteStatus::Proposed) {
                    $connection->routeSession->update([
                        'status' => PipeRouteStatus::Approved,
                        'approved_by' => $installer->id,
                        'approved_at' => now(),
                    ]);
                }
            }

            $intake->update([
                'status' => IntakeStatus::Completed,
                'completed_at' => now(),
                'reviewed_at' => null,
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $installer->id,
                'event' => 'installer_survey_completed',
                'properties' => [
                    'selected_installation_option_id' => $selected->id,
                    'approved_connection_count' => $approvedConnectionCount,
                ],
                'created_at' => now(),
            ]);

            $fresh = $intake->fresh() ?? $intake;
            $this->decisionReadiness->recalculate($fresh);

            return $fresh;
        }, 3);

        if (! $completed->is_demo) {
            SynthesizeSurveyDossierJob::dispatch($completed->id);
        }

        return $completed;
    }
}
