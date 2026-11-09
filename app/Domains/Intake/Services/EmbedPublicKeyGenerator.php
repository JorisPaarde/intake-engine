<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmbedPublicKeyGenerator
{
    private const BYTES = 24;

    public function generateUnique(): string
    {
        do {
            $key = $this->generate();
        } while (DB::table('companies')->where('embed_public_key', $key)->exists());

        return $key;
    }

    public function generate(): string
    {
        // URL-safe CSPRNG token without padding (base64url).
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    public function looksValid(string $key): bool
    {
        return $key !== ''
            && strlen($key) <= 64
            && Str::isMatch('/^[A-Za-z0-9_-]+$/', $key);
    }
}
