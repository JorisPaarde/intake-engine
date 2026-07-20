<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\PhotoUsabilityVerdict;

/**
 * Deterministic, local photo-usability check via GD (BL-007). No external calls.
 * Samples luminance and dimensions to flag likely-unusable photos as a *voorstel*.
 */
final class PhotoUsabilityHeuristic
{
    private const MIN_DIMENSION = 640;          // px, shortest side

    private const DARK_LUMINANCE = 55.0;        // 0..255 average

    public function assess(string $imageBytes): PhotoUsabilityVerdict
    {
        $image = @imagecreatefromstring($imageBytes);

        if ($image === false) {
            // Unreadable here (e.g. HEIC without support) — do not flag; stay silent.
            return PhotoUsabilityVerdict::Ok;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if (min($width, $height) < self::MIN_DIMENSION) {
                return PhotoUsabilityVerdict::TooSmall;
            }

            if ($this->averageLuminance($image, $width, $height) < self::DARK_LUMINANCE) {
                return PhotoUsabilityVerdict::TooDark;
            }

            return PhotoUsabilityVerdict::Ok;
        } finally {
            imagedestroy($image);
        }
    }

    private function averageLuminance(\GdImage $image, int $width, int $height): float
    {
        // Sample a grid (max ~32x32) — fast and stable for large photos.
        $stepX = max(1, (int) ($width / 32));
        $stepY = max(1, (int) ($height / 32));

        $sum = 0.0;
        $count = 0;

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // Rec. 601 luma
                $sum += 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : 255.0;
    }
}
