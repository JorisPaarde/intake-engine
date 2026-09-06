<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Interprets AircoRoom.dimensions for floor area (L×B or trusted area_m2) and height.
 *
 * Floor area uses exactly one reliable route. Never invent L×B from m² alone.
 *
 * @phpstan-type DimensionsArray array{
 *     length_m?: float|int|string|null,
 *     width_m?: float|int|string|null,
 *     height_m?: float|int|string|null,
 *     area_m2?: float|int|string|null,
 *     area_source?: string|null,
 *     area_confidence?: string|null,
 *     area_evidence?: string|null
 * }
 */
final class RoomDimensions
{
    public const CONFLICT_RELATIVE_TOLERANCE = 0.10;

    public const CONFLICT_ABSOLUTE_TOLERANCE_M2 = 1.0;

    /**
     * @param  DimensionsArray|array<string, mixed>|null  $dimensions
     */
    public function __construct(
        private readonly ?array $dimensions,
    ) {}

    /**
     * @param  DimensionsArray|array<string, mixed>|null  $dimensions
     */
    public static function from(?array $dimensions): self
    {
        return new self($dimensions);
    }

    public function lengthM(): ?float
    {
        return $this->numeric('length_m');
    }

    public function widthM(): ?float
    {
        return $this->numeric('width_m');
    }

    public function heightM(): ?float
    {
        return $this->numeric('height_m');
    }

    public function declaredAreaM2(): ?float
    {
        return $this->numeric('area_m2');
    }

    public function areaSource(): ?string
    {
        $source = $this->dimensions['area_source'] ?? null;

        return is_string($source) && $source !== '' ? $source : null;
    }

    public function areaConfidence(): ?string
    {
        $confidence = $this->dimensions['area_confidence'] ?? null;

        return is_string($confidence) && $confidence !== '' ? $confidence : null;
    }

    public function areaEvidence(): ?string
    {
        $evidence = $this->dimensions['area_evidence'] ?? null;

        return is_string($evidence) && trim($evidence) !== '' ? trim($evidence) : null;
    }

    public function hasLengthAndWidth(): bool
    {
        return $this->lengthM() !== null && $this->widthM() !== null;
    }

    public function areaFromLengthWidth(): ?float
    {
        $length = $this->lengthM();
        $width = $this->widthM();

        if ($length === null || $width === null) {
            return null;
        }

        return round($length * $width, 2);
    }

    public function hasTrustedAreaM2(): bool
    {
        if ($this->declaredAreaM2() === null) {
            return false;
        }

        return RoomAreaAcceptance::isTrusted(
            $this->areaSource(),
            $this->areaConfidence(),
            $this->areaEvidence(),
            $this->declaredAreaM2(),
        );
    }

    public function hasUntrustedAreaM2(): bool
    {
        return $this->declaredAreaM2() !== null && ! $this->hasTrustedAreaM2();
    }

    public function hasFloorAreaConflict(): bool
    {
        $fromLb = $this->areaFromLengthWidth();
        $declared = $this->declaredAreaM2();

        if ($fromLb === null || $declared === null) {
            return false;
        }

        return self::areasConflict($fromLb, $declared);
    }

    public static function areasConflict(float $a, float $b): bool
    {
        $delta = abs($a - $b);
        $relative = $delta / max($a, $b, 0.01);

        return $delta > self::CONFLICT_ABSOLUTE_TOLERANCE_M2
            && $relative > self::CONFLICT_RELATIVE_TOLERANCE;
    }

    /**
     * Floor area is complete when exactly one reliable route exists without conflict.
     */
    public function hasReliableFloorArea(): bool
    {
        if ($this->hasFloorAreaConflict()) {
            return false;
        }

        return $this->hasLengthAndWidth() || $this->hasTrustedAreaM2();
    }

    public function effectiveFloorAreaM2(): ?float
    {
        if ($this->hasFloorAreaConflict()) {
            return null;
        }

        if ($this->hasLengthAndWidth()) {
            return $this->areaFromLengthWidth();
        }

        if ($this->hasTrustedAreaM2()) {
            return $this->declaredAreaM2();
        }

        return null;
    }

    public function floorBasis(): string
    {
        if ($this->hasFloorAreaConflict()) {
            return 'conflict';
        }

        if ($this->hasLengthAndWidth()) {
            return 'length_width';
        }

        if ($this->hasTrustedAreaM2()) {
            return 'area_m2';
        }

        if ($this->hasUntrustedAreaM2()) {
            return 'untrusted_area';
        }

        return 'none';
    }

    public function hasAnyMeasure(): bool
    {
        return $this->lengthM() !== null
            || $this->widthM() !== null
            || $this->heightM() !== null
            || $this->declaredAreaM2() !== null;
    }

    public function hasHeight(): bool
    {
        return $this->heightM() !== null;
    }

    /**
     * @param  array{
     *     length_m?: float|null,
     *     width_m?: float|null,
     *     height_m?: float|null,
     *     area_m2?: float|null,
     *     area_source?: string|null,
     *     area_confidence?: string|null,
     *     area_evidence?: string|null
     * }  $input
     * @return array<string, float|string>
     */
    public static function normalizeWritable(array $input): array
    {
        $dimensions = [];

        foreach (['length_m', 'width_m', 'height_m', 'area_m2'] as $key) {
            $value = $input[$key] ?? null;
            if (is_numeric($value)) {
                $dimensions[$key] = (float) $value;
            }
        }

        if (isset($dimensions['area_m2'])) {
            $source = $input['area_source'] ?? 'installer';
            $confidence = $input['area_confidence'] ?? 'high';
            $evidence = $input['area_evidence'] ?? null;

            if ($source !== '') {
                $dimensions['area_source'] = $source;
            }
            if ($confidence !== '') {
                $dimensions['area_confidence'] = $confidence;
            }
            if (is_string($evidence) && trim($evidence) !== '') {
                $dimensions['area_evidence'] = trim($evidence);
            }
        }

        return $dimensions;
    }

    private function numeric(string $key): ?float
    {
        $value = $this->dimensions[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }
}
