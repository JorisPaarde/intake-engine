<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use Illuminate\Contracts\Encryption\Encrypter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stateless signed form token for the public embed POST (no session / CSRF cookie).
 * HMAC over public_key + issued-at; max age 2 hours.
 */
final class EmbedFormToken
{
    public const MAX_AGE_SECONDS = 7200;

    public const FIELD = 'embed_form_token';

    private const INVALID_MESSAGE = 'Je formulier is verlopen of ongeldig. Vernieuw de pagina en probeer het opnieuw.';

    public function __construct(
        private readonly Encrypter $encrypter,
    ) {}

    public function issue(string $publicKey, ?int $issuedAt = null): string
    {
        $issuedAt ??= now()->getTimestamp();
        $signature = $this->sign($publicKey, $issuedAt);

        return $issuedAt.'.'.$signature;
    }

    public function assertValid(string $publicKey, mixed $token): void
    {
        if (! is_string($token) || $token === '') {
            throw new HttpException(419, self::INVALID_MESSAGE);
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            throw new HttpException(419, self::INVALID_MESSAGE);
        }

        [$issuedAtRaw, $signature] = $parts;

        if ($issuedAtRaw === '' || $signature === '' || preg_match('/^\d+$/', $issuedAtRaw) !== 1) {
            throw new HttpException(419, self::INVALID_MESSAGE);
        }

        $issuedAt = (int) $issuedAtRaw;
        $expected = $this->sign($publicKey, $issuedAt);

        if (! hash_equals($expected, $signature)) {
            throw new HttpException(419, self::INVALID_MESSAGE);
        }

        $age = now()->getTimestamp() - $issuedAt;

        if ($age < 0 || $age > self::MAX_AGE_SECONDS) {
            throw new HttpException(419, self::INVALID_MESSAGE);
        }
    }

    private function sign(string $publicKey, int $issuedAt): string
    {
        return hash_hmac('sha256', $publicKey.'|'.$issuedAt, $this->encrypter->getKey());
    }
}
