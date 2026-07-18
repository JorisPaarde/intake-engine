<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagick;
use Throwable;

final class PhotoUploadNormalizer
{
    public function __construct(
        private readonly UploadMimeDetector $mimeDetector,
    ) {}

    public function normalize(UploadedFile $file): NormalizedPhotoUpload
    {
        $mime = $this->mimeDetector->detect($file);

        if (! in_array($mime, $this->acceptedMimes(), true)) {
            throw ValidationException::withMessages([
                'photo' => 'Alleen JPEG, PNG, WebP of HEIC/HEIF-foto’s zijn toegestaan. HEIC-foto’s worden automatisch omgezet.',
            ]);
        }

        return match ($mime) {
            'image/jpeg', 'image/png', 'image/webp' => $this->passthrough($file, $mime),
            'image/heic', 'image/heif' => $this->convertHeicToJpeg($file),
            default => throw ValidationException::withMessages([
                'photo' => 'Alleen JPEG, PNG, WebP of HEIC/HEIF-foto’s zijn toegestaan. HEIC-foto’s worden automatisch omgezet.',
            ]),
        };
    }

    /**
     * @return list<string>
     */
    private function acceptedMimes(): array
    {
        $mimes = config('intake.uploads.accepted_mimes', [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
        ]);

        return array_values(array_unique(array_map(
            static fn (string $mime): string => match ($mime) {
                'image/heic-sequence' => 'image/heic',
                'image/heif-sequence' => 'image/heif',
                default => $mime,
            },
            array_filter($mimes, 'is_string'),
        )));
    }

    private function passthrough(UploadedFile $file, string $mime): NormalizedPhotoUpload
    {
        $path = $this->path($file);
        $sizeBytes = $this->sizeBytes($path);

        $this->ensureWithinMaxSize($sizeBytes);

        return new NormalizedPhotoUpload(
            absolutePath: $path,
            mime: $mime,
            extension: $this->extensionForStoredMime($mime),
            sizeBytes: $sizeBytes,
            checksum: $this->checksum($path),
            originalFilename: $this->originalFilename($file),
        );
    }

    private function convertHeicToJpeg(UploadedFile $file): NormalizedPhotoUpload
    {
        if (! $this->imagickSupportsHeicRead()) {
            throw ValidationException::withMessages([
                'photo' => 'HEIC-foto’s kunnen tijdelijk niet automatisch worden verwerkt. Probeer het later opnieuw.',
            ]);
        }

        $sourcePath = $this->path($file);
        $tempPath = $this->temporaryJpegPath();
        $image = new Imagick;
        $success = false;

        try {
            $image->readImage($sourcePath);
            $image->setIteratorIndex(0);
            $image->autoOrient();
            $image->stripImage();
            $this->resizeToMaxLongEdge($image);
            $image->setImageFormat('jpeg');

            $quality = $this->initialJpegQuality();

            while ($quality >= 50) {
                $image->setImageCompressionQuality($quality);
                $image->writeImage($tempPath);
                clearstatcache(true, $tempPath);

                $sizeBytes = $this->sizeBytes($tempPath);

                if ($sizeBytes <= $this->maxBytes()) {
                    $success = true;

                    return new NormalizedPhotoUpload(
                        absolutePath: $tempPath,
                        mime: 'image/jpeg',
                        extension: 'jpg',
                        sizeBytes: $sizeBytes,
                        checksum: $this->checksum($tempPath),
                        originalFilename: $this->originalFilename($file),
                        cleanupPaths: [$tempPath],
                    );
                }

                $quality -= 8;
            }

            throw ValidationException::withMessages([
                'photo' => 'Deze foto blijft na automatische verwerking te groot. Maximaal '.$this->maxMegabytes().' MB.',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'photo' => 'Deze HEIC/HEIF-foto kon niet automatisch worden verwerkt. Probeer de foto opnieuw te uploaden.',
            ]);
        } finally {
            $image->clear();
            $image->destroy();

            if (! $success) {
                @unlink($tempPath);
            }
        }
    }

    private function resizeToMaxLongEdge(Imagick $image): void
    {
        $maxLongEdge = (int) config('intake.uploads.conversion.max_long_edge', 3000);

        if ($maxLongEdge <= 0) {
            return;
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $longEdge = max($width, $height);

        if ($longEdge <= $maxLongEdge) {
            return;
        }

        $scale = $maxLongEdge / $longEdge;
        $image->thumbnailImage(
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
            true,
        );
    }

    private function imagickSupportsHeicRead(): bool
    {
        if (! class_exists(Imagick::class)) {
            return false;
        }

        try {
            return Imagick::queryFormats('HEIC') !== []
                || Imagick::queryFormats('HEIF') !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function temporaryJpegPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'intake-heic-');

        if ($path === false) {
            throw ValidationException::withMessages([
                'photo' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        @unlink($path);

        return $path.'.jpg';
    }

    private function path(UploadedFile $file): string
    {
        return $file->getRealPath() ?: $file->getPathname();
    }

    private function sizeBytes(string $path): int
    {
        $size = filesize($path);

        if ($size === false) {
            throw ValidationException::withMessages([
                'photo' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        return $size;
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            throw ValidationException::withMessages([
                'photo' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        return $checksum;
    }

    private function ensureWithinMaxSize(int $sizeBytes): void
    {
        if ($sizeBytes <= $this->maxBytes()) {
            return;
        }

        throw ValidationException::withMessages([
            'photo' => 'Deze foto is te groot. Maximaal '.$this->maxMegabytes().' MB.',
        ]);
    }

    private function maxBytes(): int
    {
        return (int) config('intake.uploads.max_kilobytes', 5120) * 1024;
    }

    private function maxMegabytes(): string
    {
        return number_format((int) config('intake.uploads.max_kilobytes', 5120) / 1024, 0, ',', '.');
    }

    private function initialJpegQuality(): int
    {
        return min(100, max(1, (int) config('intake.uploads.conversion.heic_to_jpeg_quality', 82)));
    }

    private function originalFilename(UploadedFile $file): string
    {
        return Str::limit((string) $file->getClientOriginalName(), 240, '');
    }

    private function extensionForStoredMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
}
