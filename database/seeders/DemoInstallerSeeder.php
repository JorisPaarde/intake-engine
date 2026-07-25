<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Intake\Services\DemoInstallerProvisioner;
use Illuminate\Database\Seeder;

class DemoInstallerSeeder extends Seeder
{
    public function run(DemoInstallerProvisioner $provisioner): void
    {
        if (! (bool) config('intake.demo.enabled', true)) {
            $this->command->info('Demo installer skipped: DEMO_ENABLED=false.');

            return;
        }

        $password = $provisioner->configuredPassword();

        if ($password === null) {
            $this->command->warn('Demo installer skipped: DEMO_INSTALLER_PASSWORD is empty.');

            return;
        }

        $provisioner->provision($password);
    }
}
