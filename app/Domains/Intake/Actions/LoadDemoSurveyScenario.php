<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\DemoSurveyScenarioBuilder;
use App\Enums\ContributionMode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opens a clearly labelled, separate demo intake with the sample dossier.
 * Never mixes example rooms/routes into the installer's own request.
 */
final class LoadDemoSurveyScenario
{
    public function __construct(
        private readonly DemoSurveyScenarioBuilder $scenarioBuilder,
        private readonly CreateIntake $createIntake,
    ) {}

    public function handle(Intake $sourceIntake, User $installer): Intake
    {
        if (! $sourceIntake->is_demo) {
            throw new InvalidArgumentException('Alleen demo-opnames kunnen een voorbeelddossier laden.');
        }

        if ((int) $sourceIntake->company_id !== (int) $installer->company_id) {
            throw new InvalidArgumentException('Het voorbeelddossier hoort bij een andere demosessie.');
        }

        // Idempotent: if this source already spawned an example intake, reopen that one.
        $existingExampleId = IntakeActivityEvent::query()
            ->where('intake_id', $sourceIntake->id)
            ->where('event', 'demo_scenario_opened_example')
            ->latest('id')
            ->value('properties');
        if (is_array($existingExampleId) && isset($existingExampleId['example_intake_id'])) {
            $existing = Intake::query()
                ->whereKey((int) $existingExampleId['example_intake_id'])
                ->where('company_id', $installer->company_id)
                ->where('is_demo', true)
                ->first();
            if ($existing instanceof Intake) {
                return $existing->load([
                    'aircoRooms',
                    'aircoInstallationOptions.connections',
                    'contributionTasks',
                    'uploads',
                    'aiRuns',
                ]);
            }
        }

        return DB::transaction(function () use ($sourceIntake, $installer): Intake {
            $example = $this->createIntake->handle($installer, [
                'template_key' => 'airco',
                'workflow_mode' => ContributionMode::Installer,
                'customer_name' => 'Voorbeelddossier (demo)',
                'customer_email' => 'voorbeeld+'.uniqid('', false).'@demo.intake-engine.local',
                'address_line' => $sourceIntake->address_line,
                'address_postal_code' => $sourceIntake->address_postal_code,
                'address_house_number' => $sourceIntake->address_house_number,
                'address_house_number_addition' => $sourceIntake->address_house_number_addition,
                'address_city' => $sourceIntake->address_city,
                'internal_note' => 'Voorbeelddossier — voorbeeldinhoud, niet vermengen met een eigen aanvraag.',
                'is_demo' => true,
            ]);

            // Keep the example intake empty of intent-derived rooms before building sample content.
            $example->answers()->delete();
            $example->aircoRooms()->delete();
            $example->aircoInstallationOptions()->delete();
            $example->contributionTasks()->delete();

            $this->scenarioBuilder->build($example, $installer);

            IntakeActivityEvent::query()->create([
                'intake_id' => $example->id,
                'actor_type' => 'user',
                'actor_id' => $installer->id,
                'event' => 'demo_scenario_loaded',
                'properties' => [
                    'source_intake_id' => $sourceIntake->id,
                    'labelled' => 'Voorbeelddossier (demo)',
                ],
                'created_at' => now(),
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $sourceIntake->id,
                'actor_type' => 'user',
                'actor_id' => $installer->id,
                'event' => 'demo_scenario_opened_example',
                'properties' => [
                    'example_intake_id' => $example->id,
                ],
                'created_at' => now(),
            ]);

            return $example->fresh([
                'aircoRooms',
                'aircoInstallationOptions.connections',
                'contributionTasks',
                'uploads',
                'aiRuns',
            ]) ?? $example;
        });
    }
}
