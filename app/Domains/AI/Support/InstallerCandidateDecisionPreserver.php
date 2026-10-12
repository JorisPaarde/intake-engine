<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoOptionFeasibility;
use App\Enums\AircoOptionStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use Illuminate\Support\Collection;

/**
 * Preserves installer decisions on AI Candidate options across dossier rebuilds.
 *
 * Matching uses structured identity only (configuration type + placement ids;
 * connection type + from/to placement ids) — never label text (ADR-0013/0016).
 * An explicit installer decision always wins over a new AI status.
 */
final class InstallerCandidateDecisionPreserver
{
    public const META_PROPOSAL_UPDATED = 'proposal_updated_after_installer_decision';

    public const NOTICE_TEXT = 'Je besluit blijft staan. Het voorstel is daarna bijgewerkt.';

    /** @var list<string> */
    private const INSTALLER_NOTE_METHODS = [
        'installer_note',
        'installer_confirmed',
        'installer_adjusted',
        'installer_corrected',
        'on_site',
    ];

    public function __construct(
        private readonly DossierManager $dossierManager,
    ) {}

    /**
     * @param  Collection<int, AircoInstallationOption>  $candidates
     * @return list<array{
     *     option_id: int,
     *     configuration_type: string,
     *     placement_ids: list<int>,
     *     feasibility: string|null,
     *     infeasibility_reason: string|null,
     *     connections: list<array{
     *         connection_id: int,
     *         type: string,
     *         from_placement_id: int|null,
     *         to_placement_id: int|null,
     *         status: string|null,
     *         approved_by: int|null,
     *         approved_at: string|null,
     *         segments: list<string>,
     *         label: string,
     *         notes: list<array{key: string, method: string, text: string, actor_id: int|null}>,
     *         evidence: list<array{evidence_type: string, evidence_id: int, relationship: string}>,
     *         upload_ids: list<int>,
     *         task_ids: list<int>
     *     }>
     * }>
     */
    public function snapshotProtected(Collection $candidates): array
    {
        $snapshots = [];

        foreach ($candidates as $option) {
            $snapshot = $this->snapshotOption($option);
            if ($snapshot !== null) {
                $snapshots[] = $snapshot;
            }
        }

        return $snapshots;
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<int> Matched (and transferred) old option ids — safe to delete.
     */
    public function reapply(Intake $intake, array $snapshots): array
    {
        if ($snapshots === []) {
            return [];
        }

        $oldOptionIds = array_map(
            static fn (array $snapshot): int => (int) $snapshot['option_id'],
            $snapshots,
        );

        // Only match newly created Candidates — never the protected originals still in DB.
        $newOptions = AircoInstallationOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->where('status', AircoOptionStatus::Candidate)
            ->whereNotIn('id', $oldOptionIds)
            ->with(['connections.subject', 'placements'])
            ->get();

        $matchedOptionIds = [];
        $usedNewOptionIds = [];

        foreach ($snapshots as $snapshot) {
            $match = $this->findMatchingOption($newOptions, $snapshot, $usedNewOptionIds);
            if ($match === null) {
                continue;
            }

            $usedNewOptionIds[] = $match->id;
            $matchedOptionIds[] = (int) $snapshot['option_id'];
            $this->applyOptionSnapshot($intake, $match, $snapshot);
        }

        return $matchedOptionIds;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshotOption(AircoInstallationOption $option): ?array
    {
        $option->loadMissing([
            'placements',
            'connections.subject.records',
            'connections.subject.evidenceLinks',
        ]);

        $placementIds = $option->placements
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $connections = [];
        $hasConnectionDecision = false;

        foreach ($option->connections as $connection) {
            $connectionSnap = $this->snapshotConnection($connection);
            $connections[] = $connectionSnap;
            if ($this->connectionHasInstallerDecision($connectionSnap)) {
                $hasConnectionDecision = true;
            }
        }

        $feasibility = $option->feasibility;
        $hasFeasibilityDecision = $feasibility !== AircoOptionFeasibility::Pending;

        if (! $hasFeasibilityDecision && ! $hasConnectionDecision) {
            return null;
        }

        return [
            'option_id' => $option->id,
            'configuration_type' => $option->configuration_type->value,
            'placement_ids' => $placementIds,
            'feasibility' => $hasFeasibilityDecision ? $feasibility->value : null,
            'infeasibility_reason' => $hasFeasibilityDecision
                ? $option->infeasibility_reason
                : null,
            'connections' => $connections,
        ];
    }

    /**
     * @return array{
     *     connection_id: int,
     *     type: string,
     *     from_placement_id: int|null,
     *     to_placement_id: int|null,
     *     status: string|null,
     *     approved_by: int|null,
     *     approved_at: string|null,
     *     segments: list<string>,
     *     label: string,
     *     notes: list<array{key: string, method: string, text: string, actor_id: int|null}>,
     *     evidence: list<array{evidence_type: string, evidence_id: int, relationship: string}>,
     *     upload_ids: list<int>,
     *     task_ids: list<int>
     * }
     */
    private function snapshotConnection(AircoConnection $connection): array
    {
        $status = $connection->status;
        $installerStatus = in_array($status, [
            AircoConnectionStatus::Approved,
            AircoConnectionStatus::NotRemotelyResolvable,
        ], true) ? $status->value : null;

        $notes = [];
        $evidence = [];
        $uploadIds = [];
        $taskIds = [];
        $connection->loadMissing(['subject.records', 'subject.evidenceLinks']);
        $subject = $connection->subject;

        foreach ($subject->records as $record) {
            if ($record->status !== DossierRecordStatus::Established
                || $record->source_type !== 'installer'
                || ! in_array($record->method, self::INSTALLER_NOTE_METHODS, true)) {
                continue;
            }

            $text = $record->value['text'] ?? $record->value['_display_value'] ?? null;
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $notes[] = [
                'key' => $record->key,
                'method' => $record->method,
                'text' => trim($text),
                'actor_id' => $record->actor_id,
            ];
        }

        foreach ($subject->evidenceLinks as $link) {
            $evidence[] = [
                'evidence_type' => $link->evidence_type,
                'evidence_id' => (int) $link->evidence_id,
                'relationship' => $link->relationship,
            ];
            if ($link->evidence_type === 'intake_upload') {
                $uploadIds[] = (int) $link->evidence_id;
            }
        }

        $taskIds = ContributionTask::query()
            ->where('intake_id', $connection->intake_id)
            ->where('dossier_subject_id', $subject->id)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $uploadIds = array_values(array_unique([
            ...$uploadIds,
            ...IntakeUpload::query()
                ->where('intake_id', $connection->intake_id)
                ->where('section_instance_key', 'subject-'.$subject->id)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        ]));

        /** @var list<string> $segments */
        $segments = [];
        foreach (is_array($connection->segments) ? $connection->segments : [] as $segment) {
            if (trim($segment) !== '') {
                $segments[] = $segment;
            }
        }

        return [
            'connection_id' => $connection->id,
            'type' => $connection->type->value,
            'from_placement_id' => $connection->from_placement_id,
            'to_placement_id' => $connection->to_placement_id,
            'status' => $installerStatus,
            'approved_by' => $installerStatus === AircoConnectionStatus::Approved->value
                ? $connection->approved_by
                : null,
            'approved_at' => $installerStatus === AircoConnectionStatus::Approved->value
                && $connection->approved_at !== null
                ? $connection->approved_at->toIso8601String()
                : null,
            'segments' => $segments,
            'label' => $connection->label,
            'notes' => $notes,
            'evidence' => $evidence,
            'upload_ids' => $uploadIds,
            'task_ids' => $taskIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $connectionSnap
     */
    private function connectionHasInstallerDecision(array $connectionSnap): bool
    {
        // Evidence alone is not an installer decision — status/notes are.
        return is_string($connectionSnap['status'] ?? null)
            || ($connectionSnap['notes'] ?? []) !== [];
    }

    /**
     * @param  Collection<int, AircoInstallationOption>  $options
     * @param  array<string, mixed>  $snapshot
     * @param  list<int>  $usedNewOptionIds
     */
    private function findMatchingOption(
        Collection $options,
        array $snapshot,
        array $usedNewOptionIds,
    ): ?AircoInstallationOption {
        $wantedPlacements = array_values(array_map('intval', $snapshot['placement_ids'] ?? []));
        sort($wantedPlacements);
        $wantedType = (string) ($snapshot['configuration_type'] ?? '');

        foreach ($options as $option) {
            if (in_array($option->id, $usedNewOptionIds, true)) {
                continue;
            }

            if ($option->configuration_type->value !== $wantedType) {
                continue;
            }

            $placementIds = $option->placements
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($placementIds === $wantedPlacements) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function applyOptionSnapshot(
        Intake $intake,
        AircoInstallationOption $option,
        array $snapshot,
    ): void {
        if (is_string($snapshot['feasibility'] ?? null)) {
            $feasibility = AircoOptionFeasibility::tryFrom((string) $snapshot['feasibility']);
            if ($feasibility instanceof AircoOptionFeasibility
                && $feasibility !== AircoOptionFeasibility::Pending) {
                $option->update([
                    'feasibility' => $feasibility,
                    'infeasibility_reason' => $feasibility === AircoOptionFeasibility::Infeasible
                        ? (is_string($snapshot['infeasibility_reason'] ?? null)
                            ? $snapshot['infeasibility_reason']
                            : null)
                        : null,
                ]);
            }
        }

        $connections = is_array($snapshot['connections'] ?? null) ? $snapshot['connections'] : [];
        foreach ($connections as $connectionSnap) {
            if (! is_array($connectionSnap)) {
                continue;
            }
            $this->applyConnectionSnapshot($intake, $option, $connectionSnap);
        }
    }

    /**
     * @param  array<string, mixed>  $connectionSnap
     */
    private function applyConnectionSnapshot(
        Intake $intake,
        AircoInstallationOption $option,
        array $connectionSnap,
    ): void {
        $match = $option->connections->first(function (AircoConnection $connection) use ($connectionSnap): bool {
            return $connection->type->value === (string) ($connectionSnap['type'] ?? '')
                && $connection->from_placement_id === ($connectionSnap['from_placement_id'] ?? null)
                && $connection->to_placement_id === ($connectionSnap['to_placement_id'] ?? null);
        });

        if (! $match instanceof AircoConnection) {
            return;
        }

        $match->loadMissing('subject');
        $subject = $match->subject;

        $updates = [];
        $statusValue = is_string($connectionSnap['status'] ?? null) ? $connectionSnap['status'] : null;
        $status = $statusValue !== null ? AircoConnectionStatus::tryFrom($statusValue) : null;
        if ($status instanceof AircoConnectionStatus) {
            $updates['status'] = $status;
            $updates['approved_by'] = $status === AircoConnectionStatus::Approved
                ? ($connectionSnap['approved_by'] ?? null)
                : null;
            $updates['approved_at'] = $status === AircoConnectionStatus::Approved
                && is_string($connectionSnap['approved_at'] ?? null)
                ? $connectionSnap['approved_at']
                : null;
        }

        if ($updates !== []) {
            $match->update($updates);
        }

        // Move the drawn route session before the old connection is cascade-deleted.
        // airco_connection_id is unique — only reassign when the new connection has none.
        $oldConnectionId = is_int($connectionSnap['connection_id'] ?? null)
            ? $connectionSnap['connection_id']
            : null;
        if ($oldConnectionId !== null && $oldConnectionId !== $match->id) {
            $match->loadMissing('routeSession');
            if ($match->routeSession === null) {
                PipeRouteSession::query()
                    ->where('intake_id', $intake->id)
                    ->where('airco_connection_id', $oldConnectionId)
                    ->update(['airco_connection_id' => $match->id]);
            }
        }

        $meta = is_array($subject->meta) ? $subject->meta : [];
        // N46: stable successor map so stale workspace POSTs resolve to the new connection.
        if ($oldConnectionId !== null && $oldConnectionId !== $match->id) {
            $supersedes = is_array($meta['supersedes_connection_ids'] ?? null)
                ? $meta['supersedes_connection_ids']
                : [];
            $supersedes[] = $oldConnectionId;
            $meta['supersedes_connection_ids'] = array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                $supersedes,
            )));
        }

        $proposalUpdated = $this->proposalContentChanged($match, $connectionSnap);
        if ($proposalUpdated) {
            $meta[self::META_PROPOSAL_UPDATED] = true;
        }

        if ($meta !== (is_array($subject->meta) ? $subject->meta : [])) {
            $subject->update(['meta' => $meta]);
        }

        $notes = is_array($connectionSnap['notes'] ?? null) ? $connectionSnap['notes'] : [];
        foreach ($notes as $note) {
            if (! is_array($note)) {
                continue;
            }
            $text = is_string($note['text'] ?? null) ? trim((string) $note['text']) : '';
            $key = is_string($note['key'] ?? null) ? (string) $note['key'] : '';
            $method = is_string($note['method'] ?? null) ? (string) $note['method'] : 'installer_note';
            if ($text === '' || $key === '') {
                continue;
            }

            $this->dossierManager->record(
                intake: $intake,
                subject: $subject,
                kind: DossierRecordKind::Observation,
                key: $key,
                value: ['text' => $text],
                actorType: 'installer',
                actorId: is_int($note['actor_id'] ?? null) ? $note['actor_id'] : null,
                sourceType: 'installer',
                sourceId: is_int($note['actor_id'] ?? null) ? $note['actor_id'] : null,
                method: $method,
                confidence: 1.0,
                status: DossierRecordStatus::Established,
            );
        }

        $evidence = is_array($connectionSnap['evidence'] ?? null) ? $connectionSnap['evidence'] : [];
        foreach ($evidence as $link) {
            if (! is_array($link)) {
                continue;
            }
            $evidenceType = is_string($link['evidence_type'] ?? null) ? (string) $link['evidence_type'] : '';
            $evidenceId = is_int($link['evidence_id'] ?? null) ? $link['evidence_id'] : null;
            if ($evidenceType === '' || $evidenceId === null) {
                continue;
            }

            $this->dossierManager->linkEvidence(
                $intake,
                $subject,
                $evidenceType,
                $evidenceId,
                null,
                is_string($link['relationship'] ?? null) ? (string) $link['relationship'] : 'supports',
            );
        }

        $uploadIds = is_array($connectionSnap['upload_ids'] ?? null) ? $connectionSnap['upload_ids'] : [];
        foreach ($uploadIds as $uploadId) {
            if (! is_int($uploadId)) {
                continue;
            }
            IntakeUpload::query()
                ->where('intake_id', $intake->id)
                ->whereKey($uploadId)
                ->update(['section_instance_key' => 'subject-'.$subject->id]);
        }

        $taskIds = is_array($connectionSnap['task_ids'] ?? null) ? $connectionSnap['task_ids'] : [];
        if ($taskIds !== []) {
            ContributionTask::query()
                ->where('intake_id', $intake->id)
                ->whereIn('id', $taskIds)
                ->update(['dossier_subject_id' => $subject->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $connectionSnap
     */
    private function proposalContentChanged(AircoConnection $connection, array $connectionSnap): bool
    {
        /** @var list<string> $oldSegments */
        $oldSegments = [];
        $rawOld = is_array($connectionSnap['segments'] ?? null) ? $connectionSnap['segments'] : [];
        foreach ($rawOld as $segment) {
            if (is_string($segment) && trim($segment) !== '') {
                $oldSegments[] = $segment;
            }
        }

        /** @var list<string> $newSegments */
        $newSegments = [];
        foreach (is_array($connection->segments) ? $connection->segments : [] as $segment) {
            if (trim($segment) !== '') {
                $newSegments[] = $segment;
            }
        }

        return $oldSegments !== $newSegments;
    }
}
