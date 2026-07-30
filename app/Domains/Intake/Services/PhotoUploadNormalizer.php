<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagick;
use Throwable;

/**
 * Converts every accepted phone photo into two metadata-free JPEG variants:
 * a 2048px dossier image and a 1536px AI-analysis image (BL-030).
 */
final class PhotoUploadNormalizer
{
    public function __construct(
        private readonly UploadMimeDetector $mimeDetector,
    ) {}

    public function normalize(UploadedFile $file): NormalizedPhotoUpload
    {
        $mime = $this->normalizeMime($this->mimeDetector->detect($file));

        if (! in_array($mime, $this->acceptedMimes(), true)) {
            throw ValidationException::withMessages([
                'photo' => 'Alleen JPEG, PNG, WebP of HEIC/HEIF-foto’s zijn toegestaan. Foto’s worden automatisch verkleind.',
            ]);
        }

        $sourcePath = $this->path($file);
        $this->ensureWithinMaxSize($this->sizeBytes($sourcePath));
        $dossierPath = $this->temporaryJpegPath('dossier');
        $analysisPath = $this->temporaryJpegPath('analysis');
        $success = false;

        try {
            if (class_exists(Imagick::class)) {
                $this->createWithImagick($sourcePath, $dossierPath, $analysisPath);
            } else {
                if (in_array($mime, ['image/heic', 'image/heif'], true)) {
                    throw ValidationException::withMessages([
                        'photo' => 'HEIC-foto’s kunnen tijdelijk niet automatisch worden verwerkt. Probeer het later opnieuw.',
                    ]);
                }

                $this->createWithGd($sourcePath, $dossierPath, $analysisPath, $mime);
            }

            $success = true;

            return new NormalizedPhotoUpload(
                dossierAbsolutePath: $dossierPath,
                dossierMime: 'image/jpeg',
                dossierExtension: 'jpg',
                dossierSizeBytes: $this->sizeBytes($dossierPath),
                dossierChecksum: $this->checksum($dossierPath),
                analysisAbsolutePath: $analysisPath,
                analysisMime: 'image/jpeg',
                analysisExtension: 'jpg',
                analysisSizeBytes: $this->sizeBytes($analysisPath),
                analysisChecksum: $this->checksum($analysisPath),
                originalFilename: $this->originalFilename($file),
                cleanupPaths: [$dossierPath, $analysisPath],
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto kon niet automatisch worden verwerkt. Maak of kies de foto opnieuw.',
            ]);
        } finally {
            if (! $success) {
                @unlink($dossierPath);
                @unlink($analysisPath);
            }
        }
    }

    private function createWithImagick(string $sourcePath, string $dossierPath, string $analysisPath): void
    {
        $source = new Imagick;

        try {
            $source->readImage($sourcePath);
            $source->setIteratorIndex(0);
            $source->autoOrient();
            $source->stripImage();
            $source->setImageBackgroundColor('white');
            $source = $source->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

            $this->writeImagickVariant(
                $source,
                $dossierPath,
                (int) config('intake.uploads.dossier.max_long_edge', 2048),
                (int) config('intake.uploads.dossier.jpeg_quality', 82),
            );
            $this->writeImagickVariant(
                $source,
                $analysisPath,
                (int) config('intake.uploads.analysis.max_long_edge', 1536),
                (int) config('intake.uploads.analysis.jpeg_quality', 80),
            );
        } finally {
            $source->clear();
            $source->destroy();
        }
    }

    private function writeImagickVariant(
        Imagick $source,
        string $destination,
        int $maxLongEdge,
        int $initialQuality,
    ): void {
        $image = clone $source;

        try {
            $this->resizeImagick($image, $maxLongEdge);
            $image->setImageFormat('jpeg');
            $image->setInterlaceScheme(Imagick::INTERLACE_JPEG);
            $image->stripImage();

            for ($quality = min(100, max(50, $initialQuality)); $quality >= 50; $quality -= 8) {
                $image->setImageCompressionQuality($quality);
                $image->writeImage($destination);
                clearstatcache(true, $destination);

                if ($this->sizeBytes($destination) <= $this->maxBytes()) {
                    return;
                }
            }

            throw ValidationException::withMessages([
                'photo' => 'Deze foto blijft na automatische verwerking te groot. Maximaal '.$this->maxMegabytes().' MB.',
            ]);
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private function resizeImagick(Imagick $image, int $maxLongEdge): void
    {
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

    private function createWithGd(
        string $sourcePath,
        string $dossierPath,
        string $analysisPath,
        string $mime,
    ): void {
        if (! function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD ontbreekt.');
        }

        $binary = file_get_contents($sourcePath);
        $image = $binary === false ? false : @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            throw new \RuntimeException('Foto kon niet met GD worden gelezen.');
        }

        try {
            $image = $this->orientGd($image, $sourcePath, $mime);
            $this->writeGdVariant(
                $image,
                $dossierPath,
                (int) config('intake.uploads.dossier.max_long_edge', 2048),
                (int) config('intake.uploads.dossier.jpeg_quality', 82),
            );
            $this->writeGdVariant(
                $image,
                $analysisPath,
                (int) config('intake.uploads.analysis.max_long_edge', 1536),
                (int) config('intake.uploads.analysis.jpeg_quality', 80),
            );
        } finally {
            imagedestroy($image);
        }
    }

    private function orientGd(GdImage $image, string $sourcePath, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($sourcePath);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            2 => $this->flipGd($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotateGd($image, 180),
            4 => $this->flipGd($image, IMG_FLIP_VERTICAL),
            5 => $this->flipGd($this->rotateGd($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $this->rotateGd($image, -90),
            7 => $this->flipGd($this->rotateGd($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotateGd($image, 90),
            default => $image,
        };
    }

    private function rotateGd(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        if (! $rotated instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function flipGd(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function writeGdVariant(
        GdImage $source,
        string $destination,
        int $maxLongEdge,
        int $quality,
    ): void {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $longEdge = max($sourceWidth, $sourceHeight);
        $scale = $maxLongEdge > 0 && $longEdge > $maxLongEdge ? $maxLongEdge / $longEdge : 1.0;
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $target = imagecreatetruecolor($width, $height);

        if (! $target instanceof GdImage) {
            throw new \RuntimeException('JPEG-variant kon niet worden aangemaakt.');
        }

        try {
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

            for ($currentQuality = min(100, max(50, $quality)); $currentQuality >= 50; $currentQuality -= 8) {
                if (! imagejpeg($target, $destination, $currentQuality)) {
                    throw new \RuntimeException('JPEG-variant kon niet worden opgeslagen.');
                }

                clearstatcache(true, $destination);

                if ($this->sizeBytes($destination) <= $this->maxBytes()) {
                    return;
                }
            }
        } finally {
            imagedestroy($target);
        }

        throw ValidationException::withMessages([
            'photo' => 'Deze foto blijft na automatische verwerking te groot. Maximaal '.$this->maxMegabytes().' MB.',
        ]);
    }

    /** @return list<string> */
    private function acceptedMimes(): array
    {
        return array_values(array_unique(array_map(
            fn (string $mime): string => $this->normalizeMime($mime),
            array_filter((array) config('intake.uploads.accepted_mimes', []), 'is_string'),
        )));
    }

    private function normalizeMime(string $mime): string
    {
        return match ($mime) {
            'image/heic-sequence' => 'image/heic',
            'image/heif-sequence' => 'image/heif',
            default => $mime,
        };
    }

    private function temporaryJpegPath(string $variant): string
    {
        $path = tempnam(sys_get_temp_dir(), 'intake-'.$variant.'-');

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
            throw new \RuntimeException('Bestandsgrootte kon niet worden gelezen.');
        }

        return $size;
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            throw new \RuntimeException('Checksum kon niet worden gemaakt.');
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

    private function originalFilename(UploadedFile $file): string
    {
        return Str::limit((string) $file->getClientOriginalName(), 240, '');
    }
}
