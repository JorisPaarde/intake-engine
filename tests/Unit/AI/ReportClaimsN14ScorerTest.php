<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\ReportClaimsScorer;

test('N14 fake attention output fails false room contradiction claim', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n14-attic-vs-office-furnishing.json'),
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

    expect($scores['claim.false_room_use_contradiction']['status'])->toBe('wrong')
        ->and($scores['claim.false_room_use_contradiction']['detail'])->toBe('forbidden_concept_present');
});

test('N14 fake attention output fails false unknown-phase contradiction', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n14-phase-unknown-vs-ai.json'),
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

    expect($scores['claim.false_unknown_phase_contradiction']['status'])->toBe('wrong');
});

test('N14 control: stated 1-fase vs AI 3-fase asserts real contradiction', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n14-phase-stated-vs-ai.json'),
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

    expect($scores['claim.real_phase_contradiction']['status'])->toBe('correct')
        ->and($scores['claim.real_phase_contradiction']['detail'])->toBe('assert_ok')
        ->and($scores['claim.phase_3']['status'])->toBe('correct');
});
