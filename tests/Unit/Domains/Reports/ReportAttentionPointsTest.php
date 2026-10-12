<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Reports\Services\ReportAttentionPoints;

test('ReportAttentionPoints drops empty rows and keeps distinct labels', function () {
    $intake = new Intake;

    $unique = app(ReportAttentionPoints::class)->uniqueForReport($intake, [
        ['code' => 'a', 'label' => 'Eerste punt'],
        ['code' => '', 'label' => 'Zonder code'],
        ['code' => 'b', 'label' => ''],
        ['code' => 'c', 'label' => 'Eerste punt'],
        ['code' => 'd', 'label' => 'Tweede punt'],
    ]);

    expect($unique)->toBe([
        ['code' => 'a', 'label' => 'Eerste punt'],
        ['code' => 'd', 'label' => 'Tweede punt'],
    ]);
});

test('ReportAttentionPoints collapses five identical fusebox labels to one', function () {
    $intake = new Intake;
    $label = 'Meterkastfoto: niet duidelijk genoeg — klant ging toch door.';

    $points = [];
    for ($i = 0; $i < 5; $i++) {
        $points[] = [
            'code' => 'photo_continue_anyway__fusebox_photo__site',
            'label' => $label,
        ];
    }
    $points[] = [
        'code' => 'review_split_configuration',
        'label' => 'Beoordeel voor 2 ruimtes: één multi-split of meerdere single-splits.',
    ];

    $unique = app(ReportAttentionPoints::class)->uniqueForReport($intake, $points);

    expect($unique)->toHaveCount(2)
        ->and($unique[0])->toBe([
            'code' => 'photo_continue_anyway__fusebox_photo__site',
            'label' => $label,
        ])
        ->and($unique[1]['code'])->toBe('review_split_configuration');
});
