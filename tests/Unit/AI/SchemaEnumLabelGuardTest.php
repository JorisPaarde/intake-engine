<?php

declare(strict_types=1);

use App\Domains\AI\Support\SchemaEnumLabelGuard;
use App\Domains\Intake\Support\FuseboxAssessmentLabels;

test('SchemaEnumLabelGuard replaces none_visible with FuseboxAssessmentLabels Dutch text', function () {
    $guard = new SchemaEnumLabelGuard;
    $dutch = FuseboxAssessmentLabels::emptyModuleSpace('none_visible');

    $result = $guard->apply(
        "Beschikbaarheid vrije groep is onbevestigd; AI-analyse geeft 'none_visible' voor vrije module-ruimte.",
    );

    expect($result['replaced'])->toBe(['none_visible'])
        ->and($result['text'])->toContain($dutch)
        ->and($result['text'])->not->toContain('none_visible')
        ->and($guard->findRawHits($result['text']))->toBe([]);
});

test('SchemaEnumLabelGuard replaces three_phase and needs_clearer without touching Dutch prose', function () {
    $guard = new SchemaEnumLabelGuard;

    $result = $guard->apply(
        'AI-analyse (three_phase) en upload-status needs_clearer voor de eerste foto.',
    );

    expect($result['replaced'])->toContain('three_phase')
        ->and($result['replaced'])->toContain('needs_clearer')
        ->and($result['text'])->toContain(FuseboxAssessmentLabels::phase('three_phase'))
        ->and($result['text'])->toContain('scherpere foto gewenst')
        ->and($result['text'])->not->toContain('three_phase')
        ->and($result['text'])->not->toContain('needs_clearer');
});

test('SchemaEnumLabelGuard leaves normal Dutch labels untouched', function () {
    $guard = new SchemaEnumLabelGuard;
    $dutch = 'Geen vrije moduleplek zichtbaar (groepenkast mogelijk vol); 3-fase lijkt zichtbaar, te controleren.';

    $result = $guard->apply($dutch);

    expect($result['replaced'])->toBe([])
        ->and($result['text'])->toBe($dutch);
});

test('SchemaEnumLabelGuard does not treat retake as an enum token', function () {
    $guard = new SchemaEnumLabelGuard;

    expect($guard->findRawHits('retake gevraagd voor eerste foto'))->toBe([])
        ->and(SchemaEnumLabelGuard::guardedTokens())->not->toContain('retake');
});
