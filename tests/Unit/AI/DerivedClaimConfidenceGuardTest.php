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

test('intake 85/86 certain phase report and assistant copy are hedged from soft meter source', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../fixtures/dossier-synthesis/intake-85-hedged-phase-certain-copy.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $guard = new DerivedClaimConfidenceGuard;
    $ceiling = $guard->ceilingFromObservationText((string) $fixture['source_observation']);

    $assistant = $guard->normalizeDerivedText((string) $fixture['assistant_summary_raw'], $ceiling);
    $report = $guard->normalizeDerivedText((string) $fixture['installer_report_summary_raw'], $ceiling);
    $highlight = $guard->normalizeDerivedText((string) $fixture['report_highlight_raw'], $ceiling);
    $intake86 = $guard->normalizeDerivedText((string) $fixture['intake_86_report_summary_raw'], $ceiling);

    expect($ceiling)->toBe('medium')
        ->and($guard->claimsUnequivocalElectricalFact((string) $fixture['assistant_summary_raw']))->toBeTrue()
        ->and($guard->claimsUnequivocalElectricalFact((string) $fixture['installer_report_summary_raw']))->toBeTrue()
        ->and($guard->claimsOverconfidentFact((string) $fixture['intake_86_report_summary_raw']))->toBeTrue()
        ->and($assistant['hedged'])->toBeTrue()
        ->and($assistant['text'])->not->toMatch('/voorzien van een 3[\s-]?fasen/i')
        ->and($assistant['text'])->toMatch('/lijkt|te controleren|mogelijk/i')
        ->and($report['hedged'])->toBeTrue()
        ->and($report['text'])->not->toMatch('/uitgevoerd met 3[\s-]?fasen/i')
        ->and($report['text'])->toMatch('/lijkt|te controleren|mogelijk/i')
        ->and($highlight['hedged'])->toBeTrue()
        ->and($highlight['text'])->toMatch('/lijkt|te controleren|mogelijk/i')
        ->and($intake86['hedged'])->toBeTrue()
        ->and($intake86['text'])->not->toMatch('/3[\s-]?fase aansluiting aanwezig/i')
        ->and($intake86['text'])->toMatch('/lijkt|te controleren|mogelijk/i');
});

test('summary payload ceiling reads hedged fusebox external fact', function () {
    $guard = new DerivedClaimConfidenceGuard;

    $ceiling = $guard->ceilingFromSummaryPayload([
        'external_facts' => [
            'fusebox_photo_assessment' => [
                'value' => [
                    'phase' => 'three_phase',
                    'evidence' => '3-fase lijkt zichtbaar',
                    'confidence' => 'medium',
                ],
                'source' => 'ai',
                'confidence' => 'medium',
            ],
        ],
    ]);

    expect($ceiling)->toBe('medium');
});
