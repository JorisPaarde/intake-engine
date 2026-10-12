<?php

declare(strict_types=1);

use App\Domains\Intake\Support\PhotoUploadLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config([
        'intake.uploads.max_kilobytes' => 8192,
        'intake.uploads.hard_max_bytes' => 8 * 1024 * 1024,
        'intake.uploads.hard_max_megapixels' => 24,
        'intake.uploads.too_large_message' => 'Deze foto is groter dan 8 MB. Maak een nieuwe foto of kies een kleinere.',
    ]);
});

test('app and hard limits allow 8 MB phone originals', function () {
    expect(PhotoUploadLimits::appMaxKilobytes())->toBe(8192)
        ->and(PhotoUploadLimits::appMaxBytes())->toBe(8 * 1024 * 1024)
        ->and(PhotoUploadLimits::hardMaxBytes())->toBe(8 * 1024 * 1024)
        ->and(PhotoUploadLimits::validationMaxKilobytes())->toBe(8192)
        ->and(PhotoUploadLimits::temporaryUploadMaxKilobytes())->toBeGreaterThanOrEqual(10240)
        ->and(PhotoUploadLimits::exceedsHardByteLimit(3_100_000))->toBeFalse()
        ->and(PhotoUploadLimits::exceedsHardByteLimit(8 * 1024 * 1024))->toBeFalse()
        ->and(PhotoUploadLimits::exceedsHardByteLimit(8 * 1024 * 1024 + 1))->toBeTrue();
});

test('too-large message states the 8 MB limit in Dutch', function () {
    expect(PhotoUploadLimits::tooLargeMessage())
        ->toBe('Deze foto is groter dan 8 MB. Maak een nieuwe foto of kies een kleinere.')
        ->and(PhotoUploadLimits::tooLargeMessage())->toContain('8 MB');
});

test('3.1 MB JPEG is acceptable under hard limits', function () {
    $file = UploadedFile::fake()->createWithContent(
        'phone-3mb.jpg',
        str_repeat('x', 3_100_000),
    );

    PhotoUploadLimits::assertUploadedFileAcceptable($file, 3024, 4032);

    expect(PhotoUploadLimits::exceedsHardMegapixelLimit(3024, 4032))->toBeFalse();
});

test('file above 8 MB is rejected with the Dutch limit message', function () {
    $file = UploadedFile::fake()->createWithContent(
        'too-big.jpg',
        str_repeat('x', 8 * 1024 * 1024 + 4096),
    );

    expect(fn () => PhotoUploadLimits::assertUploadedFileAcceptable($file))
        ->toThrow(ValidationException::class);

    try {
        PhotoUploadLimits::assertUploadedFileAcceptable($file);
    } catch (ValidationException $exception) {
        expect($exception->errors()['photo'][0] ?? null)->toBe(PhotoUploadLimits::tooLargeMessage());
    }
});
