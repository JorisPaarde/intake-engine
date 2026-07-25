<?php

declare(strict_types=1);

use App\Domains\Intake\Data\BuildingContext;
use App\Domains\Intake\Data\BuildingFootprint;
use App\Domains\Intake\Services\BuildingTypeResolver;

it('classifies multiple residences in one BAG building as an apartment', function () {
    $context = new BuildingContext(
        target: rectangle('target', 0, 0, 6, 10),
        nearby: [],
        addressableObjectCount: 3,
    );

    $inference = app(BuildingTypeResolver::class)->resolve($context);

    expect($inference?->option)->toBe('apartment')
        ->and($inference?->confidence)->toBe('high');
});

it('classifies one residence without an adjoining building as detached', function () {
    $context = new BuildingContext(
        target: rectangle('target', 0, 0, 6, 10),
        nearby: [rectangle('distant', 10, 0, 16, 10)],
        addressableObjectCount: 1,
    );

    $inference = app(BuildingTypeResolver::class)->resolve($context);

    expect($inference?->option)->toBe('detached')
        ->and($inference?->confidence)->toBe('high');
});

it('classifies a residence joined on opposite sides as terraced', function () {
    $context = new BuildingContext(
        target: rectangle('target', 0, 0, 6, 10),
        nearby: [
            rectangle('left', -6, 0, 0, 10),
            rectangle('right', 6, 0, 12, 10),
        ],
        addressableObjectCount: 1,
    );

    $inference = app(BuildingTypeResolver::class)->resolve($context);

    expect($inference?->option)->toBe('terraced')
        ->and($inference?->confidence)->toBe('high');
});

it('classifies an isolated pair of adjoining residences as semi detached', function () {
    $context = new BuildingContext(
        target: rectangle('target', 0, 0, 6, 10),
        nearby: [rectangle('partner', 6, 0, 12, 10)],
        addressableObjectCount: 1,
    );

    $inference = app(BuildingTypeResolver::class)->resolve($context);

    expect($inference?->option)->toBe('semi_detached')
        ->and($inference?->confidence)->toBe('high');
});

it('classifies the end of a longer adjoining row as a corner house', function () {
    $context = new BuildingContext(
        target: rectangle('target', 0, 0, 6, 10),
        nearby: [
            rectangle('next', 6, 0, 12, 10),
            rectangle('third', 12, 0, 18, 10),
        ],
        addressableObjectCount: 1,
    );

    $inference = app(BuildingTypeResolver::class)->resolve($context);

    expect($inference?->option)->toBe('corner')
        ->and($inference?->confidence)->toBe('high');
});

it('does not infer a type from an invalid target footprint', function () {
    $context = new BuildingContext(
        target: new BuildingFootprint('target', [[0.0, 0.0]]),
        nearby: [],
        addressableObjectCount: 1,
    );

    expect(app(BuildingTypeResolver::class)->resolve($context))->toBeNull();
});

function rectangle(string $id, float $minX, float $minY, float $maxX, float $maxY): BuildingFootprint
{
    return new BuildingFootprint($id, [
        [$minX, $minY],
        [$maxX, $minY],
        [$maxX, $maxY],
        [$minX, $maxY],
        [$minX, $minY],
    ]);
}
