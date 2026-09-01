<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\Intake;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use Illuminate\Support\Collection;

/**
 * Resolves the sticky primary CTA and per-area deep links for the installer workspace (BL-054/055).
 */
final class WorkspacePrimaryActionResolver
{
    /** @var list<string> */
    private const AREA_PRIORITY = [
        'request',
        'capacity',
        'placement',
        'refrigerant',
        'condensate',
        'power',
        'cost_risks',
        'quote',
    ];

    /**
     * @param  Collection<int, DossierDecisionArea>  $openAreas
     * @param  Collection<int, mixed>  $proposedCustomerTasks
     * @return array{href: string, label: string, summary: string}
     */
    public function resolve(
        Intake $intake,
        ?DossierDecisionArea $quoteArea,
        bool $canApproveProposal,
        bool $proposalAlreadyApproved,
        Collection $proposedCustomerTasks,
        Collection $openAreas,
    ): array {
        if ($proposalAlreadyApproved) {
            return [
                'href' => '#workspace-outcome',
                'label' => 'Uitkomst vastleggen',
                'summary' => 'Voorstel is goedgekeurd',
            ];
        }

        if ($canApproveProposal) {
            return [
                'href' => '#workspace-complete',
                'label' => 'Voorstel goedkeuren',
                'summary' => $quoteArea?->next_action?->label() ?? 'Offerte voorbereiden',
            ];
        }

        if ($proposedCustomerTasks->isNotEmpty()) {
            return [
                'href' => '#demo-customer-task',
                'label' => 'Klanttaak controleren',
                'summary' => 'Controleer de taak voor de klant',
            ];
        }

        if ($intake->aircoRooms->isEmpty()) {
            return [
                'href' => '#workspace-rooms',
                'label' => 'Ruimte toevoegen',
                'summary' => 'Begin met de gewenste ruimtes',
            ];
        }

        if ($intake->aircoPlacements->isEmpty()) {
            return [
                'href' => '#demo-placements',
                'label' => 'Binnen- of buitenunit toevoegen',
                'summary' => 'Leg eerst vast waar de binnenunit en buitenunit komen',
            ];
        }

        $actionable = $this->firstActionableOpenArea($openAreas);
        if ($actionable !== null) {
            $target = $this->targetForArea($intake, $actionable->key);

            return [
                'href' => $target['href'],
                'label' => $target['label'],
                'summary' => $actionable->blocker
                    ?? $actionable->next_action?->label()
                    ?? $actionable->label,
            ];
        }

        if ($intake->aircoInstallationOptions->isEmpty()) {
            return [
                'href' => '#demo-proposal',
                'label' => 'Kies multi-split of singles',
                'summary' => 'Combineer binnen- en buitenunit tot één multi-split of losse singles',
            ];
        }

        if ($quoteArea?->next_action === DossierNextAction::RequestContribution) {
            return [
                'href' => '#demo-customer-task',
                'label' => 'Klanttaak maken',
                'summary' => $quoteArea->blocker ?? 'Vraag ontbrekend bewijs aan de klant',
            ];
        }

        if ($quoteArea?->next_action === DossierNextAction::PlanSiteVisit) {
            return [
                'href' => '#workspace-outcome',
                'label' => 'Locatiebezoek vastleggen',
                'summary' => $quoteArea->blocker ?? 'Locatiebezoek plannen',
            ];
        }

        return [
            'href' => '#demo-proposal',
            'label' => 'Naar multi-split of singles',
            'summary' => $quoteArea?->next_action?->label() ?? 'Werk de keuze verder uit',
        ];
    }

    /**
     * @return array{href: string, label: string}
     */
    public function targetForArea(Intake $intake, string $areaKey): array
    {
        return match ($areaKey) {
            'request' => [
                'href' => '#workspace-rooms',
                'label' => 'Ruimte toevoegen',
            ],
            'capacity' => $this->capacityTarget($intake),
            'placement' => $intake->aircoPlacements->isEmpty()
                ? ['href' => '#demo-placements', 'label' => 'Binnen- of buitenunit toevoegen']
                : ['href' => '#demo-proposal', 'label' => 'Kies multi-split of singles'],
            'refrigerant' => $this->connectionTarget($intake, AircoConnectionType::Refrigerant, 'Koelroute vastleggen'),
            'condensate' => $this->connectionTarget($intake, AircoConnectionType::Condensate, 'Condensroute vastleggen'),
            'power' => $this->connectionTarget($intake, AircoConnectionType::Power, 'Stroomroute vastleggen'),
            'cost_risks' => [
                'href' => '#demo-proposal',
                'label' => 'Risico’s controleren',
            ],
            'quote' => [
                'href' => '#workspace-complete',
                'label' => 'Voorstel afronden',
            ],
            default => [
                'href' => '#workspace-rooms',
                'label' => 'Verder in de opname',
            ],
        };
    }

    /**
     * @return array{href: string, label: string}
     */
    private function capacityTarget(Intake $intake): array
    {
        $incomplete = $intake->aircoRooms->first(
            static function (AircoRoom $room): bool {
                $dimensions = is_array($room->dimensions) ? $room->dimensions : [];

                return ! is_numeric($dimensions['length_m'] ?? null)
                    || ! is_numeric($dimensions['width_m'] ?? null)
                    || ! is_numeric($dimensions['height_m'] ?? null);
            },
        );

        if ($incomplete instanceof AircoRoom) {
            return [
                'href' => '#room-'.$incomplete->id,
                'label' => 'Maten invullen',
            ];
        }

        return [
            'href' => '#workspace-rooms',
            'label' => 'Maten invullen',
        ];
    }

    /**
     * @param  Collection<int, DossierDecisionArea>  $openAreas
     */
    public function firstActionableOpenArea(Collection $openAreas): ?DossierDecisionArea
    {
        $sorted = $openAreas
            ->filter(static fn (DossierDecisionArea $area): bool => $area->key !== 'quote'
                || in_array($area->status, [DecisionAreaStatus::Blocked, DecisionAreaStatus::Review], true))
            ->sortBy(function (DossierDecisionArea $area): int {
                $index = array_search($area->key, self::AREA_PRIORITY, true);

                return $index === false ? 99 : $index;
            })
            ->values();

        $withoutQuote = $sorted->first(
            static fn (DossierDecisionArea $area): bool => $area->key !== 'quote',
        );

        return $withoutQuote ?? $sorted->first();
    }

    /**
     * @return array{href: string, label: string}
     */
    private function connectionTarget(Intake $intake, AircoConnectionType $type, string $label): array
    {
        $option = $intake->aircoInstallationOptions->first(
            static fn ($option): bool => $option->status === AircoOptionStatus::Selected,
        ) ?? $intake->aircoInstallationOptions->first();

        if ($option === null) {
            return ['href' => '#demo-proposal', 'label' => 'Kies multi-split of singles'];
        }

        $connection = $option->connections
            ->filter(static fn ($connection): bool => $connection->type === $type)
            ->sortBy(static fn ($connection): int => $connection->status === AircoConnectionStatus::Approved ? 1 : 0)
            ->first();

        if ($connection === null) {
            return ['href' => '#demo-proposal', 'label' => $label];
        }

        return [
            'href' => '#connection-'.$connection->id,
            'label' => $label,
        ];
    }
}
