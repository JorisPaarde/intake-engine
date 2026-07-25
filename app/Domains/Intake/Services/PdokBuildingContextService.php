<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Data\BuildingContext;
use App\Domains\Intake\Data\BuildingFootprint;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class PdokBuildingContextService
{
    private const SOURCE = 'PDOK / BAG pandgeometrie';

    private const RD_CRS = 'http://www.opengis.net/def/crs/EPSG/0/28992';

    private const LIMIT = 100;

    private const NEARBY_MARGIN_METERS = 25.0;

    private const RESIDENCE_MARGIN_METERS = 1.0;

    public static function sourceName(): string
    {
        return self::SOURCE;
    }

    public function buildingUrl(string $bagBuildingId): string
    {
        return rtrim((string) config('services.pdok.bag_base_url'), '/')
            .'/collections/pand/items?f=html&identificatie='.$bagBuildingId.'&limit=1';
    }

    public function fetch(string $bagBuildingId): ?BuildingContext
    {
        if (! (bool) config('services.pdok.enabled', true)
            || preg_match('/^\d{16}$/', $bagBuildingId) !== 1) {
            return null;
        }

        $targetFeature = $this->targetFeature($bagBuildingId);
        $target = $targetFeature === null ? null : $this->footprint($targetFeature);
        $featureId = $targetFeature['id'] ?? null;

        if ($target === null || ! is_string($featureId) || $featureId === '') {
            return null;
        }

        $bounds = $this->bounds($target->outline);
        $nearbyFeatures = $this->features('pand', $this->expandedBbox($bounds, self::NEARBY_MARGIN_METERS));
        $residenceFeatures = $this->features('verblijfsobject', $this->expandedBbox($bounds, self::RESIDENCE_MARGIN_METERS));
        $targetHref = rtrim((string) config('services.pdok.bag_base_url'), '/')
            .'/collections/pand/items/'.$featureId;
        $nearby = [];

        foreach ($nearbyFeatures as $feature) {
            $footprint = $this->footprint($feature);

            if ($footprint !== null && $footprint->id !== $target->id) {
                $nearby[] = $footprint;
            }
        }

        return new BuildingContext(
            target: $target,
            nearby: $nearby,
            addressableObjectCount: $this->countResidencesForBuilding($residenceFeatures, $targetHref),
            complete: count($nearbyFeatures) < self::LIMIT,
        );
    }

    /** @return array<string, mixed>|null */
    private function targetFeature(string $bagBuildingId): ?array
    {
        $features = $this->request()->get('/collections/pand/items', [
            'f' => 'json',
            'identificatie' => $bagBuildingId,
            'limit' => 1,
            'crs' => self::RD_CRS,
        ])->throw()->json('features', []);

        if (! is_array($features) || count($features) !== 1 || ! is_array($features[0])) {
            return null;
        }

        $identification = $features[0]['properties']['identificatie'] ?? null;

        return $identification === $bagBuildingId ? $features[0] : null;
    }

    /** @return list<array<string, mixed>> */
    private function features(string $collection, string $bbox): array
    {
        $features = $this->request()->get('/collections/'.$collection.'/items', [
            'f' => 'json',
            'limit' => self::LIMIT,
            'bbox' => $bbox,
            'bbox-crs' => self::RD_CRS,
            'crs' => self::RD_CRS,
        ])->throw()->json('features', []);

        if (! is_array($features)) {
            return [];
        }

        return array_values(array_filter($features, 'is_array'));
    }

    /** @param array<string, mixed> $feature */
    private function footprint(array $feature): ?BuildingFootprint
    {
        $buildingId = $feature['properties']['identificatie'] ?? null;
        $geometry = $feature['geometry'] ?? null;

        if (! is_string($buildingId) || preg_match('/^\d{16}$/', $buildingId) !== 1 || ! is_array($geometry)) {
            return null;
        }

        $rings = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates'][0] ?? null],
            'MultiPolygon' => array_map(
                static fn (mixed $polygon): mixed => is_array($polygon) ? ($polygon[0] ?? null) : null,
                is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [],
            ),
            default => [],
        };
        $outlines = array_values(array_filter(array_map($this->normalizeOutline(...), $rings)));

        if ($outlines === []) {
            return null;
        }

        usort($outlines, fn (array $first, array $second): int => $this->area($second) <=> $this->area($first));

        return new BuildingFootprint($buildingId, $outlines[0]);
    }

    /** @return list<array{0: float, 1: float}> */
    private function normalizeOutline(mixed $ring): array
    {
        if (! is_array($ring)) {
            return [];
        }

        $outline = [];

        foreach ($ring as $point) {
            if (! is_array($point) || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)) {
                return [];
            }

            $outline[] = [(float) $point[0], (float) $point[1]];
        }

        return $outline;
    }

    /** @param list<array{0: float, 1: float}> $outline */
    private function area(array $outline): float
    {
        $twiceArea = 0.0;

        for ($index = 1, $count = count($outline); $index < $count; $index++) {
            $twiceArea += ($outline[$index - 1][0] * $outline[$index][1])
                - ($outline[$index][0] * $outline[$index - 1][1]);
        }

        return abs($twiceArea) / 2;
    }

    /**
     * @param  list<array<string, mixed>>  $features
     */
    private function countResidencesForBuilding(array $features, string $targetHref): int
    {
        $count = 0;

        foreach ($features as $feature) {
            $hrefs = $feature['properties']['pand.href'] ?? [];
            $hrefs = is_string($hrefs) ? [$hrefs] : $hrefs;

            if (is_array($hrefs) && in_array($targetHref, $hrefs, true)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<array{0: float, 1: float}> $outline
     * @return array{min_x: float, min_y: float, max_x: float, max_y: float}
     */
    private function bounds(array $outline): array
    {
        $x = array_column($outline, 0);
        $y = array_column($outline, 1);

        return ['min_x' => min($x), 'min_y' => min($y), 'max_x' => max($x), 'max_y' => max($y)];
    }

    /** @param array{min_x: float, min_y: float, max_x: float, max_y: float} $bounds */
    private function expandedBbox(array $bounds, float $margin): string
    {
        return implode(',', [
            $bounds['min_x'] - $margin,
            $bounds['min_y'] - $margin,
            $bounds['max_x'] + $margin,
            $bounds['max_y'] + $margin,
        ]);
    }

    private function request(): PendingRequest
    {
        $timeout = max(1, (int) config('services.pdok.timeout_seconds', 5));

        return Http::acceptJson()
            ->baseUrl(rtrim((string) config('services.pdok.bag_base_url'), '/'))
            ->connectTimeout($timeout)
            ->timeout($timeout);
    }
}
