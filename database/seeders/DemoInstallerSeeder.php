<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoInstallerSeeder extends Seeder
{
    public function run(): void
    {
        if (! (bool) config('intake.demo.enabled', true)) {
            $this->command->info('Demo installer skipped: DEMO_ENABLED=false.');

            return;
        }

        $password = $this->demoInstallerPassword();

        if ($password === null) {
            $this->command->warn('Demo installer skipped: DEMO_INSTALLER_PASSWORD is empty.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => (string) config('intake.demo.user_email', 'demo@intake-engine.invalid')],
            [
                'name' => 'Demo Installateur',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );
    }

    private function demoInstallerPassword(): ?string
    {
        $password = config('intake.demo.installer_password');

        if (! is_string($password)) {
            return null;
        }

        $password = trim($password);

        return $password === '' ? null : $password;
    }
}
