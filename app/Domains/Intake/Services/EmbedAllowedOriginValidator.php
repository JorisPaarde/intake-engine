<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use Illuminate\Validation\ValidationException;

final class EmbedAllowedOriginValidator
{
    /**
     * Parse and validate a list of hostnames (one per line or comma-separated).
     * Hostnames only: no scheme, path, port, or wildcards. www-variants must be listed explicitly.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function normalizeList(mixed $raw): array
    {
        $candidates = $this->splitCandidates($raw);
        $normalized = [];
        $errors = [];

        foreach ($candidates as $index => $candidate) {
            $host = $this->normalizeOne($candidate);

            if ($host === null) {
                $errors['embed_allowed_origins.'.$index] = 'Vul een geldige websitenaam in, bijvoorbeeld voorbeeld.nl. Geen https://, geen pad en geen *.';

                continue;
            }

            $normalized[$host] = $host;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($normalized);
    }

    public function isValidHostname(string $value): bool
    {
        return $this->normalizeOne($value) !== null;
    }

    private function normalizeOne(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '*')
            || str_contains($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, ' ')
            || str_contains($value, '@')
            || str_contains($value, '?')
            || str_contains($value, '#')
        ) {
            return null;
        }

        if (str_contains($value, '://')) {
            return null;
        }

        // Reject ports: example.nl:443
        if (preg_match('/:\d+$/', $value) === 1) {
            return null;
        }

        $host = strtolower($value);

        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        // Require at least one dot for a registrable site hostname (reject "localhost" style labels here).
        if (! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    /**
     * @return list<string>
     */
    private function splitCandidates(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        } else {
            $parts = [];
        }

        $candidates = [];

        foreach ($parts as $part) {
            if (! is_string($part)) {
                continue;
            }

            $trimmed = trim($part);

            if ($trimmed !== '') {
                $candidates[] = $trimmed;
            }
        }

        return $candidates;
    }
}
