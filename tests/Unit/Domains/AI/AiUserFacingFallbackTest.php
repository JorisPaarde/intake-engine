<?php

declare(strict_types=1);

use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Services\AiUserFacingFallback;

test('user-facing fallback never includes raw provider text', function () {
    $fallback = new AiUserFacingFallback;
    $exception = new AiClientException(
        'Externe AI-provider gaf status 402. Insufficient credits for sk-proj-SECRET',
        httpStatus: 402,
    );

    $message = $fallback->forException($exception);

    expect($message)->toBe('De AI-dienst is tijdelijk niet beschikbaar. Probeer het later opnieuw.')
        ->and($message)->not->toContain('402')
        ->and($message)->not->toContain('Insufficient')
        ->and($message)->not->toContain('SECRET');
});

test('catalog fallback stays dutch and secret-free', function () {
    $fallback = new AiUserFacingFallback;
    $exception = new AiClientException('boom status 429 rate limit', httpStatus: 429);

    $message = $fallback->forCatalogException($exception);

    expect($message)->toContain('Catalogus-AI')
        ->and($message)->toContain('overbelast')
        ->and($message)->not->toContain('429')
        ->and($message)->not->toContain('boom');
});

test('generic fallback when no http status is known', function () {
    expect((new AiUserFacingFallback)->forException(new RuntimeException('raw')))
        ->toBe(AiUserFacingFallback::GENERIC);
});
