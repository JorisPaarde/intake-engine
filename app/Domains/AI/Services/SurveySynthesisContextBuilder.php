<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Enums\DossierRecordStatus;

/**
 * Builds one bounded, provenance-aware view of the technical survey.
 *
 * Identity, address, storage paths and image bytes are deliberately absent. The
 * existing attention-context builder already bounds and sanitizes legacy evidence;
 * this layer adds the dossier and airco domain objects needed for an integral proposal.
 */
final class SurveySynthesisContextBuilder
{
    public function __construct(
        private readonly IntakeAttentionContextBuilder $legacyContext,
    ) {}

    /** @return array<string, mixed> */
    public function build(Intake $intake): array
    {
        $intake->loadMissing([
            'dossierSubjects.records',
            'aircoRooms',
            'aircoPlacements.room',
            'aircoInstallationOptions.placements',
            'aircoInstallationOptions.connections',
        ]);
        $legacy = $this->legacyContext->build($intake);

        return [
            'task' => 'synthesize_airco_survey_dossier',
            'intake_status' => $intake->status->value,
            'workflow_mode' => $intake->workflow_mode->value,
            'legacy_evidence' => [
                'answer_context' => $legacy['answer_context'] ?? [],
                'external_fact_context' => $legacy['external_fact_context'] ?? [],
                'uploads' => $legacy['uploads'] ?? [],
                'follow_up' => $legacy['follow_up'] ?? [],
                'pipe_routes' => $legacy['pipe_routes'] ?? [],
                'system_attention_points' => $legacy['system_attention_points'] ?? [],
            ],
            'subjects' => $intake->dossierSubjects
                ->map(static fn (DossierSubject $subject): array => [
                    'reference' => 'subject:'.$subject->id,
                    'type' => $subject->type,
                    'label' => $subject->label,
                ])
                ->values()
                ->all(),
            'dossier_records' => $intake->dossierSubjects
                ->flatMap(fn (DossierSubject $subject) => $subject->records
                    ->reject(fn (DossierRecord $record): bool => $record->status === DossierRecordStatus::Superseded)
                    ->map(fn (DossierRecord $record): array => [
                        'reference' => 'record:'.$record->id,
                        'subject_reference' => 'subject:'.$subject->id,
                        'subject' => $subject->label,
                        'kind' => $record->kind->value,
                        'key' => $record->key,
                        'value' => $this->legacyContext->sanitizeExternalValue($record->value),
                        'source_type' => $record->source_type,
                        'method' => $record->method,
                        'confidence' => $record->confidence,
                        'status' => $record->status->value,
                    ]))
                ->values()
                ->all(),
            'rooms' => $intake->aircoRooms
                ->map(static fn (AircoRoom $room): array => [
                    'reference' => 'room:'.$room->id,
                    'subject_reference' => 'subject:'.$room->dossier_subject_id,
                    'name' => $room->name,
                    'use_type' => $room->use_type,
                    'dimensions' => $room->dimensions ?? [],
                    'source_type' => $room->source_type,
                ])
                ->values()
                ->all(),
            'placements' => $intake->aircoPlacements
                ->map(static fn (AircoPlacementOption $placement): array => [
                    'reference' => 'placement:'.$placement->id,
                    'subject_reference' => 'subject:'.$placement->dossier_subject_id,
                    'room_reference' => $placement->airco_room_id === null
                        ? null
                        : 'room:'.$placement->airco_room_id,
                    'type' => $placement->type->value,
                    'label' => $placement->label,
                    'description' => $placement->description,
                    'status' => $placement->status->value,
                    'source_type' => $placement->source_type,
                    'confidence' => $placement->confidence,
                    'cost_risks' => $placement->cost_risks ?? [],
                ])
                ->values()
                ->all(),
            'existing_options' => $intake->aircoInstallationOptions
                ->map(static fn (AircoInstallationOption $option): array => [
                    'reference' => 'option:'.$option->id,
                    'label' => $option->label,
                    'configuration_type' => $option->configuration_type->value,
                    'status' => $option->status->value,
                    'source_type' => $option->source_type,
                    'placement_references' => $option->placements
                        ->map(static fn (AircoPlacementOption $placement): string => 'placement:'.$placement->id)
                        ->values()
                        ->all(),
                    'connections' => $option->connections
                        ->map(static fn (AircoConnection $connection): array => [
                            'reference' => 'connection:'.$connection->id,
                            'type' => $connection->type->value,
                            'label' => $connection->label,
                            'status' => $connection->status->value,
                            'from_placement_reference' => $connection->from_placement_id === null
                                ? null
                                : 'placement:'.$connection->from_placement_id,
                            'to_placement_reference' => $connection->to_placement_id === null
                                ? null
                                : 'placement:'.$connection->to_placement_id,
                            'segments' => $connection->segments ?? [],
                            'obstacles' => $connection->obstacles ?? [],
                            'uncertainties' => $connection->uncertainties ?? [],
                            'cost_impact' => $connection->cost_impact,
                            'confidence' => $connection->confidence,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
