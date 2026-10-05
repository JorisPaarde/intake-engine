<?php

declare(strict_types=1);

use App\Domains\AI\Support\DerivedClaimConfidenceGuard;

test('derived claim confidence never exceeds hedged source observation', function () {
    $guard = new DerivedClaimConfidenceGuard;

    expect($guard->ceilingFromObservationText('3-fase lijkt zichtbaar'))->toBe('medium')
        ->and($guard->capConfidence('high', 'medium'))->toBe('medium')
        ->and($guard->capConfidence('medium', 'low'))->toBe('low');
});

test('overconfident electrical claims are rewritten to lijkt / te controleren', function () {
    $guard = new DerivedClaimConfidenceGuard;

    $summary = $guard->normalizeDerivedText(
        'Elektrische aansluiting: 3-fase aanwezig',
        'medium',
    );
    $exception = $guard->normalizeDerivedText(
        'Meterkast is volledig gevuld; controleer vrije groepen voor 3-fase aansluiting.',
        'medium',
    );

    expect($summary['hedged'])->toBeTrue()
        ->and($summary['text'])->not->toMatch('/3[\s-]?fase aanwezig/i')
        ->and($summary['text'])->toMatch('/lijkt|te controleren/i')
        ->and($exception['hedged'])->toBeTrue()
        ->and($exception['text'])->toMatch('/lijkt|te controleren/i');
});
