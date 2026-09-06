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
            'installer', 'customer', 'template_bridge' => true,
            'ai', 'ai_derived' => self::acceptsAiExactArea($confidence, $evidence, $areaM2),
            'ai_suggestion', 'request_text' => false,
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

    /**
     * Map intake answer prefill_source to dimensions area_source / confidence.
     *
     * @return array{source: string, confidence: string}
     */
    public static function fromPrefillSource(?string $prefillSource): array
    {
        return match ($prefillSource) {
            'ai' => ['source' => 'ai', 'confidence' => 'high'],
            'ai_suggestion' => ['source' => 'ai_suggestion', 'confidence' => 'medium'],
            'request_text' => ['source' => 'request_text', 'confidence' => 'medium'],
            'installer' => ['source' => 'installer', 'confidence' => 'high'],
            default => ['source' => 'customer', 'confidence' => 'high'],
        };
    }
}
