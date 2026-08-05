<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\DemoSurveyScenarioBuilder;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LoadDemoSurveyScenario
{
    public function __construct(
        private readonly DemoSurveyScenarioBuilder $scenarioBuilder,
    ) {}

    public function handle(Intake $intake, User $installer): Intake
    {
        if (! $intake->is_demo) {
            throw new InvalidArgumentException('Alleen demo-opnames kunnen een voorbeelddossier laden.');
        }

        if ((int) $intake->company_id !== (int) $installer->company_id) {
            throw new InvalidArgumentException('Het voorbeelddossier hoort bij een andere demosessie.');
        }

        $alreadyLoaded = IntakeActivityEvent::query()
            ->where('intake_id', $intake->id)
            ->where('event', 'demo_scenario_loaded')
            ->exists();

        if ($alreadyLoaded) {
            return $intake->fresh([
                'aircoRooms',
                'aircoInstallationOptions.connections',
                'contributionTasks',
            ]) ?? $intake;
        }

        return DB::transaction(function () use ($intake, $installer): Intake {
            $intake->forceFill([
                'workflow_mode' => ContributionMode::Installer,
                'status' => $intake->status === IntakeStatus::Draft
                    ? IntakeStatus::InProgress
                    : $intake->status,
                'customer_access_enabled' => false,
            ])->save();

            // Live intent derivation may already have created desired rooms; the sample
            // dossier replaces that structure so the guided demo can still show a rich end state.
            $intake->aircoInstallationOptions()->delete();
            $intake->aircoRooms()->delete();
            $intake->contributionTasks()->delete();

            $this->scenarioBuilder->build($intake, $installer);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $installer->id,
                'event' => 'demo_scenario_loaded',
                'properties' => [],
                'created_at' => now(),
            ]);

            return $intake->fresh([
                'aircoRooms',
                'aircoInstallationOptions.connections',
                'contributionTasks',
                'uploads',
                'aiRuns',
            ]) ?? $intake;
        });
    }
}
