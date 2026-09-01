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
        'capacity' => 'Benodigd vermogen',
        'placement' => 'Binnen- en buitenunit',
        'refrigerant' => 'Koelleiding(en)',
        'condensate' => 'Condensafvoer',
        'power' => 'Stroomtoevoer',
        'cost_risks' => 'Risico’s voor de prijs',
        'quote' => 'Besluit over de offerte',
    ];

    public static function areaLabel(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    /**
     * Plain-Dutch phrase for AI confidence (BL-071). Never expose raw floats or keys.
     */
    public static function confidencePhrase(mixed $confidence): string
    {
        if (! is_numeric($confidence)) {
            return 'zekerheid onbekend — controleer dit even';
        }

        $value = (float) $confidence;
        // Accept both 0–1 and 0–100 scales.
        if ($value > 1.0) {
            $value = $value / 100;
        }

        return match (true) {
            $value < 0.5 => 'nog onzeker — vraag een betere foto of controleer ter plekke',
            $value < 0.8 => 'redelijk zeker, maar controleer dit even',
            default => 'lijkt te kloppen',
        };
    }

    /** @return Collection<int, DossierDecisionArea> */
    public function recalculate(Intake $intake): Collection
    {
        $intake->loadMissing([
            'aircoRooms',
            'aircoPlacements',
            'aircoInstallationOptions.placements',
            'aircoInstallationOptions.connections',
            'uploads',
            'answers',
            'review',
        ]);

        $selected = $intake->aircoInstallationOptions->first(
            static fn (AircoInstallationOption $option): bool => $option->status === AircoOptionStatus::Selected,
        );
        $candidate = $selected ?? $intake->aircoInstallationOptions->first();
        $areas = [];
        $areas['request'] = $this->requestArea($intake);
        $areas['capacity'] = $this->capacityArea($intake);
        $areas['placement'] = $this->placementArea($intake, $candidate, $selected);

        foreach (AircoConnectionType::cases() as $type) {
            $areas[$type->value] = $this->connectionArea($intake, $candidate, $type);
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
                'blocker' => 'Voeg minstens één gewenste ruimte toe.',
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
                'blocker' => 'Er is nog geen gewenste ruimte.',
            ];
        }

        $complete = $intake->aircoRooms->every(static function (AircoRoom $room): bool {
            $dimensions = $room->dimensions ?? [];

            return $room->use_type !== null
                && isset($dimensions['length_m'], $dimensions['width_m'], $dimensions['height_m']);
        });

        return [
            'status' => $complete ? DecisionAreaStatus::Ready : DecisionAreaStatus::Review,
            'blocker' => $complete ? null : 'Vul ontbrekende maten van de ruimte in voordat je het vermogen kiest.',
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
        Intake $intake,
        ?AircoInstallationOption $candidate,
        ?AircoInstallationOption $selected,
    ): array {
        $hasAroundHousePhoto = $this->hasAroundHousePhoto($intake);

        if (! $hasAroundHousePhoto) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Voeg foto’s rondom het huis toe (gevel, tuin of montageplek). Een luchtfoto volstaat niet.',
            ];
        }

        if ($candidate === null) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Kies eerst multi-split of singles met binnenunit en buitenunit.',
            ];
        }

        return [
            'status' => $selected === null ? DecisionAreaStatus::Review : DecisionAreaStatus::Ready,
            'blocker' => $selected === null ? 'Kies multi-split of singles, of pas die keuze aan.' : null,
            'evidence_summary' => [
                'option_id' => $candidate->id,
                'configuration' => $candidate->configuration_type->value,
                'placements' => $candidate->placements->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function connectionArea(
        Intake $intake,
        ?AircoInstallationOption $option,
        AircoConnectionType $type,
    ): array {
        if ($type === AircoConnectionType::Power && ! $this->hasFuseboxPhoto($intake)) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Voeg een duidelijke meterkastfoto toe. Daaruit volgt 1- of 3-fase.',
            ];
        }

        if ($option === null) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'blocker' => 'Er is nog geen keuze om deze route aan te koppelen.',
            ];
        }

        $connections = $option->connections->filter(
            static fn (AircoConnection $connection): bool => $connection->type === $type,
        );

        if ($connections->isEmpty()) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Leg de '.$type->label().' voor deze keuze vast.',
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
                    'blocker' => 'Niet elke binnenunit heeft een eigen '.$type->label().'.',
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
                'blocker' => 'Minstens één route is alleen te zien op locatie.',
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
                'blocker' => 'Minstens één route mist belangrijk bewijs.',
            ];
        }

        $allApproved = $connections->every(
            static fn (AircoConnection $connection): bool => $connection->status === AircoConnectionStatus::Approved,
        );

        return [
            'status' => $allApproved ? DecisionAreaStatus::Ready : DecisionAreaStatus::Review,
            'blocker' => $allApproved ? null : 'De route lijkt te kloppen. Controleer hem nog als geheel.',
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
                'blocker' => 'Prijsrisico’s volgen nadat er een keuze is.',
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
            'blocker' => $risks === [] ? null : 'Neem de gemarkeerde risico’s mee in de offerte of in een voorbehoud.',
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
                'blocker' => 'Een belangrijk onderdeel is alleen te zien op locatie.',
            ];
        }

        if ($technical->contains(
            static fn (array $area): bool => $area['status'] === DecisionAreaStatus::Blocked,
        )) {
            return [
                'status' => DecisionAreaStatus::Blocked,
                'next_action' => DossierNextAction::RequestContribution,
                'blocker' => 'Los eerst de gemarkeerde open punten op.',
            ];
        }

        if ($technical->contains(
            static fn (array $area): bool => $area['status'] === DecisionAreaStatus::Unknown,
        )) {
            return [
                'status' => DecisionAreaStatus::Review,
                'next_action' => DossierNextAction::SendEstimate,
                'blocker' => 'Een prijsindicatie kan. Controleer de techniek nog wel.',
            ];
        }

        if ($technical->contains(
            static fn (array $area): bool => $area['status'] === DecisionAreaStatus::Review,
        )) {
            return [
                'status' => DecisionAreaStatus::Review,
                'next_action' => DossierNextAction::PrepareQuote,
                'blocker' => 'Controleer het voorstel als geheel. Neem gemarkeerde prijsrisico’s mee.',
            ];
        }

        return [
            'status' => DecisionAreaStatus::Ready,
            'next_action' => DossierNextAction::PrepareQuote,
            'blocker' => null,
        ];
    }

    private function hasFuseboxPhoto(Intake $intake): bool
    {
        if ($intake->uploads->contains(
            static fn ($upload): bool => in_array(
                $upload->question_key,
                ['fusebox_photo', 'fusebox_photo_extra'],
                true,
            ),
        )) {
            return true;
        }

        foreach ($intake->aircoPlacements as $placement) {
            if ($placement->type === AircoPlacementType::PowerSource
                && $this->subjectHasUploadEvidence($intake, (int) $placement->dossier_subject_id)) {
                return true;
            }
        }

        foreach ($intake->aircoInstallationOptions as $option) {
            foreach ($option->connections as $connection) {
                if ($connection->type === AircoConnectionType::Power
                    && $this->subjectHasUploadEvidence($intake, (int) $connection->dossier_subject_id)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasAroundHousePhoto(Intake $intake): bool
    {
        if ($intake->uploads->contains(
            static fn ($upload): bool => in_array(
                $upload->question_key,
                ['around_house_photos', 'facade_overview_photo', 'outdoor_location_photos'],
                true,
            ),
        )) {
            return true;
        }

        foreach ($intake->aircoPlacements as $placement) {
            if ($placement->type === AircoPlacementType::OutdoorUnit
                && $this->subjectHasUploadEvidence($intake, (int) $placement->dossier_subject_id)) {
                return true;
            }
        }

        return false;
    }

    private function subjectHasUploadEvidence(Intake $intake, int $subjectId): bool
    {
        $instanceKey = 'subject-'.$subjectId;

        return $intake->uploads->contains(
            static fn ($upload): bool => $upload->question_key === 'installer_evidence'
                && $upload->section_instance_key === $instanceKey,
        );
    }
}
