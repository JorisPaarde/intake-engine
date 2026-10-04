<?php

declare(strict_types=1);

use App\Domains\Intake\Services\PhotoUploadNormalizer;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

uses(TestCase::class);

/**
 * BL-141: 12 MP phone JPEG must normalize under ~200 MB PHP peak so a 256M
 * web memory_limit (Hoasted PMEM 512 MB / 2) stays safe. Skips when neither
 * Imagick nor GD can create/decode the fixture (CI without image extensions).
 */
it('normalizes a 12 MP JPEG under ~200 MB peak memory', function () {
    $canImagick = class_exists(Imagick::class);
    $canGdCreate = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
    $canGdDecode = function_exists('imagecreatefromstring');

    if (! $canImagick && ! ($canGdCreate && $canGdDecode)) {
        test()->markTestSkipped(
            'Neither Imagick nor GD is available to create/decode a 12 MP JPEG fixture; '
            .'install php-imagick or php-gd to run this memory guard (BL-141).'
        );
    }

    $width = 4032;
    $height = 3024;
    $path = tempnam(sys_get_temp_dir(), '12mp-memory-');
    expect($path)->not->toBeFalse();
    $path = $path.'.jpg';

    try {
        writeTwelveMegapixelJpegFixture($path, $width, $height);

        $file = new UploadedFile($path, '12mp-phone.jpg', 'image/jpeg', null, true);
        $normalizer = app(PhotoUploadNormalizer::class);

        gc_collect_cycles();
        $peakBefore = memory_get_peak_usage(true);

        $result = $normalizer->normalize($file);

        $peakAfter = memory_get_peak_usage(true);
        $peakDelta = max(0, $peakAfter - $peakBefore);
        $budgetBytes = 200 * 1024 * 1024;

        expect($result->originalWidth)->toBe($width)
            ->and($result->originalHeight)->toBe($height)
            ->and(max($result->dossierWidth, $result->dossierHeight))->toBeLessThanOrEqual(2048)
            ->and(max($result->analysisWidth, $result->analysisHeight))->toBeLessThanOrEqual(1536)
            ->and($peakAfter)->toBeLessThan($budgetBytes)
            ->and($peakDelta)->toBeLessThan($budgetBytes);

        // Expose measured peak in assertion message context for PR/docs.
        expect($peakAfter)->toBeLessThan($budgetBytes, sprintf(
            '12 MP normalize peak was %.1f MB (budget 200 MB); library=%s',
            $peakAfter / 1048576,
            $canImagick ? 'Imagick' : 'GD',
        ));

        foreach ($result->cleanupPaths as $cleanupPath) {
            @unlink($cleanupPath);
        }
    } finally {
        @unlink($path);
    }
});

function writeTwelveMegapixelJpegFixture(string $path, int $width, int $height): void
{
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $image = imagecreatetruecolor($width, $height);
        expect($image)->not->toBeFalse();

        for ($y = 0; $y < $height; $y += 8) {
            $color = imagecolorallocate($image, ($y * 37) % 255, ($y * 17) % 255, ($y * 53) % 255);
            imagefilledrectangle($image, 0, $y, $width - 1, min($height - 1, $y + 7), $color);
        }

        expect(imagejpeg($image, $path, 90))->toBeTrue();
        imagedestroy($image);

        return;
    }

    $image = new Imagick;
    $image->newImage($width, $height, new ImagickPixel('gray'));
    $image->setImageFormat('jpeg');
    $image->setImageCompressionQuality(90);
    $image->writeImage($path);
    $image->clear();
    $image->destroy();
}
