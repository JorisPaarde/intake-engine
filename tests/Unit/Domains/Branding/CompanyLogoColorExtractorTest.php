<?php

declare(strict_types=1);

use App\Domains\Branding\Services\CompanyLogoColorExtractor;

test('extractor selects a deterministic saturated representative color and safe tokens', function () {
    $path = tempnam(sys_get_temp_dir(), 'logo-color-').'.png';
    $image = imagecreatetruecolor(30, 30);
    imagesavealpha($image, true);

    $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);
    imagefill($image, 0, 0, $transparent);

    $white = imagecolorallocate($image, 250, 250, 250);
    $black = imagecolorallocate($image, 5, 5, 5);
    $blue = imagecolorallocate($image, 0, 113, 227);

    imagefilledrectangle($image, 0, 0, 29, 9, $white);
    imagefilledrectangle($image, 0, 10, 29, 14, $black);
    imagefilledrectangle($image, 0, 15, 29, 29, $blue);

    imagepng($image, $path);
    unset($image);

    $tokens = app(CompanyLogoColorExtractor::class)->extract($path);

    expect($tokens['primary'])->toBe('#0071E3')
        ->and($tokens['accent'])->toBe('#005EC0')
        ->and($tokens['on_primary'])->toBe('#FFFFFF');

    @unlink($path);
});

test('extractor falls back when the image has only low information pixels', function () {
    $path = tempnam(sys_get_temp_dir(), 'logo-color-').'.png';
    $image = imagecreatetruecolor(20, 20);
    $white = imagecolorallocate($image, 252, 252, 252);
    imagefill($image, 0, 0, $white);
    imagepng($image, $path);
    unset($image);

    $tokens = app(CompanyLogoColorExtractor::class)->extract($path);

    expect($tokens['primary'])->toBe('#0071E3')
        ->and($tokens['accent'])->toBe('#005EC0')
        ->and($tokens['on_primary'])->toBe('#FFFFFF');

    @unlink($path);
});
