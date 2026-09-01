<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AircoSurveyService
{
    public function __construct(
        private readonly DossierManager $dossierManager,
        private readonly DecisionReadinessService $decisionReadiness,
        private readonly InstallerSurveyProgress $surveyProgress,
    ) {}

    /**
     * @param  array{name: string, use_type?: string|null, length_m?: float|null, width_m?: float|null, height_m?: float|null}  $data
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
        $dimensions = collect([
            'length_m' => $data['length_m'] ?? null,
            'width_m' => $data['width_m'] ?? null,
            'height_m' => $data['height_m'] ?? null,
        ])->filter(static fn (mixed $value): bool => is_numeric($value))->map(
            static fn (mixed $value): float => (float) $value,
        )->all();

        $room = AircoRoom::query()->create([
            'intake_id' => $intake->id,
            'company_id' => $intake->company_id,
            'dossier_subject_id' => $subject->id,
            'key' => $key,
            'name' => trim($data['name']),
            'use_type' => $data['use_type'] ?? null,
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
     * @param  array{name: string, use_type?: string|null, length_m?: float|null, width_m?: float|null, height_m?: float|null}  $data
     */
    public function updateRoom(Intake $intake, User $installer, AircoRoom $room, array $data): AircoRoom
    {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $room);

        $dimensions = collect([
            'length_m' => $data['length_m'] ?? null,
            'width_m' => $data['width_m'] ?? null,
            'height_m' => $data['height_m'] ?? null,
        ])->filter(static fn (mixed $value): bool => is_numeric($value))->map(
            static fn (mixed $value): float => (float) $value,
        )->all();

        $name = trim($data['name']);
        $room->update([
            'name' => $name,
            'use_type' => array_key_exists('use_type', $data) ? $data['use_type'] : $room->use_type,
            'dimensions' => $dimensions,
        ]);

        DossierSubject::query()
            ->whereKey($room->dossier_subject_id)
            ->where('intake_id', $intake->id)
            ->update(['label' => $name]);

        $this->activity($intake, $installer, 'airco_room_updated', ['room_id' => $room->id]);
        $this->surveyProgress->markStarted($intake);
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        return $room->fresh() ?? $room;
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
        $room = isset($data['airco_room_id'])
            ? AircoRoom::query()->where('intake_id', $intake->id)->findOrFail($data['airco_room_id'])
            : null;
        $type = $data['type'] instanceof AircoPlacementType
            ? $data['type']
            : AircoPlacementType::from((string) $data['type']);
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

        $roomId = $data['airco_room_id'] ?? null;
        $room = is_numeric($roomId)
            ? AircoRoom::query()->where('intake_id', $intake->id)->findOrFail((int) $roomId)
            : null;
        $type = $data['type'] instanceof AircoPlacementType
            ? $data['type']
            : AircoPlacementType::from((string) $data['type']);
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
     *     placement_ids: list<int>
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

        $option = DB::transaction(function () use ($intake, $installer, $data, $placements, $configuration): AircoInstallationOption {
            $option = AircoInstallationOption::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'label' => trim($data['label']),
                'configuration_type' => $configuration,
                'rank' => ((int) $intake->aircoInstallationOptions()->max('rank')) + 1,
                'status' => AircoOptionStatus::Candidate,
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

            return $option->load('placements');
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

    public function selectInstallationOption(
        Intake $intake,
        User $installer,
        AircoInstallationOption $option,
    ): AircoInstallationOption {
        $this->guardTenant($intake, $installer);
        $this->guardModel($intake, $option);

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
        $optionPlacementIds = $option->placements()->pluck('airco_placement_options.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ([$from, $to] as $placement) {
            if ($placement !== null && ! in_array($placement->id, $optionPlacementIds, true)) {
                throw ValidationException::withMessages([
                    'from_placement_id' => 'Iedere unit in de route moet onderdeel zijn van deze keuze.',
                ]);
            }
        }

        $root = $this->dossierManager->root($intake);
        $subject = $this->dossierManager->subject(
            $intake,
            'airco.connection.'.Str::lower(Str::ulid()->toBase32()),
            'airco_connection',
            trim($data['label']),
            $root,
            ['connection_type' => $type->value, 'installation_option_id' => $option->id],
        );

        $connection = AircoConnection::query()->create([
            'intake_id' => $intake->id,
            'company_id' => $intake->company_id,
            'airco_installation_option_id' => $option->id,
            'from_placement_id' => $from?->id,
            'to_placement_id' => $to?->id,
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
