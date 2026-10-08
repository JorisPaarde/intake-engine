<?php

declare(strict_types=1);

use App\Domains\Intake\Services\PhotoUploadNormalizer;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Demotest 8 okt taak 2: small JPEGs must not be upscaled via jpeg:size;
 * large JPEGs still shrink to dossier max (2048).
 */
it('keeps a small JPEG at source dimensions (no upscale to 2048)', function () {
    if (! class_exists(Imagick::class) && ! function_exists('imagecreatetruecolor')) {
        test()->markTestSkipped('Imagick/GD required');
    }

    $width = 1600;
    $height = 1200;
    $path = tempnam(sys_get_temp_dir(), 'small-jpeg-').'.jpg';

    try {
        writeFixtureJpeg($path, $width, $height, quality: 85);

        $file = new UploadedFile($path, 'small.jpg', 'image/jpeg', null, true);
        $normalizer = app(PhotoUploadNormalizer::class);
        $result = $normalizer->normalize($file);

        expect($result->originalWidth)->toBe($width)
            ->and($result->originalHeight)->toBe($height)
            ->and($result->dossierWidth)->toBe($width)
            ->and($result->dossierHeight)->toBe($height)
            ->and(max($result->dossierWidth, $result->dossierHeight))->toBeLessThanOrEqual(2048)
            ->and(max($result->analysisWidth, $result->analysisHeight))->toBeLessThanOrEqual(1536);

        // jpeg:size must not have been applied for sources below the hint.
        $metrics = $normalizer->lastDecodeMetrics();
        if (($metrics['library'] ?? null) === 'imagick') {
            expect($metrics['jpeg_size_hint'])->toBeNull();
        }

        foreach ($result->cleanupPaths as $cleanupPath) {
            @unlink($cleanupPath);
        }
    } finally {
        @unlink($path);
    }
});

it('shrinks a large JPEG to dossier max without exceeding source', function () {
    if (! class_exists(Imagick::class) && ! function_exists('imagecreatetruecolor')) {
        test()->markTestSkipped('Imagick/GD required');
    }

    $width = 4032;
    $height = 3024;
    $path = tempnam(sys_get_temp_dir(), 'large-jpeg-').'.jpg';

    try {
        writeFixtureJpeg($path, $width, $height, quality: 85);

        $file = new UploadedFile($path, 'large.jpg', 'image/jpeg', null, true);
        $result = app(PhotoUploadNormalizer::class)->normalize($file);

        expect($result->originalWidth)->toBe($width)
            ->and($result->originalHeight)->toBe($height)
            ->and(max($result->dossierWidth, $result->dossierHeight))->toBe(2048)
            ->and($result->dossierWidth)->toBe(2048)
            ->and($result->dossierHeight)->toBe(1536)
            ->and($result->dossierWidth)->toBeLessThanOrEqual($width)
            ->and($result->dossierHeight)->toBeLessThanOrEqual($height);

        foreach ($result->cleanupPaths as $cleanupPath) {
            @unlink($cleanupPath);
        }
    } finally {
        @unlink($path);
    }
});

function writeFixtureJpeg(string $path, int $width, int $height, int $quality = 85): void
{
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $image = imagecreatetruecolor($width, $height);
        expect($image)->not->toBeFalse();
        $color = imagecolorallocate($image, 40, 120, 200);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);
        expect(imagejpeg($image, $path, $quality))->toBeTrue();
        imagedestroy($image);

        return;
    }

    $image = new Imagick;
    $image->newImage($width, $height, new ImagickPixel('steelblue'));
    $image->setImageFormat('jpeg');
    $image->setImageCompressionQuality($quality);
    $image->writeImage($path);
    $image->clear();
    $image->destroy();
}
