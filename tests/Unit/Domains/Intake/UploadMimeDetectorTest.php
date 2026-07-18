<?php

declare(strict_types=1);

use App\Domains\Intake\Services\UploadMimeDetector;
use Illuminate\Http\UploadedFile;

it('detects heic by sniffing iso bmff brands for octet stream uploads', function () {
    $file = detectorUploadedFile(
        "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1",
        'iphone.heic',
        'application/octet-stream',
    );

    try {
        expect((new UploadMimeDetector)->detect($file))->toBe('image/heic');
    } finally {
        @unlink($file->getPathname());
    }
});

it('detects heif sequence variants from iso bmff brands', function () {
    $file = detectorUploadedFile(
        "\x00\x00\x00\x18ftypmsf1\x00\x00\x00\x00mif1",
        'burst.heif',
        'image/heif-sequence',
    );

    try {
        expect((new UploadMimeDetector)->detect($file))->toBe('image/heif');
    } finally {
        @unlink($file->getPathname());
    }
});

it('does not trust a heic extension when the server detects pdf content', function () {
    $file = detectorUploadedFile(
        "%PDF-1.7\n",
        'contract.heic',
        'image/heic',
    );

    try {
        expect((new UploadMimeDetector)->detect($file))->toBe('application/pdf');
    } finally {
        @unlink($file->getPathname());
    }
});

it('prefers server-detected image types over generic client metadata', function () {
    $file = detectorUploadedFile(
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true) ?: '',
        'photo.bin',
        'application/octet-stream',
    );

    try {
        expect((new UploadMimeDetector)->detect($file))->toBe('image/png');
    } finally {
        @unlink($file->getPathname());
    }
});

function detectorUploadedFile(string $contents, string $name, string $clientMime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'mime-detector-');

    if ($path === false) {
        throw new RuntimeException('Could not create temporary detector fixture.');
    }

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $clientMime, null, true);
}
