<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Maps short model fill-tokens onto catalog values (`owned` / `rented`).
 *
 * ADR-0016: enum synonym mapping only — does not scan request_reason or
 * inject/upgrade stated ownership from source text.
 */
final class OwnershipNormalizer
{
    private const int MAX_TOKEN_LENGTH = 40;

    /** @var array<string, 'owned'|'rented'> */
    private const TOKENS = [
        'owned' => 'owned',
        'rented' => 'rented',
        'koop' => 'owned',
        'koophuis' => 'owned',
        'koopwoning' => 'owned',
        'koopappartement' => 'owned',
        'eigen woning' => 'owned',
        'eigen huis' => 'owned',
        'in eigendom' => 'owned',
        'eigendom' => 'owned',
        'huur' => 'rented',
        'huurwoning' => 'rented',
        'huurhuis' => 'rented',
        'huurappartement' => 'rented',
        'gehuurd' => 'rented',
        'we huren' => 'rented',
        'wij huren' => 'rented',
        'ik huur' => 'rented',
    ];

    /**
     * @return 'owned'|'rented'|null
     */
    public function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = $this->prepare($raw);

        if ($value === '' || mb_strlen($value) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        return self::TOKENS[$value] ?? null;
    }

    private function prepare(string $raw): string
    {
        $value = mb_strtolower(trim($raw), 'UTF-8');
        $value = str_replace(['’', '‘', '´'], "'", $value);

        return (string) preg_replace('/\s+/u', ' ', $value);
    }
}
