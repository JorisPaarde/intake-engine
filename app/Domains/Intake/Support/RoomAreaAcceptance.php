<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Object-specific acceptance for exact room floor area in m² (BL-101).
 *
 * Exact AI m² counts only when source quality, evidence and plausibility are enough.
 * Low/medium AI suggestions stay visible for review but do not complete capacity.
 */
final class RoomAreaAcceptance
{
    public const MAX_PLAUSIBLE_AREA_M2 = 500.0;

    public const MIN_PLAUSIBLE_AREA_M2 = 1.0;

    /** Template + foto-prompt: klein tot onder deze m² (exclusief). */
    public const SIZE_SMALL_MAX_M2 = 12.0;

    /** Template + foto-prompt: gemiddeld tot en met deze m². */
    public const SIZE_MEDIUM_MAX_M2 = 20.0;

    /**
     * Human and high-confidence derived sources may complete floor area.
     */
    public static function isTrusted(
        ?string $source,
        ?string $confidence,
        ?string $evidence,
        ?float $areaM2,
    ): bool {
        if ($areaM2 === null || ! self::isPlausibleArea($areaM2)) {
            return false;
        }

        $source = $source ?? 'customer';

        return match ($source) {
            'installer', 'customer', 'template_bridge', 'derived_lxw' => true,
            'ai', 'ai_text', 'ai_photo', 'ai_derived' => self::acceptsAiExactArea($confidence, $evidence, $areaM2),
            'ai_suggestion', 'ai_text_suggestion', 'ai_photo_suggestion', 'request_text' => false,
            default => ($confidence ?? '') === 'high' && self::isPlausibleArea($areaM2),
        };
    }

    /**
     * Whether an AI fill for room_area_m2 may be treated as trusted truth.
     */
    public static function acceptsAiExactArea(
        ?string $confidence,
        ?string $evidence,
        ?float $areaM2,
        ?float $lengthWidthProduct = null,
    ): bool {
        if (($confidence ?? '') !== 'high') {
            return false;
        }

        if ($areaM2 === null || ! self::isPlausibleArea($areaM2)) {
            return false;
        }

        if (! is_string($evidence) || trim($evidence) === '') {
            return false;
        }

        if ($lengthWidthProduct !== null && RoomDimensions::areasConflict($lengthWidthProduct, $areaM2)) {
            return false;
        }

        return true;
    }

    public static function isPlausibleArea(float $areaM2): bool
    {
        return $areaM2 >= self::MIN_PLAUSIBLE_AREA_M2
            && $areaM2 <= self::MAX_PLAUSIBLE_AREA_M2;
    }

    public static function sizeIndicationFromArea(float $areaM2): string
    {
        // Eén bron met templateopties én room_assessment-prompt (BL-129).
        return match (true) {
            $areaM2 < self::SIZE_SMALL_MAX_M2 => 'small',
            $areaM2 <= self::SIZE_MEDIUM_MAX_M2 => 'medium',
            default => 'large',
        };
    }

    /**
     * Korte NL-bandomschrijving voor prompts/docs — zelfde getallen als sizeIndicationFromArea.
     */
    public static function sizeIndicationBandsForPrompt(): string
    {
        $small = (int) self::SIZE_SMALL_MAX_M2;
        $medium = (int) self::SIZE_MEDIUM_MAX_M2;

        return "small tot ca. {$small} m², medium ca. {$small}–{$medium} m², large groter dan ca. {$medium} m²";
    }

    /**
     * Map intake answer prefill_source to dimensions area_source / confidence.
     *
     * @return array{source: string, confidence: string}
     */
    public static function fromPrefillSource(?string $prefillSource): array
    {
        return match ($prefillSource) {
            'ai', 'ai_text', 'ai_photo' => ['source' => 'ai', 'confidence' => 'high'],
            'derived_lxw' => ['source' => 'derived_lxw', 'confidence' => 'high'],
            'ai_suggestion', 'ai_text_suggestion', 'ai_photo_suggestion' => ['source' => 'ai_suggestion', 'confidence' => 'medium'],
            'request_text' => ['source' => 'request_text', 'confidence' => 'medium'],
            'installer' => ['source' => 'installer', 'confidence' => 'high'],
            default => ['source' => 'customer', 'confidence' => 'high'],
        };
    }
}
