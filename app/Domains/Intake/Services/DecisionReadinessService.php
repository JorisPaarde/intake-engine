<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\Intake;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use Illuminate\Support\Collection;

final class DecisionReadinessService
{
    /** @var array<string, string> */
    private const LABELS = [
        'request' => 'Aanvraag en gewenste ruimtes',
        'capacity' => 'Capaciteit indiceren',
        'placement' => 'Plaatsing en configuratie',
        'refrigerant' => 'Koelleiding(en)',
        'condensate' => 'Condensafvoer',
        'power' => 'Stroomtoevoer',
        'cost_risks' => 'Kostenbepalende risico’s',
        'quote' => 'Offertebesluit',
    ];

    /** @return Collection<int, DossierDecisionArea> */
    public function recalculate(Intake $intake): Collection
    {
        $intake->loadMissing([
            'aircoRooms',
            'aircoInstallationOptions.placements',
            'aircoInstallationOptions.connections',
            'review',
        ]);

        $selected = $intake->aircoInstallationOptions->first(
            static fn (AircoInstallationOption $option): bool => $option->status === AircoOptionStatus::Selected,
        );
        $candidate = $selected ?? $intake->aircoInstallationOptions->first();
        $areas = [];
        $areas['request'] = $this->requestArea($intake);
        $areas['capacity'] = $this->capacityArea($intake);
        $areas['placement'] = $this->placementArea($candidate, $selected);

        foreach (AircoConnectionType::cases() as $type) {
            $areas[$type->value] = $this->connectionArea($candidate, $type);
        }

        $areas['cost_risks'] = $this->costArea($candidate);
        $areas['quote'] = $this->quoteArea($areas);

        foreach ($areas as $key => $area) {
            DossierDecisionArea::query()->updateOrCreate(
                [
                    'intake_id' => $intake->id,
                    'key' => $key,
                ],
                [
                    'company_id' => $intake->company_id,
                    'label' => self::LABELS[$key],
                    'status' => $area['status'],
                    'next_action' => $area['next_action'] ?? null,
                    'blocker' => $area['blocker'] ?? null,
                    'blocking_subject_id' => $area['blocking_subject_id'] ?? null,
                    'cost_risks' => $area['cost_risks'] ?? null,
                    'evidence_summary' => $area['evidence_summary'] ?? null,
                    'assessed_at' => now(),
                ],
            );
        }

        return $intake->decisionAreas()->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function requestArea(Intake $intake): array
    {
        if ($intake->aircoRooms->isEmpty()) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Leg minimaal één gewenste ruimte vast.',
            ];
        }

        return [
            'status' => DecisionAreaStatus::Ready,
            'evidence_summary' => ['rooms' => $intake->aircoRooms->count()],
        ];
    }

    /** @return array<string, mixed> */
    private function capacityArea(Intake $intake): array
    {
        if ($intake->aircoRooms->isEmpty()) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'blocker' => 'Gewenste ruimtes ontbreken.',
            ];
        }

        $complete = $intake->aircoRooms->every(static function (AircoRoom $room): bool {
            $dimensions = $room->dimensions ?? [];

            return $room->use_type !== null
                && isset($dimensions['length_m'], $dimensions['width_m'], $dimensions['height_m']);
        });

        return [
            'status' => $complete ? DecisionAreaStatus::Ready : DecisionAreaStatus::Review,
            'blocker' => $complete ? null : 'Controleer ontbrekende ruimtematen vóór definitieve capaciteitskeuze.',
            'evidence_summary' => [
                'rooms' => $intake->aircoRooms->count(),
                'complete_rooms' => $intake->aircoRooms->filter(static function (AircoRoom $room): bool {
                    $dimensions = $room->dimensions ?? [];

                    return isset($dimensions['length_m'], $dimensions['width_m'], $dimensions['height_m']);
                })->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placementArea(
        ?AircoInstallationOption $candidate,
        ?AircoInstallationOption $selected,
    ): array {
        if ($candidate === null) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Maak eerst een installatieoptie met binnen- en buitenposities.',
            ];
        }

        return [
            'status' => $selected === null ? DecisionAreaStatus::Review : DecisionAreaStatus::Ready,
            'blocker' => $selected === null ? 'Kies of corrigeer één installatieoptie.' : null,
            'evidence_summary' => [
                'option_id' => $candidate->id,
                'configuration' => $candidate->configuration_type->value,
                'placements' => $candidate->placements->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function connectionArea(
        ?AircoInstallationOption $option,
        AircoConnectionType $type,
    ): array {
        if ($option === null) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'blocker' => 'Er is nog geen installatieoptie om deze route aan te koppelen.',
            ];
        }

        $connections = $option->connections->filter(
            static fn (AircoConnection $connection): bool => $connection->type === $type,
        );

        if ($connections->isEmpty()) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Leg de '.$type->label().' voor deze installatieoptie vast.',
            ];
        }

        if (in_array($type, [AircoConnectionType::Refrigerant, AircoConnectionType::Condensate], true)) {
            $uncoveredIndoorPlacements = $option->placements
                ->filter(
                    static fn (AircoPlacementOption $placement): bool => $placement->type === AircoPlacementType::IndoorUnit,
                )
                ->reject(static fn (AircoPlacementOption $placement): bool => $connections->contains(
                    static fn (AircoConnection $connection): bool => in_array(
                        $placement->id,
                        [$connection->from_placement_id, $connection->to_placement_id],
                        true,
                    ),
                ));

            if ($uncoveredIndoorPlacements->isNotEmpty()) {
                return [
                    'status' => DecisionAreaStatus::Blocked,
                    'next_action' => DossierNextAction::RequestContribution,
                    'blocker' => 'Niet iedere binnenpositie heeft een eigen '.$type->label().'.',
                    'evidence_summary' => [
                        'connections' => $connections->count(),
                        'uncovered_indoor_placement_ids' => $uncoveredIndoorPlacements->pluck('id')->all(),
                    ],
                ];
            }
        }

        if ($connections->contains(
            static fn (AircoConnection $connection): bool => $connection->status === AircoConnectionStatus::NotRemotelyResolvable,
        )) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::PlanSiteVisit,
                'blocker' => 'Minimaal één route is niet verantwoord op afstand vast te stellen.',
            ];
        }

        if ($connections->contains(
            static fn (AircoConnection $connection): bool => in_array(
                $connection->status,
                [AircoConnectionStatus::Unknown, AircoConnectionStatus::NeedsEvidence],
                true,
            ),
        )) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Minimaal één route mist beslissend bewijs.',
            ];
        }

        $allApproved = $connections->every(
            static fn (AircoConnection $connection): bool => $connection->status === AircoConnectionStatus::Approved,
        );

        return [
            'status' => $allApproved ? DecisionAreaStatus::Ready : DecisionAreaStatus::Review,
            'blocker' => $allApproved ? null : 'De route is aannemelijk en wacht op integrale installateurscontrole.',
            'evidence_summary' => [
                'connections' => $connections->count(),
                'approved' => $connections->where('status', AircoConnectionStatus::Approved)->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function costArea(?AircoInstallationOption $option): array
    {
        if ($option === null) {
            return [
                'status' => DecisionAreaStatus::Unknown,
                'blocker' => 'Kostenrisico’s volgen na een installatieoptie.',
            ];
        }

        $risks = $option->connections
            ->flatMap(static fn (AircoConnection $connection): array => [
                ...($connection->obstacles ?? []),
                ...($connection->uncertainties ?? []),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'status' => $risks === [] ? DecisionAreaStatus::Ready : DecisionAreaStatus::Review,
            'cost_risks' => $risks,
            'blocker' => $risks === [] ? null : 'Verwerk de gemarkeerde risico’s in offerte of voorbehoud.',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $areas
     * @return array<string, mixed>
     */
    private function quoteArea(array $areas): array
    {
        $technicalKeys = ['request', 'capacity', 'placement', 'refrigerant', 'condensate', 'power', 'cost_risks'];
        $technical = collect($technicalKeys)->map(static fn (string $key): array => $areas[$key]);
        $siteVisit = $technical->contains(
            static fn (array $area): bool => ($area['next_action'] ?? null) === DossierNextAction::PlanSiteVisit,
        );

        if ($siteVisit) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::PlanSiteVisit,
                'blocker' => 'Een beslissend onderdeel is niet op afstand vast te stellen.',
            ];
        }

        if ($technical->contains(
            static fn (array $area): bool => $area['status'] === DecisionAreaStatus::Blocked,
        )) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Los eerst de gemarkeerde beslissende onzekerheden op.',
            ];
        }

        if ($technical->contains(
            static fn (array $area): bool => $area['status'] === DecisionAreaStatus::Unknown,
        )) {
            return [
                'status' => DecisionAreaStatus::Review,
                'next_action' => DossierNextAction::SendEstimate,
                'blocker' => 'Een prijsindicatie is mogelijk; technische controle blijft nodig.',
            ];
        }

        if ($technical->contains(
            static fn (array $area): bool => $area['status'] === DecisionAreaStatus::Review,
        )) {
            return [
                'status' => DecisionAreaStatus::Review,
                'next_action' => DossierNextAction::PrepareQuote,
                'blocker' => 'Controleer het installatievoorstel integraal en verwerk gemarkeerde kostenrisico’s.',
            ];
        }

        return [
            'status' => DecisionAreaStatus::Ready,
            'next_action' => DossierNextAction::PrepareQuote,
            'blocker' => null,
        ];
    }
}
