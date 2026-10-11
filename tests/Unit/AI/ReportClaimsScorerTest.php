<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\ReportClaimsScorer;

/**
 * Measurement-only: claim-level concepts (assert / hedged_only / forbidden).
 */
function reportClaimsScorer(): ReportClaimsScorer
{
    return new ReportClaimsScorer(new FactScorer);
}

/** @return array{summary: string, highlights: list<string>} */
function baselineConflictSummary(): array
{
    return [
        'summary' => 'De groepenkast oogt volledig bezet met automaten. Op een scherpere foto lijkt een 3-fase aansluiting zichtbaar.',
        'highlights' => [
            'Groepenkast is volledig bezet',
            '3-fase lijkt zichtbaar',
        ],
    ];
}

test('literal baseline conflict capacity claims fail even when hedged with oogt', function () {
    $expected = [
        'claims' => [
            [
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'forbidden',
            ],
            [
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ],
        ],
    ];

    $points = [[
        'code' => 'no_free_group',
        'label' => 'Geen vrije moduleplaatsen in groepenkast',
        'confidence' => 'medium',
        'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_large']],
    ]];

    $scores = reportClaimsScorer()->scoreLayer(
        $expected,
        baselineConflictSummary(),
        $points,
        [],
        ['C5', 'C7', 'C8'],
    );

    expect($scores['summary_output']['status'])->toBe('correct')
        ->and($scores['attention_output']['status'])->toBe('correct')
        ->and($scores['claim.capacity_full']['status'])->toBe('wrong')
        ->and($scores['claim.capacity_full']['detail'])->toBe('forbidden_concept_present')
        ->and($scores['claim.phase_3']['status'])->toBe('correct');
});

test('unhedged attention capacity fact fails forbidden capacity_full', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => 'Korte samenvatting zonder capaciteitsclaim voor de installateur.',
            'highlights' => ['Geen opvallende punten'],
        ],
        [[
            'code' => 'no_free_group',
            'label' => 'Geen vrije moduleplaatsen in groepenkast',
            'confidence' => 'high',
            'evidence' => [['source_type' => 'external_fact', 'reference' => 'fusebox_photo_assessment@fact:x']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.capacity_full']['status'])->toBe('wrong');
});

test('hedged medium-confidence phase wording passes hedged_only', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [
                ['id' => 'phase_3', 'concept' => 'phase_3', 'allowed' => 'hedged_only'],
                ['id' => 'capacity_full', 'concept' => 'capacity_full', 'allowed' => 'forbidden'],
            ],
        ],
        [
            'summary' => 'Op de meterkastfoto lijkt een 3-fase aansluiting zichtbaar; te controleren op locatie.',
            'highlights' => ['3-fase lijkt zichtbaar', 'Vrije moduleplek onzeker — te controleren'],
        ],
        [[
            'code' => 'verify_meter_phase',
            'label' => '3-fase lijkt zichtbaar; controleer vrije groepen op locatie',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'external_fact', 'reference' => 'fusebox_photo_assessment@fact:fact_eval_hedged']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('correct')
        ->and($scores['claim.capacity_full']['status'])->toBe('correct');
});

test('clear control counts driefasige aansluiting zichtbaar as phase_3 assert', function () {
    $scorer = reportClaimsScorer();
    expect($scorer->mentionsPhase('driefasige aansluiting zichtbaar'))->toBeTrue();

    $scores = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'assert',
            ]],
        ],
        [
            'summary' => 'Duidelijke meterkastfoto: driefasige aansluiting zichtbaar. Vrije moduleplek aanwezig.',
            'highlights' => ['driefasige aansluiting zichtbaar'],
        ],
        [[
            'code' => 'three_phase_supply',
            'label' => 'driefasige aansluiting zichtbaar',
            'confidence' => 'high',
            'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_clear']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('correct')
        ->and($scores['claim.phase_3']['detail'])->toBe('assert_ok');
});

test('unnecessary hedging fails allowed assert control', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'assert',
            ]],
        ],
        [
            'summary' => 'Op de scherpe meterkastfoto lijkt een 3-fase aansluiting zichtbaar.',
            'highlights' => ['3-fase lijkt zichtbaar'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('wrong')
        ->and($scores['claim.phase_3']['detail'])->toBe('unnecessary_hedging_for_assert');
});

test('empty or rejected layer fails and does not auto-pass claims', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'forbidden',
            ]],
        ],
        null,
        null,
        [],
        ['C5'],
        [
            'summary_rejection' => 'pipeline_rejected: validation',
            'attention_rejection' => 'gateway_output_not_attention_points_shape',
        ],
    );

    expect($scores['summary_output']['status'])->toBe('wrong')
        ->and($scores['summary_output']['rejection'])->toBe('pipeline_rejected: validation')
        ->and($scores['attention_output']['status'])->toBe('wrong')
        ->and($scores['claim.phase_3']['status'])->toBe('not_supported');
});

test('attention code synonyms match capacity_full concept without exact code list', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => 'Samenvatting zonder capaciteitstekst voor de installateur hier.',
            'highlights' => ['Overige aandachtspunten volgen'],
        ],
        [[
            'code' => 'expansion_required',
            'label' => 'Uitbreiding van de groepenkast is nodig',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:x']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.capacity_full']['status'])->toBe('wrong');
});
