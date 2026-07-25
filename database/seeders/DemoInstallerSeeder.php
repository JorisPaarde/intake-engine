<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

        $company = Company::query()->firstOrCreate(
            ['slug' => 'publieke-demo-installateur'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Demo Installateur',
                'primary_color' => Company::DEFAULT_PRIMARY,
                'accent_color' => Company::DEFAULT_ACCENT,
                'on_primary_color' => Company::DEFAULT_ON_PRIMARY,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => (string) config('intake.demo.user_email', 'demo@intake-engine.invalid')],
            [
                'company_id' => $company->id,
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
