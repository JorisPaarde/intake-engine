<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Hard safety-net limits for customer photo uploads (staging intake 82).
 * Normal phone photos (12 MP / ~2–5 MB / 3024×4032) must pass; absurd files fail fast
 * with a clear Dutch message — no hanging upload.
 */
final class PhotoUploadLimits
{
    public static function tooLargeMessage(): string
    {
        $configured = config('intake.uploads.too_large_message');

        return is_string($configured) && $configured !== ''
            ? $configured
            : 'Deze foto is te groot. Probeer een andere foto of maak een nieuwe.';
    }

    public static function hardMaxBytes(): int
    {
        return max(1, (int) config('intake.uploads.hard_max_bytes', 15 * 1024 * 1024));
    }

    public static function hardMaxMegapixels(): float
    {
        return max(1.0, (float) config('intake.uploads.hard_max_megapixels', 24));
    }

    public static function megapixels(?int $width, ?int $height): ?float
    {
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        return ($width * $height) / 1_000_000;
    }

    public static function exceedsHardByteLimit(int $sizeBytes): bool
    {
        return $sizeBytes > self::hardMaxBytes();
    }

    public static function exceedsHardMegapixelLimit(?int $width, ?int $height): bool
    {
        $mp = self::megapixels($width, $height);

        return $mp !== null && $mp > self::hardMaxMegapixels();
    }

    /**
     * @throws ValidationException
     */
    public static function assertAcceptable(
        int $sizeBytes,
        ?int $width = null,
        ?int $height = null,
    ): void {
        if (self::exceedsHardByteLimit($sizeBytes) || self::exceedsHardMegapixelLimit($width, $height)) {
            throw ValidationException::withMessages([
                'photo' => self::tooLargeMessage(),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertUploadedFileAcceptable(
        UploadedFile $file,
        ?int $clientOriginalWidth = null,
        ?int $clientOriginalHeight = null,
    ): void {
        $size = $file->getSize();
        $sizeBytes = $size === false ? 0 : (int) $size;

        $width = $clientOriginalWidth;
        $height = $clientOriginalHeight;

        if (($width === null || $height === null || $width <= 0 || $height <= 0)
            && is_string($file->getRealPath())
            && is_file($file->getRealPath())) {
            $info = @getimagesize($file->getRealPath());
            if (is_array($info)) {
                $width = (int) $info[0];
                $height = (int) $info[1];
            }
        }

        self::assertAcceptable($sizeBytes, $width > 0 ? $width : null, $height > 0 ? $height : null);
    }
}
