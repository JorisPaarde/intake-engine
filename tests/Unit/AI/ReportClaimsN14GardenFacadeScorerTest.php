<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\ReportClaimsScorer;

test('N14-rest fake attention fails false garden-vs-facade contradiction', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n14-garden-vs-facade-existing-unit.json'),
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

    expect($scores['claim.false_garden_facade_contradiction']['status'])->toBe('wrong')
        ->and($scores['claim.false_garden_facade_contradiction']['detail'])->toBe('forbidden_concept_present');
});

test('N14-rest good wording keeps garden preference and facade observation without contradiction', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n14-garden-vs-facade-existing-unit.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $scores = (new ReportClaimsScorer(new FactScorer))->scoreLayer(
        $fixture['expected'],
        [
            'summary' => 'De klant wil de buitenunit in de tuin. Op de foto hangt een bestaande buitenunit aan de gevel; de nieuwe plek in de tuin is te bevestigen.',
            'highlights' => [
                'Gewenste plek: tuin',
                'Bestaande unit hangt aan de gevel',
            ],
        ],
        [
            [
                'code' => 'existing_outdoor_on_facade',
                'label' => 'Bestaande buitenunit hangt aan de gevel; gewenste plek tuin nog te bevestigen.',
                'confidence' => 'medium',
                'evidence' => [
                    [
                        'source_type' => 'answer',
                        'reference' => 'outdoor_location@answer:survey',
                    ],
                    [
                        'source_type' => 'upload',
                        'reference' => 'outdoor_location_photos@upload:upload_facade_existing',
                    ],
                ],
            ],
        ],
        [],
        $fixture['components'],
    );

    expect($scores['claim.false_garden_facade_contradiction']['status'])->toBe('correct')
        ->and($scores['claim.false_garden_facade_contradiction']['detail'])->toBe('concept_absent');
});
