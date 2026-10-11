<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\ReportClaimsScorer;
use App\Domains\AI\Support\DerivedClaimConfidenceGuard;

/**
 * Measurement-only: fixture phrase lists + structured confidence checks (option a).
 */
function reportClaimsScorer(): ReportClaimsScorer
{
    return new ReportClaimsScorer(new FactScorer, new DerivedClaimConfidenceGuard);
}

test('report claims scorer fails verbatim unhedged three-phase presence claim', function () {
    $expected = [
        'must_not_assert' => [
            'driefasige groepenkast aanwezig',
            'uitbreiding voor nieuwe groep vereist',
        ],
        'must_hedge_phase_claims' => true,
        'allow_unhedged_three_phase' => false,
        'attention_confidence_ceiling' => 'medium',
        'require_evidence_on_points' => true,
    ];

    $summary = [
        'summary' => 'Driefasige groepenkast aanwezig. Installatie lijkt haalbaar.',
        'highlights' => [
            'Driefasige meterkast aanwezig',
            'Groepenkast is volledig bezet; uitbreiding voor nieuwe groep vereist',
        ],
    ];

    $points = [[
        'code' => 'no_free_group',
        'label' => 'Groepenkast is volledig bezet; uitbreiding voor nieuwe groep vereist',
        'confidence' => 'high',
        'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_large']],
    ]];

    $scores = reportClaimsScorer()->scoreLayer($expected, $summary, $points, [], ['C5', 'C7', 'C8']);

    expect($scores['must_not_assert.0']['status'])->toBe('wrong')
        ->and($scores['must_not_assert.1']['status'])->toBe('wrong')
        ->and($scores['attention_confidence_ceiling']['status'])->toBe('wrong');
});

test('report claims scorer passes hedged medium-confidence fusebox wording', function () {
    $expected = [
        'must_not_assert' => [
            'driefasige groepenkast aanwezig',
            '3-fase aanwezig',
            'uitbreiding voor nieuwe groep vereist',
        ],
        'must_hedge_phase_claims' => true,
        'allow_unhedged_three_phase' => false,
        'attention_confidence_ceiling' => 'medium',
        'require_evidence_on_points' => true,
    ];

    $summary = [
        'summary' => 'Op de meterkastfoto lijkt een 3-fase aansluiting zichtbaar; te controleren op locatie.',
        'highlights' => [
            '3-fase lijkt zichtbaar',
            'Vrije moduleplek onzeker — te controleren',
        ],
    ];

    $points = [[
        'code' => 'verify_meter_phase',
        'label' => '3-fase lijkt zichtbaar; controleer vrije groepen op locatie',
        'confidence' => 'medium',
        'evidence' => [['source_type' => 'external_fact', 'reference' => 'fusebox_photo_assessment@fact:fact_eval_hedged']],
    ]];

    $scores = reportClaimsScorer()->scoreLayer($expected, $summary, $points, [], ['C5']);

    expect($scores['must_not_assert.0']['status'])->toBe('correct')
        ->and($scores['must_not_assert.1']['status'])->toBe('correct')
        ->and($scores['must_not_assert.2']['status'])->toBe('correct')
        ->and($scores['summary_phase_hedge']['status'])->toBe('correct')
        ->and($scores['attention_confidence_ceiling']['status'])->toBe('correct')
        ->and($scores['attention_evidence']['status'])->toBe('correct');
});

test('report claims scorer allows clear high-confidence three-phase control', function () {
    $expected = [
        'must_not_assert' => [],
        'must_hedge_phase_claims' => false,
        'allow_unhedged_three_phase' => true,
        'attention_confidence_ceiling' => 'high',
        'require_evidence_on_points' => true,
    ];

    $summary = [
        'summary' => 'Duidelijke meterkastfoto: 3-fase aansluiting aanwezig. Vrije moduleplek zichtbaar.',
        'highlights' => ['3-fase aansluiting aanwezig'],
    ];

    $points = [[
        'code' => 'three_phase_supply',
        'label' => '3-fase aansluiting aanwezig',
        'confidence' => 'high',
        'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_clear']],
    ]];

    $scores = reportClaimsScorer()->scoreLayer($expected, $summary, $points, [], ['C5']);

    expect($scores['summary_may_state_phase']['status'])->toBe('correct')
        ->and($scores['attention_confidence_ceiling']['status'])->toBe('correct')
        ->and($scores)->not->toHaveKey('summary_phase_hedge');
});

test('driefasige wording is caught by fixture must_not_assert even when guard phase regex misses it', function () {
    $guard = new DerivedClaimConfidenceGuard;
    $claim = 'Driefasige groepenkast aanwezig';

    // Documents the production-guard gap (do NOT extend regexes — ADR-0016).
    expect($guard->claimsOverconfidentFact($claim))->toBeFalse();

    $scores = reportClaimsScorer()->scoreLayer(
        [
            'must_not_assert' => ['driefasige groepenkast aanwezig'],
            'must_hedge_phase_claims' => true,
            'allow_unhedged_three_phase' => false,
        ],
        ['summary' => $claim.'. Extra toelichting voor minimale lengte.', 'highlights' => ['Controleer meterkast']],
        [],
        [],
        ['C5'],
    );

    expect($scores['must_not_assert.0']['status'])->toBe('wrong');
});
