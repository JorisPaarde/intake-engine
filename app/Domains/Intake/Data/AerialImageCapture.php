<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

final readonly class AerialImageCapture
{
    /**
     * @param  array{min_x: float, min_y: float, max_x: float, max_y: float}  $bbox
     */
    public function __construct(
        public string $binary,
        public string $mimeType,
        public int $width,
        public int $height,
        public string $layer,
        public array $bbox,
        public int $groundWidthMeters,
        public int $groundHeightMeters,
    ) {}
}
