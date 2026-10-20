<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiAlertMessageSanitizer;

test('redacts api keys tokens and bearer headers from alert messages', function () {
    $sanitizer = new AiAlertMessageSanitizer;
    $apiKey = fakeApiKey('sk-proj-');
    $otherKey = fakeApiKey('or-v1-');

    $out = $sanitizer->sanitize(
        "Provider zei: Bearer {$apiKey} en api_key={$apiKey} plus {$otherKey}",
        $apiKey,
    );

    expect($out)->not->toContain(substr($apiKey, 8))
        ->and($out)->not->toContain('sk-proj-')
        ->and($out)->not->toContain($otherKey)
        ->and($out)->toContain('[redacted]')
        ->and($out)->toContain('[api-key-redacted]');
});

test('limits sanitized alert message length', function () {
    $sanitizer = new AiAlertMessageSanitizer;
    $long = str_repeat('fout ', 200);

    expect(mb_strlen($sanitizer->sanitize($long, '')))->toBeLessThanOrEqual(281);
});
