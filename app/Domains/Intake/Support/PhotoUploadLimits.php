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
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $mb = max(1, (int) round(self::appMaxKilobytes() / 1024));

        return "Deze foto is groter dan {$mb} MB. Maak een nieuwe foto of kies een kleinere.";
    }

    /**
     * User-facing / app soft cap (KB). Phone originals up to this size must be accepted.
     */
    public static function appMaxKilobytes(): int
    {
        return max(1, (int) config('intake.uploads.max_kilobytes', 8192));
    }

    public static function appMaxBytes(): int
    {
        return self::appMaxKilobytes() * 1024;
    }

    /**
     * Incoming-file ceiling (bytes). Kept ≥ app max so 8 MB originals always pass.
     */
    public static function hardMaxBytes(): int
    {
        $configured = max(1, (int) config('intake.uploads.hard_max_bytes', 8 * 1024 * 1024));

        return max($configured, self::appMaxBytes());
    }

    /**
     * Laravel `max:` rule (kilobytes) for Livewire/component validation.
     */
    public static function validationMaxKilobytes(): int
    {
        return max(self::appMaxKilobytes(), (int) ceil(self::hardMaxBytes() / 1024));
    }

    /**
     * Livewire temporary-upload ceiling — slightly above app max for multipart headroom.
     */
    public static function temporaryUploadMaxKilobytes(): int
    {
        return max(self::validationMaxKilobytes(), 10240);
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
