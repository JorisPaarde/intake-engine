<?php

declare(strict_types=1);

/**
 * BL-108: externe AI hangt alleen van provider/featurevlaggen/budget af.
 * Er is geen DPIA-/privacytoets-config of middleware-gate.
 */
test('ai config has no dpia or privacy-toets activation gate', function () {
    $ai = config('ai');

    expect($ai)->toBeArray()
        ->and($ai)->not->toHaveKey('dpia')
        ->and($ai)->not->toHaveKey('dpia_required')
        ->and($ai)->not->toHaveKey('privacy_gate')
        ->and($ai)->not->toHaveKey('privacy_approved')
        ->and(array_key_exists('provider', $ai))->toBeTrue()
        ->and(array_key_exists('budget', $ai))->toBeTrue()
        ->and(array_key_exists('photo_inference', $ai))->toBeTrue()
        ->and(array_key_exists('text_inference', $ai))->toBeTrue()
        ->and(array_key_exists('route', $ai))->toBeTrue()
        ->and(array_key_exists('dossier', $ai))->toBeTrue();
});

test('ai feature flags default to off without blocking on a privacy toets', function () {
    expect((bool) config('ai.photo_inference.enabled'))->toBeFalse()
        ->and((bool) config('ai.text_inference.enabled'))->toBeFalse()
        ->and((bool) config('ai.route.enabled'))->toBeFalse()
        ->and((bool) config('ai.dossier.enabled'))->toBeFalse()
        ->and((bool) config('ai.budget.enforced'))->toBeTrue();
});
