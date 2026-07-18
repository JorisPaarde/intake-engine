<?php

declare(strict_types=1);

it('returns health status including php upload limits', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure([
            'app',
            'status',
            'environment',
            'php',
            'laravel',
            'database',
            'queue',
            'php_upload' => [
                'upload_max_filesize',
                'post_max_size',
                'max_file_uploads',
                'app_max_kilobytes',
            ],
            'time',
        ]);

    expect($response->json('php_upload.upload_max_filesize'))->toBeString()->not->toBeEmpty()
        ->and($response->json('php_upload.post_max_size'))->toBeString()->not->toBeEmpty()
        ->and($response->json('php_upload.max_file_uploads'))->toBeInt()->toBeGreaterThan(0)
        ->and($response->json('php_upload.app_max_kilobytes'))->toBe(5120);
});
