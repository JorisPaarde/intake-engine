<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Eén acceptatieregel voor prefill/extractie: confidence 0–100 + bron + provenance.
 * Bouwt voort op bestaande high/medium/low en 0.0–1.0 — geen parallel systeem.
 */
final class FactAcceptance
{
    public const DEFAULT_THRESHOLD = 80;

    /** high/medium/low → 0–100 (bestaande prefill/photo-assessment schaal). */
    public const LEVEL_HIGH = 90;

    public const LEVEL_MEDIUM = 70;

    public const LEVEL_LOW = 40;

    /**
     * Normaliseer bestaande confidence-waarden naar 0–100.
     */
    public static function normalizeConfidence(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return match ($normalized) {
                'high' => self::LEVEL_HIGH,
                'medium' => self::LEVEL_MEDIUM,
                'low' => self::LEVEL_LOW,
                default => is_numeric($normalized) ? self::clampPercent((float) $normalized) : null,
            };
        }

        if (is_int($value)) {
            // 0–1 als 0/1, anders al percentage.
            if ($value === 0 || $value === 1) {
                return $value * 100;
            }

            return self::clampPercent((float) $value);
        }

        if (is_float($value)) {
            if ($value >= 0.0 && $value <= 1.0) {
                return self::clampPercent($value * 100.0);
            }

            return self::clampPercent($value);
        }

        return null;
    }

    public static function threshold(?string $questionKey = null): int
    {
        $default = (int) config('intake.fact_confidence_threshold', self::DEFAULT_THRESHOLD);

        if ($questionKey === null || $questionKey === '') {
            return max(0, min(100, $default));
        }

        $perField = config('intake.fact_confidence_thresholds.'.$questionKey);
        if (is_numeric($perField)) {
            return max(0, min(100, (int) $perField));
        }

        return max(0, min(100, $default));
    }

    /**
     * Mag dit als bekend feit tellen (known-summary / skip vraag)?
     * Alleen klantantwoord/foto + stated + confidence ≥ drempel.
     */
    public static function countsAsKnown(
        ?int $confidencePercent,
        FactSource $source,
        ?FactProvenance $provenance = null,
        ?string $questionKey = null,
    ): bool {
        if (! $source->mayCountAsKnown()) {
            return false;
        }

        if ($provenance === FactProvenance::Inferred || $provenance === FactProvenance::Unknown) {
            return false;
        }

        // Legacy fills zonder opgeslagen percentage: stated/ontbrekend op klantantwoord/foto telt als bekend.
        if ($confidencePercent === null) {
            return true;
        }

        return $confidencePercent >= self::threshold($questionKey);
    }

    public static function needsConfirmation(
        ?int $confidencePercent,
        FactSource $source,
        ?FactProvenance $provenance = null,
        ?string $questionKey = null,
    ): bool {
        return ! self::countsAsKnown($confidencePercent, $source, $provenance, $questionKey);
    }

    /**
     * @param  string|null  $requestReasonPrefillSource  prefill_source van request_reason
     *                                                   ('installer' → aanvraag; null → klantantwoord).
     *                                                   Default 'installer' voor callers zonder context.
     */
    public static function sourceFrom(
        ?string $prefillSource,
        ?FactProvenance $provenance = null,
        ?string $requestReasonPrefillSource = 'installer',
    ): FactSource {
        if ($prefillSource === null) {
            return FactSource::CustomerAnswer;
        }

        if ($provenance === FactProvenance::Inferred || $provenance === FactProvenance::Unknown) {
            return FactSource::Derived;
        }

        if (PrefillSources::isSuggestion($prefillSource) || $prefillSource === PrefillSources::DERIVED_LXW) {
            return FactSource::Derived;
        }

        if ($prefillSource === PrefillSources::AI_PHOTO || PrefillSources::isPhotoSuggestion($prefillSource)) {
            return FactSource::Photo;
        }

        // Installateursaanvraag / openingszin-bron.
        if ($prefillSource === PrefillSources::REQUEST_TEXT || $prefillSource === 'installer') {
            return FactSource::InstallerRequest;
        }

        if (in_array($prefillSource, [PrefillSources::AI_TEXT, PrefillSources::AI_LEGACY], true)) {
            // Catalogus-fills: bron volgt request_reason (installateur vs klant-first).
            return ($requestReasonPrefillSource === 'installer'
                || $requestReasonPrefillSource === PrefillSources::REQUEST_TEXT)
                ? FactSource::InstallerRequest
                : FactSource::CustomerAnswer;
        }

        if (PrefillSources::isTextDerived($prefillSource)
            || in_array($prefillSource, ['pdok', 'epo', 'bag'], true)) {
            return FactSource::CustomerAnswer;
        }

        return FactSource::Derived;
    }

    /**
     * Stated-evidence moet als genormaliseerde substring in de brontekst staan.
     */
    public static function evidenceAppearsInSource(?string $evidence, string $sourceText): bool
    {
        $quote = self::normalizeForQuote($evidence);
        $haystack = self::normalizeForQuote($sourceText);

        if ($quote === null || $haystack === null || $quote === '') {
            return false;
        }

        return str_contains($haystack, $quote);
    }

    /**
     * Bij mislukte quote-check: confidence onder de drempel (suggestion/confirmation).
     */
    public static function belowThresholdConfidence(?string $questionKey = null): int
    {
        return max(0, self::threshold($questionKey) - 1);
    }

    public static function levelFromPercent(?int $percent): string
    {
        if ($percent === null) {
            return 'low';
        }

        if ($percent >= self::LEVEL_HIGH) {
            return 'high';
        }

        if ($percent >= self::LEVEL_MEDIUM) {
            return 'medium';
        }

        return 'low';
    }

    public static function dossierFloat(?int $percent): float
    {
        if ($percent === null) {
            return 0.5;
        }

        return round(max(0, min(100, $percent)) / 100, 4);
    }

    private static function clampPercent(float $value): int
    {
        return (int) max(0, min(100, (int) round($value)));
    }

    private static function normalizeForQuote(?string $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', mb_strtolower(trim($text), 'UTF-8'));

        return is_string($collapsed) ? $collapsed : null;
    }
}
