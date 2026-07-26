<?php

declare(strict_types=1);

use App\Domains\Intake\Services\BuildingTypeResolver;
use App\Domains\Intake\Services\PdokBuildingContextService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('builds a BAG context from the target building and its surroundings', function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/collections/pand/items')
            && ($request->data()['identificatie'] ?? null) === '0363100012185508') {
            return Http::response(['features' => [buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)]]);
        }

        if (str_contains($request->url(), '/collections/pand/items')) {
            return Http::response(['features' => [
                buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10),
                buildingFeature('neighbour-feature', '0363100012185509', 6, 0, 12, 10),
            ]]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response(['features' => [
                residenceFeature('target-feature'),
                residenceFeature('target-feature'),
                residenceFeature('other-feature'),
            ]]);
        }

        return Http::response([], 404);
    });

    $context = app(PdokBuildingContextService::class)->fetch('0363100012185508');

    expect($context?->target->id)->toBe('0363100012185508')
        ->and($context?->target->outline[2])->toBe([6.0, 10.0])
        ->and($context?->nearby)->toHaveCount(1)
        ->and($context?->nearby[0]->id)->toBe('0363100012185509')
        ->and($context?->addressableObjectCount)->toBe(2);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return ($data['bbox-crs'] ?? null) === 'http://www.opengis.net/def/crs/EPSG/0/28992'
            && ($data['crs'] ?? null) === 'http://www.opengis.net/def/crs/EPSG/0/28992';
    });
});

it('does not infer an apartment for a mixed-use building', function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');

    Http::fake(function (Request $request) {
        if (($request->data()['identificatie'] ?? null) === '0363100012185508') {
            return Http::response(['features' => [buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)]]);
        }

        if (str_contains($request->url(), '/collections/pand/items')) {
            return Http::response(featureCollection([
                buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10),
            ]));
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response(featureCollection([
                residenceFeature('target-feature', ['woonfunctie']),
                residenceFeature('target-feature', ['kantoorfunctie']),
            ]));
        }

        return Http::response([], 404);
    });

    $context = app(PdokBuildingContextService::class)->fetch('0363100012185508');

    expect($context)->not->toBeNull()
        ->and($context?->hasMixedUse)->toBeTrue()
        ->and(app(BuildingTypeResolver::class)->resolve($context))->toBeNull();
});

it('keeps building inference incomplete when PDOK reports another page', function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');

    Http::fake(function (Request $request) {
        if (($request->data()['identificatie'] ?? null) === '0363100012185508') {
            return Http::response(['features' => [buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)]]);
        }

        if (str_contains($request->url(), '/collections/pand/items')) {
            return Http::response([
                'numberMatched' => 2,
                'numberReturned' => 1,
                'features' => [buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)],
                'links' => [['rel' => 'next', 'href' => 'https://api.pdok.test/bag/collections/pand/items?offset=1']],
            ]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response(featureCollection([residenceFeature('target-feature')]));
        }

        return Http::response([], 404);
    });

    $context = app(PdokBuildingContextService::class)->fetch('0363100012185508');

    expect($context?->complete)->toBeFalse()
        ->and(app(BuildingTypeResolver::class)->resolve($context))->toBeNull();
});

it('accepts a complete PDOK page when numberMatched is null', function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');

    Http::fake(function (Request $request) {
        if (($request->data()['identificatie'] ?? null) === '0363100012185508') {
            return Http::response(['features' => [buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)]]);
        }

        if (str_contains($request->url(), '/collections/pand/items')) {
            return Http::response([
                'numberMatched' => null,
                'numberReturned' => 1,
                'features' => [buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)],
                'links' => [],
            ]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'numberMatched' => null,
                'numberReturned' => 1,
                'features' => [residenceFeature('target-feature')],
                'links' => [],
            ]);
        }

        return Http::response([], 404);
    });

    $context = app(PdokBuildingContextService::class)->fetch('0363100012185508');

    expect($context?->complete)->toBeTrue()
        ->and(app(BuildingTypeResolver::class)->resolve($context)?->option)->toBe('detached');
});

it('keeps inference incomplete when a residence cannot be associated with a building', function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');

    Http::fake(function (Request $request) {
        if (($request->data()['identificatie'] ?? null) === '0363100012185508') {
            return Http::response(['features' => [buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)]]);
        }

        if (str_contains($request->url(), '/collections/pand/items')) {
            return Http::response(featureCollection([
                buildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10),
            ]));
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response(featureCollection([
                residenceFeature('target-feature'),
                ['properties' => ['pand.href' => null, 'gebruiksdoel' => ['woonfunctie']]],
            ]));
        }

        return Http::response([], 404);
    });

    $context = app(PdokBuildingContextService::class)->fetch('0363100012185508');

    expect($context?->complete)->toBeFalse()
        ->and(app(BuildingTypeResolver::class)->resolve($context))->toBeNull();
});

/** @return array<string, mixed> */
function buildingFeature(string $featureId, string $buildingId, float $minX, float $minY, float $maxX, float $maxY): array
{
    return [
        'id' => $featureId,
        'properties' => ['identificatie' => $buildingId],
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [$minX, $minY],
                [$maxX, $minY],
                [$maxX, $maxY],
                [$minX, $maxY],
                [$minX, $minY],
            ]],
        ],
    ];
}

/** @return array<string, mixed> */
function residenceFeature(string $buildingFeatureId, array $purposes = ['woonfunctie']): array
{
    return [
        'properties' => [
            'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/'.$buildingFeatureId],
            'gebruiksdoel' => $purposes,
        ],
    ];
}

/**
 * @param  list<array<string, mixed>>  $features
 * @return array<string, mixed>
 */
function featureCollection(array $features): array
{
    return [
        'numberMatched' => count($features),
        'numberReturned' => count($features),
        'features' => $features,
        'links' => [],
    ];
}
