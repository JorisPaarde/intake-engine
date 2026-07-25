<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DemoInstallerProvisioner
{
    public function provision(?string $password = null): User
    {
        return DB::transaction(function () use ($password): User {
            $company = Company::query()->firstOrCreate(
                ['slug' => 'publieke-demo-installateur'],
                ['name' => 'Demo Installateur'],
            );

            $user = User::query()->firstOrNew([
                'email' => (string) config('intake.demo.user_email', 'demo@intake-engine.invalid'),
            ]);

            $user->forceFill([
                'company_id' => $company->id,
                'name' => 'Demo Installateur',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (! $user->exists) {
                $user->password = Str::password(32);
            }

            if ($password !== null && ! Hash::check($password, (string) $user->password)) {
                $user->password = Hash::make($password);
            }

            $user->save();

            return $user;
        });
    }

    public function configuredPassword(): ?string
    {
        $password = config('intake.demo.installer_password');

        if (! is_string($password)) {
            return null;
        }

        $password = trim($password);

        return $password === '' ? null : $password;
    }
}
