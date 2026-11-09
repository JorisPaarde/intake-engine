<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Support\EmbedStagingTest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent: zorgt voor één test-company + user met vaste embed-sleutel (geen PII).
 */
final class PrepareEmbedStagingTest
{
    /**
     * @return array{company: Company, user: User, created: bool}
     */
    public function handle(?string $appHost = null): array
    {
        $host = $this->normalizeAppHost($appHost);

        return DB::transaction(function () use ($host): array {
            $existing = Company::query()
                ->where('embed_public_key', EmbedStagingTest::PUBLIC_KEY)
                ->first();

            $created = $existing === null;

            /** @var Company $company */
            $company = $existing ?? Company::query()->create([
                'name' => EmbedStagingTest::COMPANY_NAME,
            ]);

            $origins = $company->embedAllowedOrigins();
            if ($host !== null && ! in_array($host, $origins, true)) {
                $origins[] = $host;
            }

            // embed_public_key staat bewust niet in $fillable (alleen via settings/forceFill).
            $company->forceFill([
                'name' => EmbedStagingTest::COMPANY_NAME,
                'embed_public_key' => EmbedStagingTest::PUBLIC_KEY,
                'embed_enabled' => true,
                'embed_daily_limit' => max(Company::DEFAULT_EMBED_DAILY_LIMIT, (int) $company->embed_daily_limit),
                'embed_allowed_origins' => $origins,
            ])->save();

            $user = User::query()->where('email', EmbedStagingTest::USER_EMAIL)->first();

            if ($user === null) {
                $user = User::query()->create([
                    'company_id' => $company->id,
                    'name' => EmbedStagingTest::USER_NAME,
                    'email' => EmbedStagingTest::USER_EMAIL,
                    'password' => Hash::make(EmbedStagingTest::USER_PASSWORD),
                    'email_verified_at' => now(),
                ]);
            } else {
                $user->forceFill([
                    'company_id' => $company->id,
                    'name' => EmbedStagingTest::USER_NAME,
                    'password' => Hash::make(EmbedStagingTest::USER_PASSWORD),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            }

            return [
                'company' => $company->fresh() ?? $company,
                'user' => $user->fresh() ?? $user,
                'created' => $created,
            ];
        });
    }

    private function normalizeAppHost(?string $appHost): ?string
    {
        $raw = $appHost;
        if ($raw === null || trim($raw) === '') {
            $raw = (string) config('app.url');
        }

        $host = parse_url($raw, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $host = strtolower(trim($raw));
        } else {
            $host = strtolower($host);
        }

        // Hostname-validator weigert labels zonder punt (localhost); sla die over.
        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }
}
