<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\FollowUpItemType;

/**
 * Normalizes enum-like fields in dossier-synthesis JSON before validation.
 */
final class DossierSynthesisOutputNormalizer
{
    public function __construct(
        private readonly AiEnumNormalizer $enums,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function normalize(array $output): array
    {
        if (isset($output['placement_proposals']) && is_array($output['placement_proposals'])) {
            $output['placement_proposals'] = array_map(
                fn (mixed $row): mixed => is_array($row) ? $this->normalizePlacement($row) : $row,
                $output['placement_proposals'],
            );
        }

        if (isset($output['option_proposals']) && is_array($output['option_proposals'])) {
            $output['option_proposals'] = array_map(
                fn (mixed $row): mixed => is_array($row) ? $this->normalizeOption($row) : $row,
                $output['option_proposals'],
            );
        }

        if (isset($output['exceptions']) && is_array($output['exceptions'])) {
            $output['exceptions'] = array_map(
                fn (mixed $row): mixed => is_array($row) ? $this->normalizeException($row) : $row,
                $output['exceptions'],
            );
        }

        if (isset($output['customer_tasks']) && is_array($output['customer_tasks'])) {
            $output['customer_tasks'] = array_map(
                fn (mixed $row): mixed => is_array($row) ? $this->normalizeCustomerTask($row) : $row,
                $output['customer_tasks'],
            );
        }

        return $output;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizePlacement(array $row): array
    {
        if (array_key_exists('type', $row)) {
            $row['type'] = $this->enums->normalize(
                $row['type'],
                array_column(AircoPlacementType::cases(), 'value'),
                [
                    'indoor' => AircoPlacementType::IndoorUnit->value,
                    'indoorunit' => AircoPlacementType::IndoorUnit->value,
                    'binnenunit' => AircoPlacementType::IndoorUnit->value,
                    'outdoor' => AircoPlacementType::OutdoorUnit->value,
                    'outdoorunit' => AircoPlacementType::OutdoorUnit->value,
                    'buitenunit' => AircoPlacementType::OutdoorUnit->value,
                    'power' => AircoPlacementType::PowerSource->value,
                    'powersource' => AircoPlacementType::PowerSource->value,
                    'meterkast' => AircoPlacementType::PowerSource->value,
                    'drain' => AircoPlacementType::DrainPoint->value,
                    'drainpoint' => AircoPlacementType::DrainPoint->value,
                    'afvoer' => AircoPlacementType::DrainPoint->value,
                ],
            );
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeOption(array $row): array
    {
        if (array_key_exists('configuration_type', $row)) {
            $row['configuration_type'] = $this->enums->normalize(
                $row['configuration_type'],
                array_column(AircoConfigurationType::cases(), 'value'),
                [
                    'single' => AircoConfigurationType::SingleSplit->value,
                    'singlesplit' => AircoConfigurationType::SingleSplit->value,
                    'single-split' => AircoConfigurationType::SingleSplit->value,
                    'multi' => AircoConfigurationType::MultiSplit->value,
                    'multisplit' => AircoConfigurationType::MultiSplit->value,
                    'multi-split' => AircoConfigurationType::MultiSplit->value,
                    'multiple_singles' => AircoConfigurationType::MultipleSingleSplits->value,
                    'multiple_single' => AircoConfigurationType::MultipleSingleSplits->value,
                    'singles' => AircoConfigurationType::MultipleSingleSplits->value,
                ],
            );
        }

        if (array_key_exists('cost_impact', $row)) {
            $row['cost_impact'] = $this->normalizeCostImpact($row['cost_impact']);
        }

        if (isset($row['connections']) && is_array($row['connections'])) {
            $row['connections'] = array_map(
                fn (mixed $connection): mixed => is_array($connection)
                    ? $this->normalizeConnection($connection)
                    : $connection,
                $row['connections'],
            );
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeConnection(array $row): array
    {
        if (array_key_exists('type', $row)) {
            $row['type'] = $this->enums->normalize(
                $row['type'],
                array_column(AircoConnectionType::cases(), 'value'),
                [
                    'koel' => AircoConnectionType::Refrigerant->value,
                    'koelleiding' => AircoConnectionType::Refrigerant->value,
                    'cooling' => AircoConnectionType::Refrigerant->value,
                    'condens' => AircoConnectionType::Condensate->value,
                    'condensafvoer' => AircoConnectionType::Condensate->value,
                    'drain' => AircoConnectionType::Condensate->value,
                    'stroom' => AircoConnectionType::Power->value,
                    'electric' => AircoConnectionType::Power->value,
                    'electrical' => AircoConnectionType::Power->value,
                ],
            );
        }

        if (array_key_exists('status', $row)) {
            $allowed = [
                AircoConnectionStatus::Proposed->value,
                AircoConnectionStatus::NeedsEvidence->value,
                AircoConnectionStatus::NotRemotelyResolvable->value,
            ];
            $row['status'] = $this->enums->normalize(
                $row['status'],
                $allowed,
                [
                    'voorstel' => AircoConnectionStatus::Proposed->value,
                    'voorgesteld' => AircoConnectionStatus::Proposed->value,
                    'plausible' => AircoConnectionStatus::Proposed->value,
                    'unknown' => AircoConnectionStatus::NeedsEvidence->value,
                    'onbekend' => AircoConnectionStatus::NeedsEvidence->value,
                    'needs evidence' => AircoConnectionStatus::NeedsEvidence->value,
                    'evidence_needed' => AircoConnectionStatus::NeedsEvidence->value,
                    'bewijs_nodig' => AircoConnectionStatus::NeedsEvidence->value,
                    'aanvulling_nodig' => AircoConnectionStatus::NeedsEvidence->value,
                    'not remotely resolvable' => AircoConnectionStatus::NotRemotelyResolvable->value,
                    'on_site' => AircoConnectionStatus::NotRemotelyResolvable->value,
                    'locatiebezoek' => AircoConnectionStatus::NotRemotelyResolvable->value,
                    'site_visit' => AircoConnectionStatus::NotRemotelyResolvable->value,
                ],
            );
            // Never coerce AI "approved" into a writable status.
        }

        if (array_key_exists('length_class', $row)) {
            $row['length_class'] = $this->normalizeLengthClass($row['length_class']);
        }

        if (array_key_exists('cost_impact', $row)) {
            $row['cost_impact'] = $this->normalizeCostImpact($row['cost_impact']);
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeException(array $row): array
    {
        if (array_key_exists('decision_area_key', $row)) {
            $row['decision_area_key'] = $this->normalizeDecisionArea($row['decision_area_key']);
        }

        if (array_key_exists('confidence', $row)) {
            $row['confidence'] = $this->enums->normalize(
                $row['confidence'],
                ['low', 'medium', 'high'],
                [
                    'laag' => 'low',
                    'middel' => 'medium',
                    'matig' => 'medium',
                    'gemiddeld' => 'medium',
                    'mid' => 'medium',
                    'hoog' => 'high',
                ],
            );
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCustomerTask(array $row): array
    {
        if (array_key_exists('type', $row)) {
            $row['type'] = $this->enums->normalize(
                $row['type'],
                array_column(FollowUpItemType::cases(), 'value'),
                [
                    'tekst' => FollowUpItemType::Text->value,
                    'foto' => FollowUpItemType::Photo->value,
                    'image' => FollowUpItemType::Photo->value,
                    'pdf' => FollowUpItemType::Document->value,
                    'document_pdf' => FollowUpItemType::Document->value,
                    'keuze' => FollowUpItemType::Choice->value,
                ],
            );
        }

        if (array_key_exists('decision_area_key', $row)) {
            $row['decision_area_key'] = $this->normalizeDecisionArea($row['decision_area_key']);
        }

        return $row;
    }

    private function normalizeLengthClass(mixed $value): mixed
    {
        return $this->enums->normalize(
            $value,
            ['short', 'medium', 'long', 'unknown'],
            [
                'kort' => 'short',
                'short_distance' => 'short',
                'short_range' => 'short',
                '<5m' => 'short',
                '0_5m' => 'short',
                '0-5m' => 'short',
                'middel' => 'medium',
                'mid' => 'medium',
                'matig' => 'medium',
                'medium_distance' => 'medium',
                'medium_range' => 'medium',
                '5_15m' => 'medium',
                '5-15m' => 'medium',
                'lang' => 'long',
                'long_distance' => 'long',
                'long_range' => 'long',
                '>15m' => 'long',
                '15m+' => 'long',
                'onbekend' => 'unknown',
                'n/a' => 'unknown',
                'na' => 'unknown',
                'none' => 'unknown',
                'unclear' => 'unknown',
                'uncertain' => 'unknown',
                '?' => 'unknown',
            ],
            'unknown',
        );
    }

    private function normalizeCostImpact(mixed $value): mixed
    {
        return $this->enums->normalize(
            $value,
            ['low', 'medium', 'high', 'unknown'],
            [
                'laag' => 'low',
                'gering' => 'low',
                'klein' => 'low',
                'low_cost' => 'low',
                'middel' => 'medium',
                'matig' => 'medium',
                'gemiddeld' => 'medium',
                'mid' => 'medium',
                'hoog' => 'high',
                'groot' => 'high',
                'high_cost' => 'high',
                'onbekend' => 'unknown',
                'n/a' => 'unknown',
                'na' => 'unknown',
                'unclear' => 'unknown',
                'uncertain' => 'unknown',
            ],
            'unknown',
        );
    }

    private function normalizeDecisionArea(mixed $value): mixed
    {
        return $this->enums->normalize(
            $value,
            ['request', 'capacity', 'placement', 'refrigerant', 'condensate', 'power', 'cost_risks'],
            [
                'aanvraag' => 'request',
                'capaciteit' => 'capacity',
                'plaatsing' => 'placement',
                'koel' => 'refrigerant',
                'koelleiding' => 'refrigerant',
                'cooling' => 'refrigerant',
                'condens' => 'condensate',
                'condensafvoer' => 'condensate',
                'stroom' => 'power',
                'kosten' => 'cost_risks',
                'cost' => 'cost_risks',
                'costs' => 'cost_risks',
                'cost_risk' => 'cost_risks',
            ],
        );
    }
}
