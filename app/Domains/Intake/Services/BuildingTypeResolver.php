<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Data\BuildingContext;
use App\Domains\Intake\Data\BuildingFootprint;
use App\Domains\Intake\Data\BuildingTypeInference;

final class BuildingTypeResolver
{
    private const MAX_BOUNDARY_DISTANCE_METERS = 0.75;

    private const MIN_SHARED_BOUNDARY_METERS = 2.0;

    public function resolve(BuildingContext $context): ?BuildingTypeInference
    {
        if ($context->addressableObjectCount > 1) {
            return new BuildingTypeInference(
                option: 'apartment',
                confidence: 'high',
                reason: 'Meerdere BAG-verblijfsobjecten zijn gekoppeld aan hetzelfde pand.',
            );
        }

        if (! $context->complete || ! $this->hasUsableOutline($context->target)) {
            return null;
        }

        $adjoining = array_values(array_filter(
            $context->nearby,
            fn (BuildingFootprint $footprint): bool => $this->adjoins($context->target, $footprint),
        ));

        if ($context->addressableObjectCount === 1 && $adjoining === []) {
            return new BuildingTypeInference(
                option: 'detached',
                confidence: 'high',
                reason: 'Het BAG-pand bevat één verblijfsobject en sluit niet aan op een ander pand.',
            );
        }

        if ($context->addressableObjectCount === 1 && $this->hasOppositeNeighbours($context->target, $adjoining)) {
            return new BuildingTypeInference(
                option: 'terraced',
                confidence: 'high',
                reason: 'Het BAG-pand bevat één verblijfsobject en sluit aan weerszijden aan op andere panden.',
            );
        }

        if ($context->addressableObjectCount === 1 && count($adjoining) === 1) {
            $continues = $this->neighbourContinuesChain($adjoining[0], $context->nearby);

            return new BuildingTypeInference(
                option: $continues ? 'corner' : 'semi_detached',
                confidence: 'high',
                reason: $continues
                    ? 'Het BAG-pand ligt aan het uiteinde van een keten van aansluitende panden.'
                    : 'Het BAG-pand bevat één verblijfsobject en vormt samen met precies één aansluitend pand een paar.',
            );
        }

        return null;
    }

    /** @param list<BuildingFootprint> $nearby */
    private function neighbourContinuesChain(BuildingFootprint $neighbour, array $nearby): bool
    {
        foreach ($nearby as $candidate) {
            if ($candidate->id !== $neighbour->id && $this->adjoins($neighbour, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<BuildingFootprint> $neighbours */
    private function hasOppositeNeighbours(BuildingFootprint $target, array $neighbours): bool
    {
        if (count($neighbours) < 2) {
            return false;
        }

        [$targetX, $targetY] = $this->centroid($target);

        foreach ($neighbours as $firstIndex => $first) {
            [$firstX, $firstY] = $this->centroid($first);
            $firstVectorX = $firstX - $targetX;
            $firstVectorY = $firstY - $targetY;
            $firstLength = hypot($firstVectorX, $firstVectorY);

            foreach (array_slice($neighbours, $firstIndex + 1) as $second) {
                [$secondX, $secondY] = $this->centroid($second);
                $secondVectorX = $secondX - $targetX;
                $secondVectorY = $secondY - $targetY;
                $secondLength = hypot($secondVectorX, $secondVectorY);

                if ($firstLength > 0.0 && $secondLength > 0.0
                    && (($firstVectorX * $secondVectorX) + ($firstVectorY * $secondVectorY)) / ($firstLength * $secondLength) <= -0.5) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0: float, 1: float} */
    private function centroid(BuildingFootprint $footprint): array
    {
        $points = array_slice($footprint->outline, 0, -1);
        $count = count($points);

        if ($count === 0) {
            return [0.0, 0.0];
        }

        return [
            array_sum(array_column($points, 0)) / $count,
            array_sum(array_column($points, 1)) / $count,
        ];
    }

    private function adjoins(BuildingFootprint $first, BuildingFootprint $second): bool
    {
        if (! $this->hasUsableOutline($second)) {
            return false;
        }

        foreach ($this->segments($first) as [$firstStart, $firstEnd]) {
            foreach ($this->segments($second) as [$secondStart, $secondEnd]) {
                $firstX = $firstEnd[0] - $firstStart[0];
                $firstY = $firstEnd[1] - $firstStart[1];
                $secondX = $secondEnd[0] - $secondStart[0];
                $secondY = $secondEnd[1] - $secondStart[1];
                $firstLength = hypot($firstX, $firstY);
                $secondLength = hypot($secondX, $secondY);

                if ($firstLength < self::MIN_SHARED_BOUNDARY_METERS || $secondLength < self::MIN_SHARED_BOUNDARY_METERS) {
                    continue;
                }

                $parallelError = abs(($firstX * $secondY) - ($firstY * $secondX)) / ($firstLength * $secondLength);

                if ($parallelError > sin(deg2rad(10))) {
                    continue;
                }

                $unitX = $firstX / $firstLength;
                $unitY = $firstY / $firstLength;
                $distanceStart = abs((($secondStart[0] - $firstStart[0]) * $firstY) - (($secondStart[1] - $firstStart[1]) * $firstX)) / $firstLength;
                $distanceEnd = abs((($secondEnd[0] - $firstStart[0]) * $firstY) - (($secondEnd[1] - $firstStart[1]) * $firstX)) / $firstLength;

                if (max($distanceStart, $distanceEnd) > self::MAX_BOUNDARY_DISTANCE_METERS) {
                    continue;
                }

                $projectionStart = (($secondStart[0] - $firstStart[0]) * $unitX) + (($secondStart[1] - $firstStart[1]) * $unitY);
                $projectionEnd = (($secondEnd[0] - $firstStart[0]) * $unitX) + (($secondEnd[1] - $firstStart[1]) * $unitY);
                $overlap = min($firstLength, max($projectionStart, $projectionEnd))
                    - max(0.0, min($projectionStart, $projectionEnd));

                if ($overlap >= self::MIN_SHARED_BOUNDARY_METERS) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasUsableOutline(BuildingFootprint $footprint): bool
    {
        if (count($footprint->outline) < 4) {
            return false;
        }

        $first = $footprint->outline[0];
        $last = $footprint->outline[array_key_last($footprint->outline)];

        if (hypot($last[0] - $first[0], $last[1] - $first[1]) > 0.1) {
            return false;
        }

        $twiceArea = 0.0;

        foreach ($this->segments($footprint) as [$start, $end]) {
            $twiceArea += ($start[0] * $end[1]) - ($end[0] * $start[1]);
        }

        return abs($twiceArea) / 2 >= 4.0;
    }

    /** @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}> */
    private function segments(BuildingFootprint $footprint): array
    {
        $segments = [];

        for ($index = 1, $count = count($footprint->outline); $index < $count; $index++) {
            $segments[] = [$footprint->outline[$index - 1], $footprint->outline[$index]];
        }

        return $segments;
    }
}
