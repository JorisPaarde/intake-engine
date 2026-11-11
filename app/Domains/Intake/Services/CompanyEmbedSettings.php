<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyEmbedSettings
{
    public function __construct(
        private readonly EmbedPublicKeyGenerator $keyGenerator,
        private readonly EmbedAllowedOriginValidator $originValidator,
    ) {}

    public function generateKey(Company $company): Company
    {
        return DB::transaction(function () use ($company): Company {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            if (is_string($locked->embed_public_key) && $locked->embed_public_key !== '') {
                throw ValidationException::withMessages([
                    'embed_public_key' => 'Er is al een sleutel. Gebruik vernieuwen om een nieuwe te maken.',
                ]);
            }

            $locked->forceFill([
                'embed_public_key' => $this->keyGenerator->generateUnique(),
            ])->save();

            return $locked->refresh();
        });
    }

    public function rotateKey(Company $company): Company
    {
        return DB::transaction(function () use ($company): Company {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            if (! is_string($locked->embed_public_key) || $locked->embed_public_key === '') {
                throw ValidationException::withMessages([
                    'embed_public_key' => 'Maak eerst een sleutel aan.',
                ]);
            }

            $locked->forceFill([
                'embed_public_key' => $this->keyGenerator->generateUnique(),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  array{embed_enabled?: mixed, embed_daily_limit?: mixed, embed_allowed_origins?: mixed}  $input
     */
    public function update(Company $company, array $input): Company
    {
        $origins = $this->originValidator->normalizeList($input['embed_allowed_origins'] ?? []);
        $enabled = filter_var($input['embed_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $limit = (int) ($input['embed_daily_limit'] ?? 20);

        if ($limit < 1 || $limit > 500) {
            throw ValidationException::withMessages([
                'embed_daily_limit' => 'Kies een dagplafond tussen 1 en 500.',
            ]);
        }

        if ($enabled && ($company->embed_public_key === null || $company->embed_public_key === '')) {
            throw ValidationException::withMessages([
                'embed_enabled' => 'Maak eerst een sleutel aan voordat je het formulier aanzet.',
            ]);
        }

        $company->forceFill([
            'embed_enabled' => $enabled,
            'embed_daily_limit' => $limit,
            'embed_allowed_origins' => $origins,
        ])->save();

        return $company->refresh();
    }

    public function iframeSnippet(Company $company): ?string
    {
        $key = $company->embed_public_key;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $src = url('/embed/'.$key);

        return app(EmbedIframeSnippet::class)->forSrc($src, [
            'id' => 'intake-embed-'.substr(hash('sha256', $key), 0, 12),
            'title' => 'Opname starten',
        ]);
    }
}
