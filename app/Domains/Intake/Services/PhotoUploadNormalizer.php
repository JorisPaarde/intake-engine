<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Support\PhotoUploadLimits;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagick;
use Throwable;

/**
 * Converts every accepted phone photo into two metadata-free JPEG variants:
 * a 2048px dossier image and a 1536px AI-analysis image (BL-030).
 *
 * Staging intake 82: a 12 MP JPEG took ~79 s preprocess on shared LVE when Imagick
 * fully decoded the source. JPEG path now uses jpeg:size shrink-on-load, one decode,
 * downscale once to dossier size, then derive the analysis variant from that.
 */
final class PhotoUploadNormalizer
{
    /**
     * Metrics from the last {@see normalize()} call (tests / diagnostics).
     *
     * @var array{full_decodes: int, jpeg_size_hint: string|null, library: string}|null
     */
    private ?array $lastDecodeMetrics = null;

    public function __construct(
        private readonly UploadMimeDetector $mimeDetector,
    ) {}

    /**
     * @return array{full_decodes: int, jpeg_size_hint: string|null, library: string}|null
     */
    public function lastDecodeMetrics(): ?array
    {
        return $this->lastDecodeMetrics;
    }

    public function normalize(UploadedFile $file): NormalizedPhotoUpload
    {
        $this->lastDecodeMetrics = null;
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
                $dimensions = $this->createWithImagick($sourcePath, $dossierPath, $analysisPath, $mime);
            } else {
                if (in_array($mime, ['image/heic', 'image/heif'], true)) {
                    throw ValidationException::withMessages([
                        'photo' => 'HEIC-foto’s kunnen tijdelijk niet automatisch worden verwerkt. Probeer het later opnieuw.',
                    ]);
                }

                $dimensions = $this->createWithGd($sourcePath, $dossierPath, $analysisPath, $mime);
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
                dossierWidth: $dimensions['dossier_width'],
                dossierHeight: $dimensions['dossier_height'],
                analysisWidth: $dimensions['analysis_width'],
                analysisHeight: $dimensions['analysis_height'],
                originalWidth: $dimensions['original_width'],
                originalHeight: $dimensions['original_height'],
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

    /**
     * @return array{
     *     dossier_width: int,
     *     dossier_height: int,
     *     analysis_width: int,
     *     analysis_height: int,
     *     original_width: int,
     *     original_height: int
     * }
     */
    private function createWithImagick(
        string $sourcePath,
        string $dossierPath,
        string $analysisPath,
        string $mime,
    ): array {
        $this->applyImagickResourceLimits();
        $dossierMax = (int) config('intake.uploads.dossier.max_long_edge', 2048);
        $analysisMax = (int) config('intake.uploads.analysis.max_long_edge', 1536);

        // Ping for true original dimensions without decoding pixels (cheap).
        [$originalWidth, $originalHeight] = $this->pingImagickDimensions($sourcePath);

        $jpegSizeHint = null;
        $source = new Imagick;

        try {
            // Shrink-on-load for JPEG: libjpeg DCT-scales during decode so a 12 MP
            // phone photo never materialises at full resolution (staging intake 82 ~79 s).
            // Only hint when the source is larger than the hint — otherwise libjpeg may
            // upsample small JPEGs (demotest 8 okt: 1600×1200 → 2048×1536).
            if ($mime === 'image/jpeg') {
                $hintEdge = max(64, $dossierMax * 2);
                $sourceLongEdge = max($originalWidth, $originalHeight);
                // Only shrink-on-load when the source exceeds the dossier edge.
                // Cap the hint at the source long edge so libjpeg never upscales
                // (demotest 8 okt: 1600×1200 stayed 1600; 4032 still hints ≤4032).
                if ($sourceLongEdge > $dossierMax) {
                    $effectiveHint = min($hintEdge, $sourceLongEdge);
                    $jpegSizeHint = $effectiveHint.'x'.$effectiveHint;
                    $source->setOption('jpeg:size', $jpegSizeHint);
                }
            }

            $source->readImage($sourcePath);
            $this->lastDecodeMetrics = [
                'full_decodes' => 1,
                'jpeg_size_hint' => $jpegSizeHint,
                'library' => 'imagick',
            ];

            // HEIC/multi-frame: index 0 can be a small preview — pick the largest frame.
            // Skip the scan for single-frame JPEG (common phone path).
            if ($mime !== 'image/jpeg' && $source->getNumberImages() > 1) {
                $bestIndex = 0;
                $bestArea = 0;
                $frameCount = max(1, $source->getNumberImages());
                for ($index = 0; $index < $frameCount; $index++) {
                    $source->setIteratorIndex($index);
                    $area = max(1, $source->getImageWidth()) * max(1, $source->getImageHeight());
                    if ($area > $bestArea) {
                        $bestArea = $area;
                        $bestIndex = $index;
                    }
                }
                $source->setIteratorIndex($bestIndex);
            }

            $source->autoOrient();
            $source->stripImage();

            // Flatten only when transparency / layers exist — plain JPEG skips this.
            if ($source->getNumberImages() > 1 || $source->getImageAlphaChannel()) {
                $source->setImageBackgroundColor('white');
                $source = $source->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            if ($originalWidth <= 1 || $originalHeight <= 1) {
                $originalWidth = max(1, $source->getImageWidth());
                $originalHeight = max(1, $source->getImageHeight());
            }

            // Safety net: never keep pixels larger than the true original
            // (jpeg:size / decoder quirks must not upscale).
            $this->clampImagickToOriginal($source, $originalWidth, $originalHeight);

            // One working resize to the largest variant; analysis is derived from it.
            $this->resizeImagick($source, $dossierMax);

            $dossierDims = $this->writeImagickVariant(
                $source,
                $dossierPath,
                $dossierMax,
                (int) config('intake.uploads.dossier.jpeg_quality', 82),
                alreadySized: true,
            );
            $analysisDims = $this->writeImagickVariant(
                $source,
                $analysisPath,
                $analysisMax,
                (int) config('intake.uploads.analysis.jpeg_quality', 80),
                alreadySized: false,
            );

            return [
                'dossier_width' => $dossierDims['width'],
                'dossier_height' => $dossierDims['height'],
                'analysis_width' => $analysisDims['width'],
                'analysis_height' => $analysisDims['height'],
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
            ];
        } finally {
            $source->clear();
            $source->destroy();
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pingImagickDimensions(string $sourcePath): array
    {
        $ping = new Imagick;

        try {
            $ping->pingImage($sourcePath);

            return [
                max(1, $ping->getImageWidth()),
                max(1, $ping->getImageHeight()),
            ];
        } catch (Throwable) {
            return [0, 0];
        } finally {
            $ping->clear();
            $ping->destroy();
        }
    }

    private function applyImagickResourceLimits(): void
    {
        $memory = max(32 * 1024 * 1024, (int) config('intake.uploads.imagick_memory_bytes', 128 * 1024 * 1024));
        $map = max($memory, (int) config('intake.uploads.imagick_map_bytes', 192 * 1024 * 1024));

        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $memory);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, $map);
    }

    /**
     * @return array{width: int, height: int}
     */
    private function writeImagickVariant(
        Imagick $source,
        string $destination,
        int $maxLongEdge,
        int $initialQuality,
        bool $alreadySized = false,
    ): array {
        // Dossier: write the already-resized working image (no second clone/resize).
        // Analysis: clone once and shrink from the dossier-sized working copy.
        $image = $alreadySized ? $source : clone $source;
        $ownsImage = ! $alreadySized;

        try {
            if (! $alreadySized) {
                $this->resizeImagick($image, $maxLongEdge);
            }
            $image->setImageFormat('jpeg');
            $image->setInterlaceScheme(Imagick::INTERLACE_JPEG);
            $image->stripImage();

            for ($quality = min(100, max(50, $initialQuality)); $quality >= 50; $quality -= 8) {
                $image->setImageCompressionQuality($quality);
                $image->writeImage($destination);
                clearstatcache(true, $destination);

                if ($this->sizeBytes($destination) <= $this->maxBytes()) {
                    return [
                        'width' => $image->getImageWidth(),
                        'height' => $image->getImageHeight(),
                    ];
                }
            }

            throw ValidationException::withMessages([
                'photo' => 'Deze foto blijft na automatische verwerking te groot. Maximaal '.$this->maxMegabytes().' MB.',
            ]);
        } finally {
            if ($ownsImage) {
                $image->clear();
                $image->destroy();
            }
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

    /**
     * If decode produced more pixels than the source, shrink back to original dims.
     */
    private function clampImagickToOriginal(Imagick $image, int $originalWidth, int $originalHeight): void
    {
        if ($originalWidth <= 1 || $originalHeight <= 1) {
            return;
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        if ($width <= $originalWidth && $height <= $originalHeight) {
            return;
        }

        $image->thumbnailImage($originalWidth, $originalHeight, true);
    }

    /**
     * @return array{
     *     dossier_width: int,
     *     dossier_height: int,
     *     analysis_width: int,
     *     analysis_height: int,
     *     original_width: int,
     *     original_height: int
     * }
     */
    private function createWithGd(
        string $sourcePath,
        string $dossierPath,
        string $analysisPath,
        string $mime,
    ): array {
        if (! function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD ontbreekt.');
        }

        $binary = file_get_contents($sourcePath);
        $image = $binary === false ? false : @imagecreatefromstring($binary);
        unset($binary);

        if (! $image instanceof GdImage) {
            throw new \RuntimeException('Foto kon niet met GD worden gelezen.');
        }

        $this->lastDecodeMetrics = [
            'full_decodes' => 1,
            'jpeg_size_hint' => null,
            'library' => 'gd',
        ];

        try {
            $image = $this->orientGd($image, $sourcePath, $mime);
            $originalWidth = max(1, imagesx($image));
            $originalHeight = max(1, imagesy($image));

            $dossierMax = (int) config('intake.uploads.dossier.max_long_edge', 2048);
            // Drop full-resolution pixels before writing variants (BL-141).
            // GD has no jpeg:size equivalent — one full decode, then shrink once.
            // Never enlarge: cap at min(dossierMax, original long edge).
            $image = $this->downscaleGdWorkingImage(
                $image,
                min($dossierMax, max($originalWidth, $originalHeight)),
            );

            $dossierDims = $this->writeGdVariant(
                $image,
                $dossierPath,
                $dossierMax,
                (int) config('intake.uploads.dossier.jpeg_quality', 82),
                alreadySized: true,
            );
            $analysisDims = $this->writeGdVariant(
                $image,
                $analysisPath,
                (int) config('intake.uploads.analysis.max_long_edge', 1536),
                (int) config('intake.uploads.analysis.jpeg_quality', 80),
                alreadySized: false,
            );

            return [
                'dossier_width' => $dossierDims['width'],
                'dossier_height' => $dossierDims['height'],
                'analysis_width' => $analysisDims['width'],
                'analysis_height' => $analysisDims['height'],
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
            ];
        } finally {
            imagedestroy($image);
        }
    }

    private function downscaleGdWorkingImage(GdImage $image, int $maxLongEdge): GdImage
    {
        if ($maxLongEdge <= 0) {
            return $image;
        }

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $longEdge = max($sourceWidth, $sourceHeight);

        if ($longEdge <= $maxLongEdge) {
            return $image;
        }

        $scale = $maxLongEdge / $longEdge;
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $scaled = imagecreatetruecolor($width, $height);

        if (! $scaled instanceof GdImage) {
            throw new \RuntimeException('JPEG-variant kon niet worden aangemaakt.');
        }

        $white = imagecolorallocate($scaled, 255, 255, 255);
        imagefill($scaled, 0, 0, $white);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        imagedestroy($image);

        return $scaled;
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

    /**
     * @return array{width: int, height: int}
     */
    private function writeGdVariant(
        GdImage $source,
        string $destination,
        int $maxLongEdge,
        int $quality,
        bool $alreadySized = false,
    ): array {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $longEdge = max($sourceWidth, $sourceHeight);
        $scale = (! $alreadySized && $maxLongEdge > 0 && $longEdge > $maxLongEdge)
            ? $maxLongEdge / $longEdge
            : 1.0;
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        // Dossier already matches source dims — encode in place without a second buffer.
        if ($alreadySized || ($width === $sourceWidth && $height === $sourceHeight)) {
            for ($currentQuality = min(100, max(50, $quality)); $currentQuality >= 50; $currentQuality -= 8) {
                if (! imagejpeg($source, $destination, $currentQuality)) {
                    throw new \RuntimeException('JPEG-variant kon niet worden opgeslagen.');
                }

                clearstatcache(true, $destination);

                if ($this->sizeBytes($destination) <= $this->maxBytes()) {
                    return ['width' => $sourceWidth, 'height' => $sourceHeight];
                }
            }

            throw ValidationException::withMessages([
                'photo' => 'Deze foto blijft na automatische verwerking te groot. Maximaal '.$this->maxMegabytes().' MB.',
            ]);
        }

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
                    return ['width' => $width, 'height' => $height];
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
        // Incoming source may be a phone original (≤ hard max); processed variants
        // are still capped by max_kilobytes in write*Variant (demotest 8 okt taak 3).
        PhotoUploadLimits::assertAcceptable($sizeBytes);
    }

    private function maxBytes(): int
    {
        return (int) config('intake.uploads.max_kilobytes', 8192) * 1024;
    }

    private function maxMegabytes(): string
    {
        return number_format((int) config('intake.uploads.max_kilobytes', 8192) / 1024, 0, ',', '.');
    }

    private function originalFilename(UploadedFile $file): string
    {
        return Str::limit((string) $file->getClientOriginalName(), 240, '');
    }
}
