<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EmbedAllowedOriginValidator
{
    /**
     * Host from Origin (preferred) or Referer. Same-origin form POSTs use the app host;
     * that host is accepted alongside the company allowlist so the iframe form works.
     */
    public function requestHostIsAllowed(Request $request, Company $company): bool
    {
        $host = $this->hostFromRequest($request);

        if ($host === null) {
            return false;
        }

        $appHost = strtolower($request->getHost());

        if ($host === $appHost) {
            return true;
        }

        return $company->allowsEmbedOriginHost($host);
    }

    public function hostFromRequest(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');

        if (is_string($origin) && $origin !== '' && strcasecmp($origin, 'null') !== 0) {
            $host = $this->hostFromUrl($origin);

            if ($host !== null) {
                return $host;
            }
        }

        $referer = $request->headers->get('Referer');

        if (is_string($referer) && $referer !== '') {
            return $this->hostFromUrl($referer);
        }

        return null;
    }

    /**
     * CSP frame-ancestors sources: https://host for each allowlisted hostname, plus 'self'.
     * No wildcards.
     *
     * @return list<string>
     */
    public function frameAncestorsSources(Company $company): array
    {
        $sources = ["'self'"];

        foreach ($company->embedAllowedOrigins() as $host) {
            $sources[] = 'https://'.$host;
        }

        return array_values(array_unique($sources));
    }

    public function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

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
