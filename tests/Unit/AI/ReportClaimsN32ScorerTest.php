<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\ReportClaimsScorer;

test('N32 fake output fails current concealed-state claim from preference only', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n32-pipe-visibility-concealed-preference.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $points = [];
    foreach ($fixture['fake_attention_output']['points'] as $point) {
        $points[] = [
            'code' => $point['code'],
            'label' => $point['label'],
            'confidence' => $point['confidence'],
            'evidence' => $point['evidence'],
        ];
    }

    $scores = (new ReportClaimsScorer(new FactScorer))->scoreLayer(
        $fixture['expected'],
        [
            'summary' => $fixture['fake_summary_output']['summary'],
            'highlights' => $fixture['fake_summary_output']['highlights'],
        ],
        $points,
        $fixture['disputed_facts'] ?? [],
        $fixture['components'],
    );

    expect($scores['claim.current_pipe_concealed_state']['status'])->toBe('wrong')
        ->and($scores['claim.current_pipe_concealed_state']['detail'])->toBe('forbidden_concept_present');
});

test('N32 visible preference fake output fails current visible-state claim', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n32-pipe-visibility-visible-preference.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $points = [];
    foreach ($fixture['fake_attention_output']['points'] as $point) {
        $points[] = [
            'code' => $point['code'],
            'label' => $point['label'],
            'confidence' => $point['confidence'],
            'evidence' => $point['evidence'],
        ];
    }

    $scores = (new ReportClaimsScorer(new FactScorer))->scoreLayer(
        $fixture['expected'],
        [
            'summary' => $fixture['fake_summary_output']['summary'],
            'highlights' => $fixture['fake_summary_output']['highlights'],
        ],
        $points,
        [],
        $fixture['components'],
    );

    expect($scores['claim.current_pipe_visible_state']['status'])->toBe('wrong');
});

test('N32 control: stated existing concealed pipes may be asserted', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n32-pipe-existing-concealed-stated.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $points = [];
    foreach ($fixture['fake_attention_output']['points'] as $point) {
        $points[] = [
            'code' => $point['code'],
            'label' => $point['label'],
            'confidence' => $point['confidence'],
            'evidence' => $point['evidence'],
        ];
    }

    $scores = (new ReportClaimsScorer(new FactScorer))->scoreLayer(
        $fixture['expected'],
        [
            'summary' => $fixture['fake_summary_output']['summary'],
            'highlights' => $fixture['fake_summary_output']['highlights'],
        ],
        $points,
        $fixture['disputed_facts'] ?? [],
        $fixture['components'],
    );

    expect($scores['claim.stated_existing_pipes_concealed']['status'])->toBe('correct')
        ->and($scores['claim.stated_existing_pipes_concealed']['detail'])->toBe('assert_ok');
});

test('N32 preference-only good wording does not trip forbidden current-state claim', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n32-pipe-visibility-concealed-preference.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $scores = (new ReportClaimsScorer(new FactScorer))->scoreLayer(
        $fixture['expected'],
        [
            'summary' => 'Je klant wil de leidingen zoveel mogelijk weggewerkt. Er is nog geen leidingroutefoto.',
            'highlights' => [
                'Voorkeur: zoveel mogelijk weggewerkt',
            ],
        ],
        [
            [
                'code' => 'pipe_conceal_preference',
                'label' => 'Klantvoorkeur: leidingen zoveel mogelijk weggewerkt (geen bewijs van huidige toestand).',
                'confidence' => 'high',
                'evidence' => [
                    [
                        'source_type' => 'answer',
                        'reference' => 'pipe_visibility@answer:survey',
                    ],
                ],
            ],
        ],
        [],
        $fixture['components'],
    );

    expect($scores['claim.current_pipe_concealed_state']['status'])->toBe('correct')
        ->and($scores['claim.current_pipe_concealed_state']['detail'])->toBe('concept_absent');
});
