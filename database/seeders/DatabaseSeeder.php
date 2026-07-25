<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $demoCompany = Company::query()->firstOrCreate(
            ['slug' => 'demo-installateur'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Demo Installateur',
                'primary_color' => Company::DEFAULT_PRIMARY,
                'accent_color' => Company::DEFAULT_ACCENT,
                'on_primary_color' => Company::DEFAULT_ON_PRIMARY,
            ],
        );
        $testCompany = Company::query()->firstOrCreate(
            ['slug' => 'test-installateur'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Test Installateur',
                'primary_color' => Company::DEFAULT_PRIMARY,
                'accent_color' => Company::DEFAULT_ACCENT,
                'on_primary_color' => Company::DEFAULT_ON_PRIMARY,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'installateur@example.com'],
            [
                'company_id' => $demoCompany->id,
                'name' => 'Demo Installateur',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'company_id' => $testCompany->id,
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $this->call([
            IntakeTemplateSeeder::class,
            DemoIntakeSeeder::class,
        ]);
    }
}
