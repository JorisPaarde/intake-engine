<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Actions\ApplyFollowUpTextContribution;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Support\RoomDimensions;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionFeasibility;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AircoSurveyService
{
    public function __construct(
        private readonly DossierManager $dossierManager,
        private readonly DecisionReadinessService $decisionReadiness,
        private readonly InstallerSurveyProgress $surveyProgress,
        private readonly AircoUnitCouplingValidator $couplingValidator,
        private readonly InstallationOptionPreferenceService $preferenceService,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     use_type?: string|null,
     *     length_m?: float|null,
     *     width_m?: float|null,
     *     height_m?: float|null,
     *     area_m2?: float|null
     * }  $data
     */
    public function createRoom(Intake $intake, User $installer, array $data): AircoRoom
    {
        $this->guardTenant($intake, $installer);
        $root = $this->dossierManager->root($intake);
        $key = 'manual-'.Str::lower(Str::ulid()->toBase32());
        $subject = $this->dossierManager->subject(
            $intake,
            'airco.room.'.$key,
            'airco_room',
            trim($data['name']),
            $root,
        );
        $dimensions = RoomDimensions::normalizeWritable([
            'length_m' => $data['length_m'] ?? null,
            'width_m' => $data['width_m'] ?? null,
            'height_m' => $data['height_m'] ?? null,
            'area_m2' => $data['area_m2'] ?? null,
            'area_source' => 'installer',
            'area_confidence' => 'high',
        ]);
        // Eigendomsmarker ook zonder m² (alleen L/B/H), zodat syncRooms antwoorden niet overschrijft.
        $dimensions['dimensions_source'] = 'installer';

        $room = AircoRoom::query()->create([
            'intake_id' => $intake->id,
            'company_id' => $intake->company_id,
            'dossier_subject_id' => $subject->id,
            'key' => $key,
            'name' => trim($data['name']),
            'name_source' => 'installer',
            'use_type' => $data['use_type'] ?? null,
            'use_type_source' => array_key_exists('use_type', $data) && $data['use_type'] !== null
                ? 'installer'
                : null,
            'sort_order' => ((int) $intake->aircoRooms()->max('sort_order')) + 1,
            'status' => 'desired',
            'source_type' => 'installer',
            'source_id' => $installer->id,
            'dimensions' => $dimensions,
        ]);

        $this->activity($intake, $installer, 'airco_room_added', ['room_id' => $room->id]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $room;
    }

    /**
     * @param  array{
     *     name: string,
     *     use_type?: string|null,
     *     floor_level?: string|null,
     *     length_m?: float|null,
     *     width_m?: float|null,
     *     height_m?: float|null,
     *     area_m2?: float|null
     * }  $data
     */
    public function updateRoom(Intake $intake, User $installer, AircoRoom $room, array $data): AircoRoom
    {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $room);

        $dimensions = RoomDimensions::normalizeWritable([
            'length_m' => $data['length_m'] ?? null,
            'width_m' => $data['width_m'] ?? null,
            'height_m' => $data['height_m'] ?? null,
            'area_m2' => $data['area_m2'] ?? null,
            'area_source' => 'installer',
            'area_confidence' => 'high',
        ]);

        $name = trim($data['name']);
        $updates = [];
        $measuresChanged = false;

        $existingDimensions = is_array($room->dimensions) ? $room->dimensions : [];
        if ($this->dimensionMeasuresDiffer($existingDimensions, $dimensions)) {
            $dimensions['dimensions_source'] = 'installer';
            // Bewaar bestaande verdieping bij pure maatwijziging (geen floor in dit request-pad).
            if (! array_key_exists('floor_level', $data)
                && is_string($existingDimensions['floor_level'] ?? null)
                && $existingDimensions['floor_level'] !== '') {
                $dimensions['floor_level'] = $existingDimensions['floor_level'];
                if (isset($existingDimensions['floor_level_source'])) {
                    $dimensions['floor_level_source'] = $existingDimensions['floor_level_source'];
                }
            }
            $updates['dimensions'] = $dimensions;
            $measuresChanged = true;
        }

        if (array_key_exists('floor_level', $data)) {
            $floorLevel = is_string($data['floor_level'] ?? null) && $data['floor_level'] !== ''
                ? $data['floor_level']
                : null;
            // Effectieve verdieping = dimensions, anders intake-antwoord (AI-prefill).
            $previousFloor = $this->effectiveFloorLevel($intake, $room, $existingDimensions);
            if ($floorLevel !== $previousFloor) {
                $merged = $updates['dimensions'] ?? $existingDimensions;
                // Wissen: houd installer-marker met null zodat prefill/sync niet terugzet.
                $merged['floor_level'] = $floorLevel;
                $merged['floor_level_source'] = 'installer';
                $updates['dimensions'] = $merged;
                $this->recordFloorLevelOverride($intake, $installer, $room, $floorLevel, $previousFloor);
            } elseif ($measuresChanged && $floorLevel !== null) {
                // Maatwijziging + ongewijzigde floor: behoud bron, schrijf floor terug.
                $merged = $updates['dimensions'];
                $merged['floor_level'] = $floorLevel;
                if (isset($existingDimensions['floor_level_source'])) {
                    $merged['floor_level_source'] = $existingDimensions['floor_level_source'];
                }
                $updates['dimensions'] = $merged;
            }
        }

        if (array_key_exists('use_type', $data)) {
            $updates['use_type'] = $data['use_type'];
            $updates['use_type_source'] = 'installer';
        }
        if ($name !== $room->name) {
            $updates['name'] = $name;
            $updates['name_source'] = 'installer';
        }

        if ($updates !== []) {
            $room->update($updates);
        }

        DossierSubject::query()
            ->whereKey($room->dossier_subject_id)
            ->where('intake_id', $intake->id)
            ->update(['label' => $name]);

        // Alleen maten superseden — niet bij pure floor/naam-edit.
        if ($measuresChanged && isset($updates['dimensions'])) {
            $this->supersedeAiDimensionProposals(
                $intake,
                $installer,
                $room->fresh() ?? $room,
                $updates['dimensions'],
            );
        }

        // Confirming height also closes a pending customer height proposal.
        $freshRoom = $room->fresh() ?? $room;
        if (RoomDimensions::from(is_array($freshRoom->dimensions) ? $freshRoom->dimensions : null)->hasHeight()) {
            DossierRecord::query()
                ->where('intake_id', $intake->id)
                ->where('dossier_subject_id', $freshRoom->dossier_subject_id)
                ->where('key', ApplyFollowUpTextContribution::RECORD_KEY_HEIGHT)
                ->where('status', DossierRecordStatus::Proposed)
                ->whereNull('superseded_by_id')
                ->update([
                    'status' => DossierRecordStatus::Superseded,
                ]);
        }

        $this->activity($intake, $installer, 'airco_room_updated', ['room_id' => $room->id]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $room->fresh() ?? $room;
    }

    /**
     * Effectieve verdieping: dimensions eerst, anders room-N intake-antwoord.
     * Installateur-marker (ook met null) wint — geen fallback naar antwoord.
     *
     * @param  array<string, mixed>  $existingDimensions
     */
    private function effectiveFloorLevel(Intake $intake, AircoRoom $room, array $existingDimensions): ?string
    {
        if (($existingDimensions['floor_level_source'] ?? null) === 'installer') {
            $cleared = $existingDimensions['floor_level'] ?? null;

            return is_string($cleared) && $cleared !== '' ? $cleared : null;
        }

        $fromDimensions = is_string($existingDimensions['floor_level'] ?? null)
            && $existingDimensions['floor_level'] !== ''
            ? $existingDimensions['floor_level']
            : null;
        if ($fromDimensions !== null) {
            return $fromDimensions;
        }

        if (preg_match('/^room-\d+$/', $room->key) !== 1) {
            return null;
        }

        $answer = $intake->answers()
            ->where('question_key', 'floor_level')
            ->where('section_instance_key', $room->key)
            ->first();
        $value = is_array($answer?->value) ? ($answer->value['value'] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function recordFloorLevelOverride(
        Intake $intake,
        User $installer,
        AircoRoom $room,
        ?string $floorLevel,
        ?string $previousFloor,
    ): void {
        $subject = $room->subject
            ?? DossierSubject::query()
                ->whereKey($room->dossier_subject_id)
                ->where('intake_id', $intake->id)
                ->first();

        if (! $subject instanceof DossierSubject) {
            return;
        }

        $labels = [
            'basement' => 'Kelder / souterrain',
            'ground' => 'Begane grond',
            '1' => '1e verdieping',
            '2' => '2e verdieping',
            '3_plus' => '3e verdieping of hoger',
            'attic' => 'Zolder',
        ];

        if ($floorLevel !== null && isset($labels[$floorLevel])) {
            $this->dossierManager->record(
                intake: $intake,
                subject: $subject,
                kind: DossierRecordKind::Observation,
                key: 'floor_level',
                value: [
                    'value' => $floorLevel,
                    '_field_label' => 'Verdieping',
                    '_display_value' => $labels[$floorLevel],
                    '_source_label' => 'installateur',
                    '_provenance_label' => 'gezegd',
                    '_previous_value' => $previousFloor,
                ],
                actorType: 'installer',
                actorId: $installer->id,
                sourceType: 'installer',
                sourceId: $installer->id,
                method: 'installer_corrected',
                confidence: 1.0,
                status: DossierRecordStatus::Established,
            );
        } else {
            // Wissen: supersede open floor_level-records zonder nieuwe waarde.
            DossierRecord::query()
                ->where('intake_id', $intake->id)
                ->where('dossier_subject_id', $subject->id)
                ->where('key', 'floor_level')
                ->whereNull('superseded_by_id')
                ->whereIn('status', [
                    DossierRecordStatus::Proposed,
                    DossierRecordStatus::Established,
                    DossierRecordStatus::Conflicted,
                ])
                ->update(['status' => DossierRecordStatus::Superseded]);
        }

        // Houd het intake-antwoord in sync (ook bij wissen) zodat syncRooms niet terugzet.
        if (preg_match('/^room-\d+$/', $room->key) === 1) {
            $answer = $intake->answers()
                ->where('question_key', 'floor_level')
                ->where('section_instance_key', $room->key)
                ->first();
            if ($answer !== null) {
                if ($floorLevel === null) {
                    $answer->delete();
                } else {
                    $answer->update([
                        'value' => ['value' => $floorLevel],
                        'prefill_source' => null,
                        'fact_source' => null,
                        'fact_provenance' => null,
                    ]);
                }
            }
        }
    }

    /**
     * Mark prior AI/proposal dimension records as superseded when the installer
     * corrects effective room measures (reuse DossierManager::record).
     *
     * @param  array<string, float|string>  $dimensions
     */
    private function supersedeAiDimensionProposals(
        Intake $intake,
        User $installer,
        AircoRoom $room,
        array $dimensions,
    ): void {
        $subject = $room->subject
            ?? DossierSubject::query()
                ->whereKey($room->dossier_subject_id)
                ->where('intake_id', $intake->id)
                ->first();

        if (! $subject instanceof DossierSubject) {
            return;
        }

        $mapping = [
            'length_m' => 'room_length_m',
            'width_m' => 'room_width_m',
            'height_m' => 'ceiling_height_m',
            'area_m2' => 'room_area_m2',
        ];

        foreach ($mapping as $dimensionKey => $questionKey) {
            if (! isset($dimensions[$dimensionKey]) || ! is_numeric($dimensions[$dimensionKey])) {
                continue;
            }

            $value = (float) $dimensions[$dimensionKey];
            $answerKeySuffix = $questionKey;
            $dimensionLabels = [
                'length_m' => 'Lengte (m)',
                'width_m' => 'Breedte (m)',
                'height_m' => 'Hoogte (m)',
                'area_m2' => 'Oppervlak (m²)',
            ];
            // Prefill answer keys look like answer.room-1.room_length_m or answer.room_length_m.
            $prior = DossierRecord::query()
                ->where('intake_id', $intake->id)
                ->where('dossier_subject_id', $subject->id)
                ->whereIn('status', [
                    DossierRecordStatus::Proposed,
                    DossierRecordStatus::Established,
                    DossierRecordStatus::Conflicted,
                ])
                ->where(function ($query) use ($answerKeySuffix): void {
                    $query->where('key', 'like', '%.'.$answerKeySuffix)
                        ->orWhere('key', 'answer.'.$answerKeySuffix);
                })
                ->whereNull('superseded_by_id')
                ->get();

            if ($prior->isEmpty()) {
                continue;
            }

            $this->dossierManager->record(
                intake: $intake,
                subject: $subject,
                kind: DossierRecordKind::Observation,
                key: 'dimensions.'.$dimensionKey,
                value: [
                    'number' => $value,
                    'unit' => 'm',
                    '_field_label' => $dimensionLabels[$dimensionKey],
                    '_display_value' => (string) $value,
                    '_source_label' => 'installateur',
                    '_provenance_label' => 'gezegd',
                ],
                actorType: 'installer',
                actorId: $installer->id,
                sourceType: 'installer',
                sourceId: $installer->id,
                method: 'installer_corrected',
                confidence: 1.0,
                status: DossierRecordStatus::Established,
            );

            // Also supersede answer.* keys that share the same measure but a
            // different dossier key than dimensions.* — record() only supersedes
            // identical keys, so mark the AI answer proposals explicitly.
            foreach ($prior as $old) {
                if ($old->key === 'dimensions.'.$dimensionKey) {
                    continue;
                }

                $replacement = DossierRecord::query()
                    ->where('intake_id', $intake->id)
                    ->where('dossier_subject_id', $subject->id)
                    ->where('key', 'dimensions.'.$dimensionKey)
                    ->where('status', DossierRecordStatus::Established)
                    ->whereNull('superseded_by_id')
                    ->latest('id')
                    ->first();

                if ($replacement === null) {
                    continue;
                }

                $old->update([
                    'status' => DossierRecordStatus::Superseded,
                    'superseded_by_id' => $replacement->id,
                ]);
            }
        }
    }

    /**
     * @param  array<string, float|string>  $existing
     * @param  array<string, float|string>  $submitted
     */
    private function dimensionMeasuresDiffer(array $existing, array $submitted): bool
    {
        foreach (['length_m', 'width_m', 'height_m', 'area_m2'] as $key) {
            $left = isset($existing[$key]) && is_numeric($existing[$key]) ? (float) $existing[$key] : null;
            $right = isset($submitted[$key]) && is_numeric($submitted[$key]) ? (float) $submitted[$key] : null;

            if ($left === null && $right === null) {
                continue;
            }

            if ($left === null || $right === null || abs($left - $right) > 0.0001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{
     *     airco_room_id?: int|null,
     *     type: AircoPlacementType|string,
     *     label: string,
     *     description?: string|null,
     *     status?: AircoOptionStatus|string,
     *     confidence?: float|null
     * }  $data
     */
    public function createPlacement(Intake $intake, User $installer, array $data): AircoPlacementOption
    {
        $this->guardTenant($intake, $installer);
        $type = $data['type'] instanceof AircoPlacementType
            ? $data['type']
            : AircoPlacementType::from((string) $data['type']);
        $room = $this->resolvePlacementRoom($intake, $type, $data['airco_room_id'] ?? null);
        $root = $room->subject ?? $this->dossierManager->root($intake);
        $key = 'airco.placement.'.Str::lower(Str::ulid()->toBase32());
        $subject = $this->dossierManager->subject(
            $intake,
            $key,
            'airco_placement',
            trim($data['label']),
            $root,
            ['placement_type' => $type->value],
        );

        $placement = AircoPlacementOption::query()->create([
            'intake_id' => $intake->id,
            'company_id' => $intake->company_id,
            'airco_room_id' => $room?->id,
            'dossier_subject_id' => $subject->id,
            'type' => $type,
            'label' => trim($data['label']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'status' => $data['status'] ?? AircoOptionStatus::Candidate,
            'source_type' => 'installer',
            'source_id' => $installer->id,
            'confidence' => $data['confidence'] ?? 1.0,
        ]);

        $this->activity($intake, $installer, 'airco_placement_added', [
            'placement_id' => $placement->id,
            'type' => $type->value,
        ]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $placement;
    }

    /**
     * @param  array{
     *     airco_room_id?: int|null,
     *     type: AircoPlacementType|string,
     *     label: string,
     *     description?: string|null
     * }  $data
     */
    public function updatePlacement(
        Intake $intake,
        User $installer,
        AircoPlacementOption $placement,
        array $data,
    ): AircoPlacementOption {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $placement);

        $type = $data['type'] instanceof AircoPlacementType
            ? $data['type']
            : AircoPlacementType::from((string) $data['type']);
        $room = $this->resolvePlacementRoom($intake, $type, $data['airco_room_id'] ?? null);
        $label = trim($data['label']);

        $placement->update([
            'airco_room_id' => $room?->id,
            'type' => $type,
            'label' => $label,
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
        ]);

        $subject = $placement->subject
            ?? DossierSubject::query()
                ->whereKey($placement->dossier_subject_id)
                ->where('intake_id', $intake->id)
                ->firstOrFail();

        $meta = is_array($subject->meta) ? $subject->meta : [];
        $meta['placement_type'] = $type->value;
        $subject->update([
            'label' => $label,
            'meta' => $meta,
            'parent_id' => $room !== null
                ? $room->dossier_subject_id
                : $this->dossierManager->root($intake)->id,
        ]);

        $this->activity($intake, $installer, 'airco_placement_updated', [
            'placement_id' => $placement->id,
            'type' => $type->value,
        ]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $placement->fresh(['room', 'subject']) ?? $placement;
    }

    /**
     * @param  array{
     *     label: string,
     *     configuration_type: AircoConfigurationType|string,
     *     summary?: string|null,
     *     cost_impact?: string|null,
     *     placement_ids: list<int>,
     *     refrigerant_links?: list<array{from_placement_id: int, to_placement_id: int}>
     * }  $data
     */
    public function createInstallationOption(
        Intake $intake,
        User $installer,
        array $data,
    ): AircoInstallationOption {
        $this->guardTenant($intake, $installer);
        $placements = AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->whereIn('id', $data['placement_ids'])
            ->get();

        if ($placements->count() !== count(array_unique($data['placement_ids']))) {
            throw ValidationException::withMessages([
                'placement_ids' => 'Eén of meer units horen niet bij deze opname.',
            ]);
        }

        if (! $placements->contains('type', AircoPlacementType::IndoorUnit)
            || ! $placements->contains('type', AircoPlacementType::OutdoorUnit)) {
            throw ValidationException::withMessages([
                'placement_ids' => 'Een keuze bevat minimaal één binnenunit en één buitenunit.',
            ]);
        }

        $indoorsWithoutRoom = $placements
            ->where('type', AircoPlacementType::IndoorUnit)
            ->filter(static fn (AircoPlacementOption $placement): bool => $placement->airco_room_id === null);
        if ($indoorsWithoutRoom->isNotEmpty()) {
            throw ValidationException::withMessages([
                'placement_ids' => 'Iedere binnenunit hoort bij precies één gewenste ruimte.',
            ]);
        }

        $outdoorsWithRoom = $placements
            ->where('type', AircoPlacementType::OutdoorUnit)
            ->filter(static fn (AircoPlacementOption $placement): bool => $placement->airco_room_id !== null);
        if ($outdoorsWithRoom->isNotEmpty()) {
            throw ValidationException::withMessages([
                'placement_ids' => 'Een buitenunit is een gedeelde montageplek en hoort niet bij één ruimte.',
            ]);
        }

        $configuration = $data['configuration_type'] instanceof AircoConfigurationType
            ? $data['configuration_type']
            : AircoConfigurationType::from((string) $data['configuration_type']);
        $indoorCount = $placements->where('type', AircoPlacementType::IndoorUnit)->count();
        $outdoorCount = $placements->where('type', AircoPlacementType::OutdoorUnit)->count();
        $validConfiguration = match ($configuration) {
            AircoConfigurationType::SingleSplit => $indoorCount === 1 && $outdoorCount === 1,
            AircoConfigurationType::MultiSplit => $indoorCount >= 2 && $outdoorCount === 1,
            AircoConfigurationType::MultipleSingleSplits => $indoorCount >= 2
                && $outdoorCount === $indoorCount,
        };

        if (! $validConfiguration) {
            throw ValidationException::withMessages([
                'configuration_type' => 'Het aantal gekozen binnen- en buitenunits past niet bij deze configuratie.',
            ]);
        }

        $refrigerantLinks = $data['refrigerant_links'] ?? [];
        if ($refrigerantLinks !== []) {
            $this->assertRefrigerantLinkPayload($placements, $refrigerantLinks);
            $virtualConnections = collect($refrigerantLinks)->map(
                static function (array $link): AircoConnection {
                    $connection = new AircoConnection([
                        'type' => AircoConnectionType::Refrigerant,
                        'from_placement_id' => $link['from_placement_id'],
                        'to_placement_id' => $link['to_placement_id'],
                    ]);

                    return $connection;
                },
            );
            $problems = $this->couplingValidator->problems(
                $configuration,
                $placements,
                $virtualConnections,
                requireComplete: true,
            );
            if ($problems !== []) {
                throw ValidationException::withMessages([
                    'refrigerant_links' => $problems[0],
                ]);
            }
        }

        $option = DB::transaction(function () use ($intake, $installer, $data, $placements, $configuration, $refrigerantLinks): AircoInstallationOption {
            $option = AircoInstallationOption::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'label' => trim($data['label']),
                'configuration_type' => $configuration,
                'rank' => ((int) $intake->aircoInstallationOptions()->max('rank')) + 1,
                'status' => AircoOptionStatus::Candidate,
                'feasibility' => AircoOptionFeasibility::Pending,
                'infeasibility_reason' => null,
                'summary' => isset($data['summary']) ? trim((string) $data['summary']) : null,
                'cost_impact' => $data['cost_impact'] ?? null,
                'source_type' => 'installer',
                'source_id' => $installer->id,
                'confidence' => 1.0,
                'created_by' => $installer->id,
            ]);

            foreach ($placements->values() as $index => $placement) {
                $option->placements()->attach($placement->id, [
                    'role' => $placement->type->value,
                    'sort_order' => $index + 1,
                ]);
            }

            foreach ($refrigerantLinks as $link) {
                $from = $placements->firstWhere('id', $link['from_placement_id']);
                $to = $placements->firstWhere('id', $link['to_placement_id']);
                $label = sprintf(
                    'Koelleiding %s → %s',
                    $from instanceof AircoPlacementOption ? $from->label : 'binnenunit',
                    $to instanceof AircoPlacementOption ? $to->label : 'buitenunit',
                );
                $this->storeConnectionRow($intake, $installer, $option, [
                    'type' => AircoConnectionType::Refrigerant,
                    'label' => $label,
                    'from_placement_id' => $link['from_placement_id'],
                    'to_placement_id' => $link['to_placement_id'],
                    'status' => AircoConnectionStatus::Unknown,
                ]);
            }

            return $option->load(['placements', 'connections']);
        }, 3);

        $this->activity($intake, $installer, 'airco_installation_option_added', [
            'option_id' => $option->id,
            'configuration_type' => $configuration->value,
            'placement_count' => $placements->count(),
        ]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $option;
    }

    public function markInstallationOptionFeasible(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
    ): AircoInstallationOption {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $option);

        $option->update([
            'feasibility' => AircoOptionFeasibility::Feasible,
            'infeasibility_reason' => null,
        ]);

        $this->activity($intake, $installer, 'airco_installation_option_feasible', [
            'option_id' => $option->id,
        ]);
        $this->preferenceService->invalidateIfFeasibleSetChanged($intake->fresh() ?? $intake, $installer);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $option->fresh(['placements', 'connections']) ?? $option;
    }

    public function markInstallationOptionInfeasible(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
        string $reason,
    ): AircoInstallationOption {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $option);

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'infeasibility_reason' => 'Schrijf kort waarom deze keuze niet haalbaar is.',
            ]);
        }

        if ($option->status === AircoOptionStatus::Selected) {
            throw ValidationException::withMessages([
                'option' => 'Maak eerst een andere keuze voordat je deze als niet haalbaar markeert.',
            ]);
        }

        $option->update([
            'feasibility' => AircoOptionFeasibility::Infeasible,
            'infeasibility_reason' => $reason,
            'status' => AircoOptionStatus::Candidate,
            'selected_at' => null,
        ]);

        $this->activity($intake, $installer, 'airco_installation_option_infeasible', [
            'option_id' => $option->id,
        ]);
        $this->preferenceService->invalidateIfFeasibleSetChanged($intake->fresh() ?? $intake, $installer);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $option->fresh(['placements', 'connections']) ?? $option;
    }

    public function selectInstallationOption(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
    ): AircoInstallationOption {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $option);

        if ($option->feasibility !== AircoOptionFeasibility::Feasible
            && $option->status !== AircoOptionStatus::Selected) {
            throw ValidationException::withMessages([
                'option' => 'Markeer deze keuze eerst als haalbaar voordat je hem selecteert.',
            ]);
        }

        $problems = $this->couplingValidator->optionProblems($option, requireComplete: false);
        if ($problems !== []) {
            throw ValidationException::withMessages([
                'option' => $problems[0],
            ]);
        }

        DB::transaction(function () use ($intake, $option): void {
            $intake->aircoInstallationOptions()
                ->where('status', AircoOptionStatus::Selected)
                ->where('id', '!=', $option->id)
                ->update([
                    'status' => AircoOptionStatus::Candidate,
                    'selected_at' => null,
                ]);
            $option->update([
                'status' => AircoOptionStatus::Selected,
                'feasibility' => AircoOptionFeasibility::Feasible,
                'infeasibility_reason' => null,
                'selected_at' => now(),
            ]);
        }, 3);

        $this->activity($intake, $installer, 'airco_installation_option_selected', [
            'option_id' => $option->id,
        ]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $option->fresh(['placements', 'connections']) ?? $option;
    }

    /**
     * Update configuration type on an existing choice and revalidate refrigerant links.
     */
    public function updateConfigurationType(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
        AircoConfigurationType|string $configurationType,
    ): AircoInstallationOption {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $option);

        $configuration = $configurationType instanceof AircoConfigurationType
            ? $configurationType
            : AircoConfigurationType::from((string) $configurationType);

        $option->loadMissing(['placements', 'connections']);
        $problems = $this->couplingValidator->problems(
            $configuration,
            $option->placements,
            $option->connections,
            requireComplete: false,
        );
        if ($problems !== []) {
            throw ValidationException::withMessages([
                'configuration_type' => $problems[0],
            ]);
        }

        $option->update(['configuration_type' => $configuration]);

        $this->activity($intake, $installer, 'airco_installation_option_configuration_updated', [
            'option_id' => $option->id,
            'configuration_type' => $configuration->value,
        ]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $option->fresh(['placements', 'connections']) ?? $option;
    }

    /**
     * Room-centric indoor↔outdoor coupling: ensure indoor belongs to the room,
     * link it to a labeled outdoor via refrigerant, and set configuration type.
     *
     * @param  array{
     *     indoor_label: string,
     *     outdoor_placement_id?: int|null,
     *     outdoor_label?: string|null,
     *     configuration_type: AircoConfigurationType|string,
     *     installation_option_id?: int|null
     * }  $data
     */
    public function syncRoomUnitCoupling(
        Intake $intake,
        User $installer,
        AircoRoom $room,
        array $data,
    ): AircoInstallationOption {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $room);

        $configuration = $data['configuration_type'] instanceof AircoConfigurationType
            ? $data['configuration_type']
            : AircoConfigurationType::from((string) $data['configuration_type']);
        $indoorLabel = trim($data['indoor_label']);
        if ($indoorLabel === '') {
            throw ValidationException::withMessages([
                'indoor_label' => 'Geef de binnenunit een naam.',
            ]);
        }

        $outdoorId = $data['outdoor_placement_id'] ?? null;
        if (! is_numeric($outdoorId) || (int) $outdoorId <= 0) {
            $outdoorLabel = trim((string) ($data['outdoor_label'] ?? ''));
            if ($outdoorLabel === '') {
                throw ValidationException::withMessages([
                    'outdoor_label' => 'Kies een bestaande buitenunit of geef een nieuwe naam.',
                ]);
            }
        }

        return DB::transaction(function () use ($intake, $installer, $room, $data, $configuration, $indoorLabel, $outdoorId): AircoInstallationOption {
            $indoor = $room->placements()
                ->where('type', AircoPlacementType::IndoorUnit)
                ->orderBy('id')
                ->first();

            if ($indoor instanceof AircoPlacementOption) {
                $indoor = $this->updatePlacement($intake, $installer, $indoor, [
                    'airco_room_id' => $room->id,
                    'type' => AircoPlacementType::IndoorUnit,
                    'label' => $indoorLabel,
                    'description' => $indoor->description,
                ]);
            } else {
                $indoor = $this->createPlacement($intake, $installer, [
                    'airco_room_id' => $room->id,
                    'type' => AircoPlacementType::IndoorUnit,
                    'label' => $indoorLabel,
                ]);
            }

            if (is_numeric($outdoorId) && (int) $outdoorId > 0) {
                $outdoor = AircoPlacementOption::query()
                    ->where('intake_id', $intake->id)
                    ->where('type', AircoPlacementType::OutdoorUnit)
                    ->findOrFail((int) $outdoorId);
                if ($outdoor->airco_room_id !== null) {
                    $outdoor->update(['airco_room_id' => null]);
                }
            } else {
                $outdoor = $this->createPlacement($intake, $installer, [
                    'type' => AircoPlacementType::OutdoorUnit,
                    'label' => trim((string) ($data['outdoor_label'] ?? '')),
                ]);
            }

            $option = $this->resolveOptionForRoomCoupling(
                $intake,
                $installer,
                $configuration,
                isset($data['installation_option_id']) ? (int) $data['installation_option_id'] : null,
                $indoor,
                $outdoor,
            );

            $this->ensurePlacementOnOption($option, $indoor);
            $this->ensurePlacementOnOption($option, $outdoor);

            $previousConfiguration = $option->configuration_type;

            if ($option->configuration_type !== $configuration) {
                $option->update(['configuration_type' => $configuration]);
            }

            $option->load(['placements', 'connections.fromPlacement', 'connections.toPlacement']);
            $this->upsertRefrigerantLink($intake, $installer, $option, $indoor, $outdoor);

            $option->load(['placements', 'connections.fromPlacement', 'connections.toPlacement']);
            $problems = $this->couplingValidator->optionProblems($option, requireComplete: false);
            if ($problems !== []) {
                $message = $this->singleSplitConflictsWithOtherRoomLinks(
                    $previousConfiguration,
                    $configuration,
                    $option,
                    $room,
                    $outdoor,
                )
                    ? AircoUnitCouplingValidator::OUTDOOR_ALREADY_ON_MULTI_SPLIT
                    : $problems[0];

                throw ValidationException::withMessages([
                    'outdoor_placement_id' => $message,
                ]);
            }

            $this->activity($intake, $installer, 'airco_room_unit_coupling_synced', [
                'room_id' => $room->id,
                'option_id' => $option->id,
                'configuration_type' => $configuration->value,
            ]);
            $this->surveyProgress->markStarted($intake);
            $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

            return $option->fresh(['placements', 'connections.fromPlacement', 'connections.toPlacement']) ?? $option;
        });
    }

    /**
     * True when the installer is choosing SingleSplit, the option was already
     * MultiSplit, and another room's indoor already shares a refrigerant link to
     * this same outdoor — the UX cue for "outdoor already on a multi-split".
     */
    private function singleSplitConflictsWithOtherRoomLinks(
        AircoConfigurationType $previousConfiguration,
        AircoConfigurationType $configuration,
        AircoInstallationOption $option,
        AircoRoom $room,
        AircoPlacementOption $outdoor,
    ): bool {
        if ($configuration !== AircoConfigurationType::SingleSplit) {
            return false;
        }

        if ($previousConfiguration !== AircoConfigurationType::MultiSplit) {
            return false;
        }

        foreach ($option->connections as $connection) {
            if ($connection->type !== AircoConnectionType::Refrigerant) {
                continue;
            }

            $from = $connection->fromPlacement;
            $to = $connection->toPlacement;

            $indoor = null;
            $linkedOutdoor = null;
            if ($from instanceof AircoPlacementOption && $from->type === AircoPlacementType::IndoorUnit) {
                $indoor = $from;
                $linkedOutdoor = $to;
            } elseif ($to instanceof AircoPlacementOption && $to->type === AircoPlacementType::IndoorUnit) {
                $indoor = $to;
                $linkedOutdoor = $from;
            }

            if (
                $indoor instanceof AircoPlacementOption
                && (int) $indoor->airco_room_id !== (int) $room->id
                && $linkedOutdoor instanceof AircoPlacementOption
                && (int) $linkedOutdoor->id === (int) $outdoor->id
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{
     *     type: AircoConnectionType|string,
     *     label: string,
     *     from_placement_id?: int|null,
     *     to_placement_id?: int|null,
     *     status?: AircoConnectionStatus|string,
     *     length_class?: string|null,
     *     segments?: list<string>,
     *     obstacles?: list<string>,
     *     uncertainties?: list<string>,
     *     cost_impact?: string|null,
     *     confidence?: float|null
     * }  $data
     */
    public function createConnection(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
        array $data,
    ): AircoConnection {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $option);
        $type = $data['type'] instanceof AircoConnectionType
            ? $data['type']
            : AircoConnectionType::from((string) $data['type']);
        $status = $data['status'] ?? AircoConnectionStatus::Unknown;
        $status = $status instanceof AircoConnectionStatus
            ? $status
            : AircoConnectionStatus::from((string) $status);
        $from = $this->placement($intake, $data['from_placement_id'] ?? null);
        $to = $this->placement($intake, $data['to_placement_id'] ?? null);
        $option->loadMissing(['placements', 'connections']);
        $optionPlacementIds = $option->placements->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ([$from, $to] as $placement) {
            if ($placement !== null && ! in_array($placement->id, $optionPlacementIds, true)) {
                throw ValidationException::withMessages([
                    'from_placement_id' => 'Iedere unit in de route moet onderdeel zijn van deze keuze.',
                ]);
            }
        }

        if ($type === AircoConnectionType::Refrigerant) {
            $normalized = $this->normalizeRefrigerantEndpoints($from, $to);
            $from = $normalized['from'];
            $to = $normalized['to'];
            $data['from_placement_id'] = $from->id;
            $data['to_placement_id'] = $to->id;

            $virtual = $option->connections
                ->filter(static fn (AircoConnection $c): bool => $c->type === AircoConnectionType::Refrigerant)
                ->values();
            $pending = new AircoConnection([
                'type' => AircoConnectionType::Refrigerant,
                'from_placement_id' => $from->id,
                'to_placement_id' => $to->id,
            ]);
            $problems = $this->couplingValidator->problems(
                $option->configuration_type,
                $option->placements,
                $virtual->push($pending),
                requireComplete: false,
            );
            if ($problems !== []) {
                throw ValidationException::withMessages([
                    'from_placement_id' => $problems[0],
                ]);
            }
        }

        $connection = $this->storeConnectionRow($intake, $installer, $option, [
            ...$data,
            'type' => $type,
            'status' => $status,
            'from_placement_id' => $from?->id,
            'to_placement_id' => $to?->id,
        ]);

        $this->activity($intake, $installer, 'airco_connection_added', [
            'connection_id' => $connection->id,
            'type' => $type->value,
        ]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $connection;
    }

    private function placement(Intake $intake, mixed $id): ?AircoPlacementOption
    {
        if ($id === null || $id === '') {
            return null;
        }

        return AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->findOrFail((int) $id);
    }

    private function resolvePlacementRoom(
        Intake $intake,
        AircoPlacementType $type,
        mixed $roomId,
    ): ?AircoRoom {
        if ($type === AircoPlacementType::IndoorUnit) {
            if (! is_numeric($roomId)) {
                throw ValidationException::withMessages([
                    'airco_room_id' => 'Een binnenunit hoort bij precies één gewenste ruimte.',
                ]);
            }

            return AircoRoom::query()
                ->where('intake_id', $intake->id)
                ->findOrFail((int) $roomId);
        }

        if (is_numeric($roomId) && in_array($type, [
            AircoPlacementType::OutdoorUnit,
            AircoPlacementType::PowerSource,
            AircoPlacementType::DrainPoint,
        ], true)) {
            // Outdoor/shared placements are never owned by a room.
            return null;
        }

        if (is_numeric($roomId)) {
            return AircoRoom::query()
                ->where('intake_id', $intake->id)
                ->findOrFail((int) $roomId);
        }

        return null;
    }

    /**
     * @param  Collection<int, AircoPlacementOption>  $placements
     * @param  list<array{from_placement_id: int, to_placement_id: int}>  $links
     */
    private function assertRefrigerantLinkPayload(Collection $placements, array $links): void
    {
        $ids = $placements->pluck('id')->all();
        foreach ($links as $link) {
            if (! in_array($link['from_placement_id'], $ids, true)
                || ! in_array($link['to_placement_id'], $ids, true)) {
                throw ValidationException::withMessages([
                    'refrigerant_links' => 'Een koelleiding koppelt units die niet bij deze keuze horen.',
                ]);
            }
            $from = $placements->firstWhere('id', $link['from_placement_id']);
            $to = $placements->firstWhere('id', $link['to_placement_id']);
            $this->normalizeRefrigerantEndpoints(
                $from instanceof AircoPlacementOption ? $from : null,
                $to instanceof AircoPlacementOption ? $to : null,
            );
        }
    }

    /**
     * @return array{from: AircoPlacementOption, to: AircoPlacementOption}
     */
    private function normalizeRefrigerantEndpoints(
        ?AircoPlacementOption $from,
        ?AircoPlacementOption $to,
    ): array {
        if ($from === null || $to === null) {
            throw ValidationException::withMessages([
                'from_placement_id' => 'Een koelleiding koppelt een binnenunit aan een buitenunit.',
            ]);
        }

        if ($from->type === AircoPlacementType::IndoorUnit && $to->type === AircoPlacementType::OutdoorUnit) {
            return ['from' => $from, 'to' => $to];
        }

        if ($from->type === AircoPlacementType::OutdoorUnit && $to->type === AircoPlacementType::IndoorUnit) {
            return ['from' => $to, 'to' => $from];
        }

        throw ValidationException::withMessages([
            'from_placement_id' => 'Een koelleiding koppelt een binnenunit aan een buitenunit.',
        ]);
    }

    /**
     * @param  array{
     *     type: AircoConnectionType|string,
     *     label: string,
     *     from_placement_id?: int|null,
     *     to_placement_id?: int|null,
     *     status?: AircoConnectionStatus|string,
     *     length_class?: string|null,
     *     segments?: list<string>,
     *     obstacles?: list<string>,
     *     uncertainties?: list<string>,
     *     cost_impact?: string|null,
     *     confidence?: float|null
     * }  $data
     */
    private function storeConnectionRow(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
        array $data,
    ): AircoConnection {
        $type = $data['type'] instanceof AircoConnectionType
            ? $data['type']
            : AircoConnectionType::from((string) $data['type']);
        $status = $data['status'] ?? AircoConnectionStatus::Unknown;
        $status = $status instanceof AircoConnectionStatus
            ? $status
            : AircoConnectionStatus::from((string) $status);

        $root = $this->dossierManager->root($intake);
        $subject = $this->dossierManager->subject(
            $intake,
            'airco.connection.'.Str::lower(Str::ulid()->toBase32()),
            'airco_connection',
            trim($data['label']),
            $root,
            ['connection_type' => $type->value, 'installation_option_id' => $option->id],
        );

        return AircoConnection::query()->create([
            'intake_id' => $intake->id,
            'company_id' => $intake->company_id,
            'airco_installation_option_id' => $option->id,
            'from_placement_id' => $data['from_placement_id'] ?? null,
            'to_placement_id' => $data['to_placement_id'] ?? null,
            'dossier_subject_id' => $subject->id,
            'type' => $type,
            'label' => trim($data['label']),
            'status' => $status,
            'length_class' => $data['length_class'] ?? null,
            'segments' => $data['segments'] ?? [],
            'obstacles' => $data['obstacles'] ?? [],
            'uncertainties' => $data['uncertainties'] ?? [],
            'cost_impact' => $data['cost_impact'] ?? null,
            'confidence' => $data['confidence'] ?? null,
            'source_type' => 'installer',
            'source_id' => $installer->id,
            'safety_check_required' => $type === AircoConnectionType::Power,
        ]);
    }

    private function resolveOptionForRoomCoupling(
        Intake $intake,
        User $installer,
        AircoConfigurationType $configuration,
        ?int $optionId,
        AircoPlacementOption $indoor,
        AircoPlacementOption $outdoor,
    ): AircoInstallationOption {
        if ($optionId !== null && $optionId > 0) {
            $option = AircoInstallationOption::query()
                ->where('intake_id', $intake->id)
                ->findOrFail($optionId);
            $this->guardModel($intake, $option);

            return $option;
        }

        $selected = $intake->aircoInstallationOptions()
            ->where('status', AircoOptionStatus::Selected)
            ->first();
        if ($selected instanceof AircoInstallationOption) {
            return $selected;
        }

        $candidate = $intake->aircoInstallationOptions()->orderBy('rank')->first();
        if ($candidate instanceof AircoInstallationOption) {
            return $candidate;
        }

        return $this->createInstallationOption($intake, $installer, [
            'label' => match ($configuration) {
                AircoConfigurationType::SingleSplit => 'Keuze · single-split',
                AircoConfigurationType::MultiSplit => 'Keuze · multi-split',
                AircoConfigurationType::MultipleSingleSplits => 'Keuze · meerdere single-splits',
            },
            'configuration_type' => AircoConfigurationType::SingleSplit,
            'placement_ids' => [$indoor->id, $outdoor->id],
            'refrigerant_links' => [[
                'from_placement_id' => $indoor->id,
                'to_placement_id' => $outdoor->id,
            ]],
        ]);
    }

    private function ensurePlacementOnOption(
        AircoInstallationOption $option,
        AircoPlacementOption $placement,
    ): void {
        if ($option->placements()->where('airco_placement_options.id', $placement->id)->exists()) {
            return;
        }

        $sort = ((int) $option->placements()->max('airco_installation_option_placements.sort_order')) + 1;
        $option->placements()->attach($placement->id, [
            'role' => $placement->type->value,
            'sort_order' => $sort,
        ]);
    }

    private function upsertRefrigerantLink(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
        AircoPlacementOption $indoor,
        AircoPlacementOption $outdoor,
    ): void {
        $existing = $option->connections
            ->filter(static fn (AircoConnection $c): bool => $c->type === AircoConnectionType::Refrigerant)
            ->first(static function (AircoConnection $c) use ($indoor): bool {
                return in_array($indoor->id, [$c->from_placement_id, $c->to_placement_id], true);
            });

        if ($existing instanceof AircoConnection) {
            $existing->update([
                'from_placement_id' => $indoor->id,
                'to_placement_id' => $outdoor->id,
                'label' => sprintf('Koelleiding %s → %s', $indoor->label, $outdoor->label),
            ]);

            return;
        }

        $this->storeConnectionRow($intake, $installer, $option, [
            'type' => AircoConnectionType::Refrigerant,
            'label' => sprintf('Koelleiding %s → %s', $indoor->label, $outdoor->label),
            'from_placement_id' => $indoor->id,
            'to_placement_id' => $outdoor->id,
            'status' => AircoConnectionStatus::Unknown,
        ]);
    }

    private function guardTenant(Intake $intake, User $installer): void
    {
        if ($installer->company_id !== $intake->company_id) {
            throw ValidationException::withMessages([
                'intake' => 'Deze opname hoort bij een ander installatiebedrijf.',
            ]);
        }
    }

    private function guardModel(Intake $intake, Model $model): void
    {
        $modelIntakeId = $model->getAttribute('intake_id');

        if (! is_numeric($modelIntakeId)) {
            throw ValidationException::withMessages(['intake' => 'Ongeldig dossierobject.']);
        }

        if ((int) $modelIntakeId !== $intake->id) {
            throw ValidationException::withMessages(['intake' => 'Dit dossierobject hoort niet bij deze opname.']);
        }
    }

    /** @param array<string, mixed>|null $properties */
    private function activity(
        Intake $intake,
        User $installer,
        string $event,
        ?array $properties,
    ): void {
        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'user',
            'actor_id' => $installer->id,
            'event' => $event,
            'properties' => $properties,
            'created_at' => now(),
        ]);
    }
}
