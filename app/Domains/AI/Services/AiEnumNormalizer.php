<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Normalizes free-form AI enum strings before Laravel validation.
 *
 * Models (esp. via OpenRouter/Gemini) often return synonyms, Dutch labels,
 * or parenthetical elaborations ("short (<5m)") instead of exact tokens.
 * Fields that allow `unknown` fall back to it; others leave the raw value
 * so validation can still soft-fail rather than invent a dangerous mapping.
 */
final class AiEnumNormalizer
{
    /**
     * @param  list<string>  $allowed
     * @param  array<string, string>  $aliases  lowercase alias => canonical
     */
    public function normalize(
        mixed $value,
        array $allowed,
        array $aliases = [],
        ?string $fallback = null,
    ): mixed {
        if (! is_string($value)) {
            return $value;
        }

        $candidates = $this->candidates($value);

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }

            if (isset($aliases[$candidate])) {
                return $aliases[$candidate];
            }
        }

        return $fallback ?? $value;
    }

    /**
     * @return list<string>
     */
    private function candidates(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [''];
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');
        $withoutParens = trim((string) preg_replace('/\([^)]*\)/u', '', $lower));
        $collapsed = trim((string) preg_replace('/[\s\-]+/u', '_', $withoutParens));
        $alnum = trim((string) preg_replace('/[^a-z0-9_]+/u', '_', $collapsed), '_');
        $spaced = trim((string) preg_replace('/[\s_]+/u', ' ', $withoutParens));

        $out = [];
        foreach ([$lower, $withoutParens, $collapsed, $alnum, $spaced] as $candidate) {
            if ($candidate !== '' && ! in_array($candidate, $out, true)) {
                $out[] = $candidate;
            }
        }

        // Leading token before space/punctuation: "short (<5m)" → "short"
        if (preg_match('/^([a-z0-9_]+)/u', $alnum !== '' ? $alnum : $lower, $matches) === 1) {
            if (! in_array($matches[1], $out, true)) {
                $out[] = $matches[1];
            }
        }

        return $out;
    }
}
