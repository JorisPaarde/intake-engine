<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Support\PhotoObservationRelevance;
use App\Enums\AircoPlacementType;

function observation(string $text): DossierRecord
{
    return (new DossierRecord)->forceFill([
        'value' => ['text' => $text, 'impact' => 'installation'],
    ]);
}

function intakeWithWallUnit(): Intake
{
    $intake = new Intake;
    $placement = (new AircoPlacementOption)->forceFill([
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit boven de deur',
        'description' => 'Wandmodel hoog aan de muur',
    ]);
    $intake->setRelation('aircoPlacements', collect([$placement]));

    return $intake;
}

test('visible glass or radiator notes do not warrant a new photo task', function () {
    $relevance = app(PhotoObservationRelevance::class);

    expect($relevance->warrantsPhotoTask(observation('Grote glaspartij zichtbaar aan de zuidkant.')))->toBeFalse()
        ->and($relevance->warrantsPhotoTask(observation('Lage radiatoren aanwezig onder het raam.')))->toBeFalse()
        ->and($relevance->warrantsPhotoTask(observation('De muur is te donker zichtbaar.')))->toBeTrue()
        ->and($relevance->warrantsPhotoTask(observation('Stopcontact niet zichtbaar; maak een foto dichterbij.')))->toBeTrue();
});

test('floor-model risks are filtered when a wall unit above the door is planned', function () {
    $relevance = app(PhotoObservationRelevance::class);
    $intake = intakeWithWallUnit();
    $floorRisk = observation('Vloermodel lijkt lastig door radiator en deur.');
    $glassNote = observation('Grote glaspartij zichtbaar.');

    expect($relevance->isRelevantToSolution($intake, $floorRisk))->toBeFalse()
        ->and($relevance->isRelevantToSolution($intake, $glassNote))->toBeTrue()
        ->and($relevance->filterForDisplay($intake, collect([$floorRisk, $glassNote]))->count())->toBe(1)
        ->and($relevance->filterForDisplay($intake, collect([$floorRisk, $glassNote]))->first()->value['text'])
        ->toContain('glaspartij');
});
