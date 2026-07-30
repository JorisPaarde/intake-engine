<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\DemoSurveyScenarioBuilder;
use App\Domains\Intake\Services\PublicDemoWorkspaceProvisioner;
use App\Enums\ContributionMode;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StartDemoIntake
{
    public function __construct(
        private readonly CreateIntake $createIntake,
        private readonly DemoSurveyScenarioBuilder $scenarioBuilder,
        private readonly PublicDemoWorkspaceProvisioner $workspaceProvisioner,
        private readonly HardDeleteIntake $hardDeleteIntake,
    ) {}

    public function handle(): Intake
    {
        if (! (bool) config('intake.demo.enabled', true)) {
            throw ValidationException::withMessages([
                'demo' => 'Demo is uitgeschakeld in deze omgeving.',
            ]);
        }

        $suffix = Str::lower((string) Str::ulid());
        $creator = $this->workspaceProvisioner->provision($suffix);
        $intake = null;

        try {
            $intake = $this->createIntake->handle($creator, [
                'template_key' => 'airco',
                'workflow_mode' => ContributionMode::Installer,
                'customer_name' => 'Voorbeeldklant',
                'customer_email' => 'voorbeeld+'.$suffix.'@demo.invalid',
                'customer_phone' => null,
                'address_line' => (string) config('intake.demo.address.line', 'Voorbeeldstraat 12'),
                'address_postal_code' => (string) config('intake.demo.address.postal_code', '1234AB'),
                'address_house_number' => (int) config('intake.demo.address.house_number', 12),
                'address_house_number_addition' => config('intake.demo.address.house_number_addition'),
                'address_city' => (string) config('intake.demo.address.city', 'Voorbeeldstad'),
                'internal_note' => 'Fictieve interactieve demo — geen echte woning, klant of offerte.',
                'is_demo' => true,
                'token_ttl_hours' => max(1, (int) config('intake.demo.ttl_hours', 2)),
            ]);
            $this->scenarioBuilder->build($intake, $creator);

            return $intake->fresh([
                'templateVersion.template',
                'creator.company',
                'aircoRooms',
                'aircoInstallationOptions.connections',
                'contributionTasks',
            ]) ?? $intake;
        } catch (Throwable $exception) {
            if ($intake !== null && $intake->exists) {
                $this->hardDeleteIntake->handle($intake);
            }

            $this->workspaceProvisioner->cleanupIfOrphaned($creator->id, (int) $creator->company_id);

            throw $exception;
        }
    }
}
