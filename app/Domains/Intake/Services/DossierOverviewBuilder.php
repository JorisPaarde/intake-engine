<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\Intake;
use App\Enums\AircoConnectionType;
use App\Enums\DecisionAreaStatus;
use Illuminate\Support\Collection;

final class DossierOverviewBuilder
{
    public function __construct(
        private readonly DecisionReadinessService $decisionReadiness,
    ) {}

    /**
     * @return array{
     *     areas: Collection<int, DossierDecisionArea>,
     *     blockers: Collection<int, DossierDecisionArea>,
     *     quote: DossierDecisionArea|null,
     *     ready_count: int,
     *     filled_count: int,
     *     total_count: int
     * }
     */
    public function build(Intake $intake): array
    {
        $areas = $this->decisionReadiness->recalculate($intake);
        $quote = $areas->firstWhere('key', 'quote');
        $intake->loadMissing([
            'aircoRooms',
            'aircoPlacements',
            'aircoInstallationOptions.connections',
        ]);

        return [
            'areas' => $areas,
            'blockers' => $areas->filter(
                static fn (DossierDecisionArea $area): bool => in_array(
                    $area->status,
                    [DecisionAreaStatus::Blocked, DecisionAreaStatus::Review],
                    true,
                ),
            )->values(),
            'quote' => $quote instanceof DossierDecisionArea ? $quote : null,
            'ready_count' => $areas->where('status', DecisionAreaStatus::Ready)->count(),
            'filled_count' => $areas->filter(
                fn (DossierDecisionArea $area): bool => $this->areaHasContent($intake, $area),
            )->count(),
            'total_count' => $areas->count(),
        ];
    }

    /**
     * Honest “met inhoud” progress: Ready/Review, or Blocked areas that already have
     * concrete dossier content (rooms, maten, plekken, routes). Photos alone do not
     * invent an opstelling — missing opstelling stays visible via next-step copy.
     */
    private function areaHasContent(Intake $intake, DossierDecisionArea $area): bool
    {
        if (in_array($area->status, [DecisionAreaStatus::Ready, DecisionAreaStatus::Review], true)) {
            return true;
        }

        return match ($area->key) {
            'request' => $intake->aircoRooms->isNotEmpty(),
            'capacity' => $intake->aircoRooms->contains(
                static function (AircoRoom $room): bool {
                    $dimensions = is_array($room->dimensions) ? $room->dimensions : [];

                    return $room->use_type !== null
                        || isset($dimensions['length_m'], $dimensions['width_m'], $dimensions['height_m'])
                        || isset($dimensions['length_m'])
                        || isset($dimensions['width_m'])
                        || isset($dimensions['height_m']);
                },
            ),
            'placement' => $intake->aircoPlacements->isNotEmpty()
                || $intake->aircoInstallationOptions->isNotEmpty(),
            'refrigerant' => $this->hasConnectionOfType($intake, AircoConnectionType::Refrigerant),
            'condensate' => $this->hasConnectionOfType($intake, AircoConnectionType::Condensate),
            'power' => $this->hasConnectionOfType($intake, AircoConnectionType::Power),
            'cost_risks' => $intake->aircoInstallationOptions->isNotEmpty(),
            'quote' => false,
            default => false,
        };
    }

    private function hasConnectionOfType(Intake $intake, AircoConnectionType $type): bool
    {
        return $intake->aircoInstallationOptions->contains(
            static fn (AircoInstallationOption $option): bool => $option->connections->contains(
                static fn (AircoConnection $connection): bool => $connection->type === $type,
            ),
        );
    }
}
