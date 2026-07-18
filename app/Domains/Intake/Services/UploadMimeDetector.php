<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use Illuminate\Http\UploadedFile;

final class UploadMimeDetector
{
    /**
     * @var list<string>
     */
    private const HEIC_BRANDS = [
        'heic',
        'heix',
        'hevc',
        'hevx',
        'heim',
        'heis',
        'hevm',
        'hevs',
    ];

    /**
     * @var list<string>
     */
    private const HEIF_BRANDS = [
        'heif',
    ];

    /**
     * @var list<string>
     */
    private const GENERIC_BINARY_MIMES = [
        'application/octet-stream',
        'application/x-empty',
        'binary/octet-stream',
    ];

    public function detect(UploadedFile $file): string
    {
        $path = $this->path($file);
        $serverMime = $this->canonicalize($file->getMimeType());
        $clientMime = $this->canonicalize($file->getClientMimeType());
        $extension = $this->extension($file);

        if ($this->shouldSniffHeif($serverMime, $clientMime, $extension)) {
            $sniffedMime = $this->sniffHeifMime($path, $extension, $clientMime);

            if ($sniffedMime !== null) {
                return $sniffedMime;
            }
        }

        return $serverMime
            ?: $clientMime
            ?: 'application/octet-stream';
    }

    private function canonicalize(?string $mime): ?string
    {
        if ($mime === null || trim($mime) === '') {
            return null;
        }

        return match (strtolower(trim($mime))) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            'image/x-heic' => 'image/heic',
            'image/x-heif' => 'image/heif',
            'image/x-heic-sequence' => 'image/heic',
            'image/x-heif-sequence' => 'image/heif',
            'image/heic-sequence' => 'image/heic',
            'image/heif-sequence' => 'image/heif',
            default => strtolower(trim($mime)),
        };
    }

    private function shouldSniffHeif(?string $serverMime, ?string $clientMime, string $extension): bool
    {
        if (in_array($serverMime, ['image/heic', 'image/heif'], true)) {
            return true;
        }

        if (in_array($serverMime, self::GENERIC_BINARY_MIMES, true) || $serverMime === null) {
            return true;
        }

        return in_array($extension, ['heic', 'heif'], true)
            && in_array($clientMime, ['image/heic', 'image/heif'], true);
    }

    private function sniffHeifMime(string $path, string $extension, ?string $clientMime): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = fread($handle, 128);
        } finally {
            fclose($handle);
        }

        if ($header === false || strlen($header) < 16 || substr($header, 4, 4) !== 'ftyp') {
            return null;
        }

        $majorBrand = substr($header, 8, 4);
        $brands = [$majorBrand];

        for ($offset = 16; $offset + 4 <= strlen($header); $offset += 4) {
            $brand = substr($header, $offset, 4);

            if ($brand === "\0\0\0\0") {
                continue;
            }

            $brands[] = $brand;
        }

        if (array_intersect($brands, self::HEIC_BRANDS) !== []) {
            return 'image/heic';
        }

        if (array_intersect($brands, self::HEIF_BRANDS) !== []) {
            return 'image/heif';
        }

        if (
            in_array($majorBrand, ['mif1', 'msf1'], true)
            && (in_array($extension, ['heic', 'heif'], true) || in_array($clientMime, ['image/heic', 'image/heif'], true))
        ) {
            return 'image/heif';
        }

        return null;
    }

    private function extension(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();

        if ($extension === '') {
            $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        }

        return strtolower($extension);
    }

    private function path(UploadedFile $file): string
    {
        return $file->getRealPath() ?: $file->getPathname();
    }
}
