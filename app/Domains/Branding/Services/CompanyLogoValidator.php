<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class CompanyLogoValidator
{
    /**
     * @return array{mime: string, extension: string, size: int}
     */
    public function validate(UploadedFile $file): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $size = $file->getSize();

        if ($size === false || $size > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'logo' => 'Het logo mag maximaal 2 MB zijn.',
            ]);
        }

        $info = @getimagesize($path);
        $mime = is_array($info) ? $info['mime'] : null;

        if (! is_array($info)
            || $info[0] < 1
            || $info[1] < 1
            || $info[0] > 2048
            || $info[1] > 2048
            || $info[0] * $info[1] > 4_000_000) {
            throw ValidationException::withMessages([
                'logo' => 'Het logo heeft ongeldige of te grote afmetingen (maximaal 2048 × 2048 pixels).',
            ]);
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages([
                'logo' => 'Upload een geldig JPEG-, PNG- of WebP-logo.',
            ]);
        }

        $image = match ($extension) {
            'jpg' => @imagecreatefromjpeg($path) ?: null,
            'png' => @imagecreatefrompng($path) ?: null,
            'webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
        };

        if (! $image instanceof \GdImage) {
            throw ValidationException::withMessages([
                'logo' => 'Het logo kon niet volledig worden gelezen.',
            ]);
        }

        imagedestroy($image);

        return [
            'mime' => $mime,
            'extension' => $extension,
            'size' => $size,
        ];
    }
}
