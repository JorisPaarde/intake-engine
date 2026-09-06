<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use Illuminate\Support\Collection;

/**
 * Validates indoor↔outdoor refrigerant coupling cardinality per ADR-0012 / BL-102.
 *
 * Indoor units belong to exactly one desired room. Outdoor units stay shared
 * placement options (no room ownership). Within an installation option each
 * indoor links to one labeled outdoor via a refrigerant connection.
 */
final class AircoUnitCouplingValidator
{
    /**
     * @param  Collection<int, AircoPlacementOption>  $placements
     * @param  Collection<int, AircoConnection>  $connections
     * @return list<string>
     */
    public function problems(
        AircoConfigurationType $configuration,
        Collection $placements,
        Collection $connections,
        bool $requireComplete = true,
    ): array {
        $indoors = $placements
            ->filter(static fn (AircoPlacementOption $p): bool => $p->type === AircoPlacementType::IndoorUnit)
            ->values();
        $outdoors = $placements
            ->filter(static fn (AircoPlacementOption $p): bool => $p->type === AircoPlacementType::OutdoorUnit)
            ->values();

        $problems = [];

        foreach ($indoors as $indoor) {
            if ($indoor->airco_room_id === null) {
                $problems[] = 'Iedere binnenunit hoort bij precies één gewenste ruimte.';
                break;
            }
        }

        foreach ($outdoors as $outdoor) {
            if ($outdoor->airco_room_id !== null) {
                $problems[] = 'Een buitenunit is een gedeelde montageplek en hoort niet bij één ruimte.';
                break;
            }
        }

        $links = $this->refrigerantLinks($placements, $connections);
        $linkProblems = $this->linkProblems($links, $indoors, $outdoors, $requireComplete);
        $problems = [...$problems, ...$linkProblems];

        if ($requireComplete || $links !== []) {
            $problems = [...$problems, ...$this->cardinalityProblems($configuration, $links, $indoors, $outdoors, $requireComplete)];
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return list<string>
     */
    public function optionProblems(AircoInstallationOption $option, bool $requireComplete = true): array
    {
        $option->loadMissing(['placements', 'connections']);

        return $this->problems(
            $option->configuration_type,
            $option->placements,
            $option->connections,
            $requireComplete,
        );
    }

    /**
     * @param  Collection<int, AircoPlacementOption>  $placements
     * @param  Collection<int, AircoConnection>  $connections
     * @return list<array{indoor_id: int, outdoor_id: int, connection_id: int|null}>
     */
    public function refrigerantLinks(Collection $placements, Collection $connections): array
    {
        $byId = $placements->keyBy('id');
        $links = [];

        foreach ($connections as $connection) {
            if ($connection->type !== AircoConnectionType::Refrigerant) {
                continue;
            }

            $from = $connection->from_placement_id !== null
                ? $byId->get($connection->from_placement_id)
                : null;
            $to = $connection->to_placement_id !== null
                ? $byId->get($connection->to_placement_id)
                : null;

            if (! $from instanceof AircoPlacementOption || ! $to instanceof AircoPlacementOption) {
                continue;
            }

            $indoor = null;
            $outdoor = null;

            if ($from->type === AircoPlacementType::IndoorUnit && $to->type === AircoPlacementType::OutdoorUnit) {
                $indoor = $from;
                $outdoor = $to;
            } elseif ($from->type === AircoPlacementType::OutdoorUnit && $to->type === AircoPlacementType::IndoorUnit) {
                $indoor = $to;
                $outdoor = $from;
            } else {
                continue;
            }

            $links[] = [
                'indoor_id' => $indoor->id,
                'outdoor_id' => $outdoor->id,
                'connection_id' => $connection->id,
            ];
        }

        return $links;
    }

    /**
     * @param  list<array{indoor_id: int, outdoor_id: int, connection_id: int|null}>  $links
     * @param  Collection<int, AircoPlacementOption>  $indoors
     * @param  Collection<int, AircoPlacementOption>  $outdoors
     * @return list<string>
     */
    private function linkProblems(
        array $links,
        Collection $indoors,
        Collection $outdoors,
        bool $requireComplete,
    ): array {
        $problems = [];
        $indoorIds = $indoors->pluck('id')->all();
        $outdoorIds = $outdoors->pluck('id')->all();
        $seenIndoors = [];
        $seenOutdoors = [];

        foreach ($links as $link) {
            if (! in_array($link['indoor_id'], $indoorIds, true)
                || ! in_array($link['outdoor_id'], $outdoorIds, true)) {
                $problems[] = 'Een koelleiding koppelt units die niet bij deze keuze horen.';

                continue;
            }

            if (isset($seenIndoors[$link['indoor_id']])) {
                $problems[] = 'Een binnenunit mag maar één koelleiding naar een buitenunit hebben.';
            }
            $seenIndoors[$link['indoor_id']] = true;

            if (isset($seenOutdoors[$link['outdoor_id']])) {
                // Shared outdoor is only illegal for multiple single-splits; checked in cardinality.
            }
            $seenOutdoors[$link['outdoor_id']] = ($seenOutdoors[$link['outdoor_id']] ?? 0) + 1;
        }

        if ($requireComplete) {
            foreach ($indoorIds as $indoorId) {
                if (! isset($seenIndoors[$indoorId])) {
                    $problems[] = 'Niet iedere binnenunit heeft een koelleiding naar een buitenunit.';
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * @param  list<array{indoor_id: int, outdoor_id: int, connection_id: int|null}>  $links
     * @param  Collection<int, AircoPlacementOption>  $indoors
     * @param  Collection<int, AircoPlacementOption>  $outdoors
     * @return list<string>
     */
    private function cardinalityProblems(
        AircoConfigurationType $configuration,
        array $links,
        Collection $indoors,
        Collection $outdoors,
        bool $requireComplete,
    ): array {
        $indoorCount = $indoors->count();
        $outdoorCount = $outdoors->count();
        $uniqueOutdoorsInLinks = collect($links)->pluck('outdoor_id')->unique()->values();
        $outdoorUseCounts = collect($links)->countBy('outdoor_id');

        return match ($configuration) {
            AircoConfigurationType::SingleSplit => $this->singleSplitProblems(
                $indoorCount,
                $outdoorCount,
                $links,
                $requireComplete,
            ),
            AircoConfigurationType::MultiSplit => $this->multiSplitProblems(
                $indoorCount,
                $outdoorCount,
                $links,
                $uniqueOutdoorsInLinks,
                $requireComplete,
            ),
            AircoConfigurationType::MultipleSingleSplits => $this->multipleSinglesProblems(
                $indoorCount,
                $outdoorCount,
                $links,
                $outdoorUseCounts,
                $requireComplete,
            ),
        };
    }

    /**
     * @param  list<array{indoor_id: int, outdoor_id: int, connection_id: int|null}>  $links
     * @return list<string>
     */
    private function singleSplitProblems(
        int $indoorCount,
        int $outdoorCount,
        array $links,
        bool $requireComplete,
    ): array {
        $problems = [];

        if ($requireComplete && ($indoorCount !== 1 || $outdoorCount !== 1)) {
            $problems[] = 'Single-split vraagt precies één binnenunit en één buitenunit.';
        }

        if (count($links) > 1) {
            $problems[] = 'Single-split mag maar één koelleiding hebben.';
        }

        if ($requireComplete && count($links) !== 1 && $indoorCount === 1 && $outdoorCount === 1) {
            $problems[] = 'Koppel de binnenunit met precies één koelleiding aan de buitenunit.';
        }

        return $problems;
    }

    /**
     * @param  list<array{indoor_id: int, outdoor_id: int, connection_id: int|null}>  $links
     * @param  Collection<int, int>  $uniqueOutdoorsInLinks
     * @return list<string>
     */
    private function multiSplitProblems(
        int $indoorCount,
        int $outdoorCount,
        array $links,
        Collection $uniqueOutdoorsInLinks,
        bool $requireComplete,
    ): array {
        $problems = [];

        if ($requireComplete && ($indoorCount < 2 || $outdoorCount !== 1)) {
            $problems[] = 'Multi-split vraagt minstens twee binnenunits en precies één buitenunit.';
        }

        if ($outdoorCount > 1) {
            $problems[] = 'Multi-split mag maar één buitenunit hebben.';
        }

        if ($uniqueOutdoorsInLinks->count() > 1) {
            $problems[] = 'Bij multi-split gaan alle binnenunits naar dezelfde buitenunit.';
        }

        if ($requireComplete && $indoorCount >= 2 && $outdoorCount === 1 && count($links) !== $indoorCount) {
            $problems[] = 'Koppel iedere binnenunit met een koelleiding aan dezelfde buitenunit.';
        }

        return $problems;
    }

    /**
     * @param  list<array{indoor_id: int, outdoor_id: int, connection_id: int|null}>  $links
     * @param  Collection<int|string, int>  $outdoorUseCounts
     * @return list<string>
     */
    private function multipleSinglesProblems(
        int $indoorCount,
        int $outdoorCount,
        array $links,
        Collection $outdoorUseCounts,
        bool $requireComplete,
    ): array {
        $problems = [];

        if ($requireComplete && ($indoorCount < 2 || $outdoorCount !== $indoorCount)) {
            $problems[] = 'Meerdere single-splits vragen evenveel binnen- als buitenunits (minstens twee).';
        }

        if ($outdoorUseCounts->contains(static fn (int $count): bool => $count > 1)) {
            $problems[] = 'Bij meerdere single-splits deelt geen buitenunit twee binnenunits.';
        }

        if ($requireComplete
            && $indoorCount >= 2
            && $outdoorCount === $indoorCount
            && (count($links) !== $indoorCount || $outdoorUseCounts->count() !== $indoorCount)) {
            $problems[] = 'Koppel iedere binnenunit één-op-één aan een eigen buitenunit.';
        }

        return $problems;
    }
}
