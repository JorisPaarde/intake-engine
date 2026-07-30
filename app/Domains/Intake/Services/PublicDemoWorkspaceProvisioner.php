<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PublicDemoWorkspaceProvisioner
{
    private const COMPANY_SLUG_PREFIX = 'publieke-demo-';

    private const USER_EMAIL_PREFIX = 'installateur+';

    private const USER_EMAIL_DOMAIN = '@demo.invalid';

    public function provision(string $suffix): User
    {
        $suffix = Str::lower($suffix);

        if (preg_match('/^[a-z0-9]{20,32}$/', $suffix) !== 1) {
            throw new \InvalidArgumentException('Ongeldige publieke demo-identificatie.');
        }

        return DB::transaction(function () use ($suffix): User {
            $company = Company::query()->create([
                'name' => 'Demo Installatiebedrijf',
                'slug' => self::COMPANY_SLUG_PREFIX.$suffix,
                'primary_color' => '#0071E3',
                'accent_color' => '#005EC0',
                'on_primary_color' => '#FFFFFF',
            ]);

            $user = User::query()->create([
                'company_id' => $company->id,
                'name' => 'Demo Installateur',
                'email' => self::USER_EMAIL_PREFIX.$suffix.self::USER_EMAIL_DOMAIN,
                'password' => Str::password(40),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user->load('company');
        }, 3);
    }

    public function cleanupIfOrphaned(int $userId, int $companyId): void
    {
        DB::transaction(function () use ($userId, $companyId): void {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->first();

            if ($company === null || ! str_starts_with($company->slug, self::COMPANY_SLUG_PREFIX)) {
                return;
            }

            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($user !== null && ! $this->isEphemeralUser($user, $company)) {
                return;
            }

            if (Intake::withTrashed()->where('company_id', $company->id)->exists()) {
                return;
            }

            if ($user !== null) {
                if ($company->users()->where('id', '!=', $user->id)->exists()) {
                    return;
                }

                $user->delete();
            }

            if (! $company->users()->exists() && ! $company->intakes()->withTrashed()->exists()) {
                $company->delete();
            }
        }, 3);
    }

    public function isEphemeralUser(User $user, ?Company $company = null): bool
    {
        $company ??= $user->company;

        return $company !== null
            && (int) $user->company_id === (int) $company->id
            && str_starts_with($company->slug, self::COMPANY_SLUG_PREFIX)
            && str_starts_with($user->email, self::USER_EMAIL_PREFIX)
            && str_ends_with($user->email, self::USER_EMAIL_DOMAIN);
    }
}
