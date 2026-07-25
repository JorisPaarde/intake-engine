<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use App\Models\Company;

final class CompanyLogoColorExtractor
{
    /**
     * @return array{primary: string, accent: string, on_primary: string}
     */
    public function extract(string $path): array
    {
        $image = $this->createImage($path);

        if ($image === null) {
            return $this->fallback();
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $step = max(1, (int) floor(max($width, $height) / 120));
            $buckets = [];

            for ($y = 0; $y < $height; $y += $step) {
                for ($x = 0; $x < $width; $x += $step) {
                    $rgba = imagecolorat($image, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7F;

                    if ($alpha > 72) {
                        continue;
                    }

                    $red = ($rgba >> 16) & 0xFF;
                    $green = ($rgba >> 8) & 0xFF;
                    $blue = $rgba & 0xFF;
                    $saturation = $this->saturation($red, $green, $blue);
                    $luminance = $this->relativeLuminance($red, $green, $blue);

                    if ($saturation < 0.16 || $luminance > 0.88 || $luminance < 0.025) {
                        continue;
                    }

                    $key = ((int) round($red / 16)).'-'.((int) round($green / 16)).'-'.((int) round($blue / 16));
                    $buckets[$key] ??= [
                        'count' => 0,
                        'red' => 0,
                        'green' => 0,
                        'blue' => 0,
                        'saturation' => 0.0,
                        'luminance' => 0.0,
                    ];
                    $buckets[$key]['count']++;
                    $buckets[$key]['red'] += $red;
                    $buckets[$key]['green'] += $green;
                    $buckets[$key]['blue'] += $blue;
                    $buckets[$key]['saturation'] += $saturation;
                    $buckets[$key]['luminance'] += $luminance;
                }
            }
        } finally {
            unset($image);
        }

        if ($buckets === []) {
            return $this->fallback();
        }

        $best = null;
        $bestScore = -1.0;

        foreach ($buckets as $bucket) {
            $count = max(1, (int) $bucket['count']);
            $saturation = (float) $bucket['saturation'] / $count;
            $luminance = (float) $bucket['luminance'] / $count;
            $midLuminanceBias = 1.0 - min(0.55, abs($luminance - 0.22));
            $score = $count * (0.45 + $saturation) * $midLuminanceBias;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'red' => (int) round($bucket['red'] / $count),
                    'green' => (int) round($bucket['green'] / $count),
                    'blue' => (int) round($bucket['blue'] / $count),
                ];
            }
        }

        if ($best === null) {
            return $this->fallback();
        }

        return $this->tokensFromRgb($best['red'], $best['green'], $best['blue']);
    }

    /**
     * @return array{primary: string, accent: string, on_primary: string}
     */
    public function tokensFromHex(string $hex): array
    {
        $normalized = Company::normalizeHex($hex) ?? Company::DEFAULT_PRIMARY;

        return $this->tokensFromRgb(
            hexdec(substr($normalized, 1, 2)),
            hexdec(substr($normalized, 3, 2)),
            hexdec(substr($normalized, 5, 2)),
        );
    }

    /**
     * @return array{primary: string, accent: string, on_primary: string}
     */
    private function tokensFromRgb(int $red, int $green, int $blue): array
    {
        $primary = $this->hex($red, $green, $blue);

        if ($primary === Company::DEFAULT_PRIMARY) {
            return $this->fallback();
        }

        $onPrimary = $this->contrastRatio($red, $green, $blue, 255, 255, 255) >= 4.5
            ? '#FFFFFF'
            : '#1D1D1F';

        if ($onPrimary === '#1D1D1F'
            && $this->contrastRatio($red, $green, $blue, 29, 29, 31) < 4.5) {
            return $this->fallback();
        }

        return [
            'primary' => $primary,
            'accent' => $this->hex(
                (int) round($red * 0.84),
                (int) round($green * 0.84),
                (int) round($blue * 0.84),
            ),
            'on_primary' => $onPrimary,
        ];
    }

    /**
     * @return array{primary: string, accent: string, on_primary: string}
     */
    private function fallback(): array
    {
        return Company::defaultThemeTokens();
    }

    private function saturation(int $red, int $green, int $blue): float
    {
        $r = $red / 255;
        $g = $green / 255;
        $b = $blue / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;

        if ($max === $min) {
            return 0.0;
        }

        $delta = $max - $min;

        return $lightness > 0.5
            ? $delta / (2 - $max - $min)
            : $delta / ($max + $min);
    }

    private function contrastRatio(int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): float
    {
        $l1 = $this->relativeLuminance($r1, $g1, $b1);
        $l2 = $this->relativeLuminance($r2, $g2, $b2);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(int $red, int $green, int $blue): float
    {
        $channels = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }, [$red, $green, $blue]);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function hex(int $red, int $green, int $blue): string
    {
        return sprintf('#%02X%02X%02X', max(0, min(255, $red)), max(0, min(255, $green)), max(0, min(255, $blue)));
    }

    private function createImage(string $path): ?\GdImage
    {
        $info = @getimagesize($path);
        $mime = is_array($info) ? $info['mime'] : null;

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            default => null,
        };
    }
}
