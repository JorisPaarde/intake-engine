<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Redacts secrets, customer-link tokens, and obvious PII from AI-trace payloads
 * before persistence and again on export. Never logs API keys, Bearer auth
 * headers, or /o/{token} customer access tokens.
 *
 * Masks: e-mail, phone, person names (known context + Dutch voornaam+achternaam
 * patterns), street + house number (known address_line + Dutch street patterns).
 */
final class AiTraceRedactor
{
    private const SECRET_PATTERNS = [
        '/sk-[A-Za-z0-9_\-]{8,}/' => '[api-key-redacted]',
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [redacted]',
        '/(api[_-]?key|authorization|x-api-key)\s*[:=]\s*["\']?[^\s"\']+/i' => '$1=[redacted]',
    ];

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // NL/intl telefoonnummers: +31/0031/0 gevolgd door 8+ cijfers met optionele spaties/streepjes.
    private const PHONE = '/(?<!\d)(?:\+31|0031|0)[\s\-]?(?:\d[\s\-]?){8,11}\d(?!\d)/';

    /**
     * Dutch street + house number, including compound names like "Voorbeeldstraat 12"
     * and spaced forms like "Van Speijkstraat 10-II".
     */
    private const STREET_HOUSE = '/\b(?:[A-ZÀ-Ý][A-Za-zÀ-ÿ\'\-]*(?:straat|laan|weg|plein|pad|singel|kade|gracht|dijk|dreef|hof|park|steeg|markt|boulevard|allee)|(?:[A-ZÀ-Ý][A-Za-zÀ-ÿ\'\-]+(?:\s+(?:van|de|den|der|het|ten|ter))?\s+)+(?:straat|laan|weg|plein|pad|singel|kade|gracht|dijk|dreef|hof|park|steeg|markt|boulevard|allee))\s+\d+[A-Za-z]?(?:\s*[-–]\s*[A-Za-z0-9]+)?\b/u';

    /**
     * Voornaam + achternaam (2–4 capitalized words), excluding common non-name starts.
     */
    private const PERSON_NAME = '/\b(?!(?:Airco|Meterkast|Woonkamer|Slaapkamer|Keuken|Zolder|Badkamer|Hal|Gang|Kantoor|Buitenunit|Artikel|Sectie|Vraag)\b)(?:[A-ZÀ-Ý][a-zà-ÿ\'\-]+)(?:\s+(?:van|de|den|der|het|ten|ter))?(?:\s+[A-ZÀ-Ý][a-zà-ÿ\'\-]+){1,3}\b/u';

    /** @var list<string> */
    private array $knownLiterals = [];

    /**
     * @param  array<string, mixed>|null  $knownPii  e.g. customer_name, customer_email, address_line
     */
    public function withKnownPii(?array $knownPii): self
    {
        $clone = clone $this;
        $clone->knownLiterals = [];

        if ($knownPii === null) {
            return $clone;
        }

        foreach (['customer_name', 'name', 'customer_email', 'email', 'customer_phone', 'phone', 'address_line', 'street', 'address'] as $key) {
            $value = $knownPii[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $clone->knownLiterals[] = trim($value);
            }
        }

        $clone->knownLiterals = array_values(array_unique($clone->knownLiterals));
        usort($clone->knownLiterals, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        /** @var array<string, mixed> $redacted */
        $redacted = $this->walk($payload);

        return $redacted;
    }

    public function redactString(string $value): string
    {
        $safe = $value;

        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $safe = (string) preg_replace($pattern, $replacement, $safe);
        }

        $safe = (string) preg_replace('#(/o/)[A-Za-z0-9]{32,}#', '$1[token-redacted]', $safe);
        $safe = (string) preg_replace(self::EMAIL, '[e-mail verwijderd]', $safe);
        $safe = (string) preg_replace(self::PHONE, '[telefoon verwijderd]', $safe);

        foreach ($this->knownLiterals as $literal) {
            $safe = $this->replaceKnownLiteral($safe, $literal);
        }

        $safe = (string) preg_replace(self::STREET_HOUSE, '[adres verwijderd]', $safe);
        $safe = (string) preg_replace(self::PERSON_NAME, '[naam verwijderd]', $safe);

        if ($this->looksLikeBase64Blob($safe)) {
            return '[base64-omitted len='.strlen($safe).']';
        }

        return $safe;
    }

    /**
     * Base64 only when data:-prefix or a long unbroken base64 alphabet string (no whitespace).
     * Long customer free-text without punctuation must stay intact.
     */
    public function looksLikeBase64Blob(string $value): bool
    {
        if (str_starts_with($value, 'data:') && str_contains($value, ';base64,')) {
            return true;
        }

        if (strlen($value) <= 500) {
            return false;
        }

        // No whitespace allowed — otherwise long NL customer text without punctuation is kept.
        if (preg_match('/\s/', $value) === 1) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9+/=]+$#', $value) === 1;
    }

    private function replaceKnownLiteral(string $haystack, string $literal): string
    {
        if ($literal === '' || ! str_contains(mb_strtolower($haystack), mb_strtolower($literal))) {
            return $haystack;
        }

        $replacement = match (true) {
            str_contains($literal, '@') => '[e-mail verwijderd]',
            preg_match(self::PHONE, $literal) === 1 => '[telefoon verwijderd]',
            preg_match('/\d/', $literal) === 1 => '[adres verwijderd]',
            default => '[naam verwijderd]',
        };

        return (string) preg_replace(
            '/'.preg_quote($literal, '/').'/iu',
            $replacement,
            $haystack,
        );
    }

    private function walk(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? strtolower($key) : (string) $key;

            if ($this->isSecretKey($keyString)) {
                $out[$key] = '[redacted]';

                continue;
            }

            if ($this->isNameKey($keyString) && is_string($item) && trim($item) !== '') {
                $out[$key] = '[naam verwijderd]';

                continue;
            }

            if ($this->isAddressKey($keyString) && is_string($item) && trim($item) !== '') {
                $out[$key] = '[adres verwijderd]';

                continue;
            }

            if (in_array($keyString, ['image_url', 'url', 'data', 'binary', 'base64', 'bytes'], true)
                && is_string($item)
                && (str_starts_with($item, 'data:') || strlen($item) > 200)) {
                $out[$key] = $this->photoRefPlaceholder($item);

                continue;
            }

            $out[$key] = $this->walk($item);
        }

        return $out;
    }

    private function isSecretKey(string $key): bool
    {
        return str_contains($key, 'api_key')
            || str_contains($key, 'apikey')
            || $key === 'authorization'
            || $key === 'auth'
            || str_contains($key, 'access_token')
            || str_contains($key, 'customer_token')
            || str_contains($key, 'customer_access')
            || ($key === 'token');
    }

    private function isNameKey(string $key): bool
    {
        return in_array($key, [
            'customer_name',
            'name',
            'full_name',
            'contact_name',
            'installer_name',
        ], true) || (str_ends_with($key, '_name') && ! str_contains($key, 'file') && ! str_contains($key, 'model'));
    }

    private function isAddressKey(string $key): bool
    {
        return in_array($key, [
            'address_line',
            'address',
            'street',
            'street_name',
            'house_number',
            'huisnummer',
            'adres',
        ], true) || str_contains($key, 'address_line') || str_contains($key, 'house_number');
    }

    private function photoRefPlaceholder(string $value): string
    {
        if (str_starts_with($value, 'data:')) {
            $mime = 'image';
            if (preg_match('#^data:([^;]+);base64,#', $value, $matches) === 1) {
                $mime = $matches[1];
            }

            return '[image-omitted mime='.$mime.' bytes≈'.max(0, strlen($value) - 30).']';
        }

        return '[binary-omitted len='.strlen($value).']';
    }
}
