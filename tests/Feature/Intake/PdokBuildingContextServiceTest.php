<?php

declare(strict_types=1);

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
function residenceFeature(string $buildingFeatureId): array
{
    return [
        'properties' => [
            'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/'.$buildingFeatureId],
        ],
    ];
}
