<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Redacts obvious PII (e-mail, telefoon) from an AI payload before it leaves the app
 * to an external provider (ADR-0005, DPIA-lijn). Structured PII (naam/e-mail/telefoon)
 * zit al niet in de payload; dit vangt vrije-tekstantwoorden af als extra laag.
 *
 * Let op: dit verwijdert geen willekeurige NAW uit vrije tekst — dat blijft een
 * restrisico dat in de DPIA wordt afgewogen vóór activering van een externe provider.
 */
final class AiInputRedactor
{
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // NL/intl telefoonnummers: +31/0031/0 gevolgd door 8+ cijfers met optionele spaties/streepjes.
    private const PHONE = '/(?<!\d)(?:\+31|0031|0)[\s\-]?(?:\d[\s\-]?){8,11}\d(?!\d)/';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function redact(array $input): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->walk($input);

        return $result;
    }

    private function walk(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item): mixed => $this->walk($item), $value);
        }

        if (is_string($value)) {
            return $this->scrub($value);
        }

        return $value;
    }

    private function scrub(string $value): string
    {
        $value = (string) preg_replace(self::EMAIL, '[e-mail verwijderd]', $value);
        $value = (string) preg_replace(self::PHONE, '[telefoon verwijderd]', $value);

        return $value;
    }
}
