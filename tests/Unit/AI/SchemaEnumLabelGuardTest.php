<?php

declare(strict_types=1);

use App\Domains\AI\Support\SchemaEnumLabelGuard;

test('SchemaEnumLabelGuard findRawHits detects none_visible and three_phase', function () {
    $guard = new SchemaEnumLabelGuard;

    expect($guard->findRawHits(
        "AI-analyse geeft 'none_visible' voor vrije module-ruimte.",
    ))->toBe(['none_visible'])
        ->and($guard->findRawHits(
            'AI-analyse (three_phase) en upload-status needs_clearer.',
        ))->toContain('three_phase')
        ->and($guard->findRawHits(
            'AI-analyse (three_phase) en upload-status needs_clearer.',
        ))->toContain('needs_clearer');
});

test('SchemaEnumLabelGuard findRawHits ignores Dutch prose without schema tokens', function () {
    $guard = new SchemaEnumLabelGuard;
    $dutch = 'Geen vrije moduleplek zichtbaar (groepenkast mogelijk vol); 3-fase lijkt zichtbaar, te controleren.';

    expect($guard->findRawHits($dutch))->toBe([]);
});

test('SchemaEnumLabelGuard does not treat retake as an enum token', function () {
    $guard = new SchemaEnumLabelGuard;

    expect($guard->findRawHits('retake gevraagd voor eerste foto'))->toBe([])
        ->and(SchemaEnumLabelGuard::guardedTokens())->not->toContain('retake');
});
