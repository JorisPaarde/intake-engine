<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Enums\AircoOptionStatus;
use App\Enums\DossierRecordStatus;
use App\Enums\IntakeStatus;

/**
 * N69: the current dossier state (rooms + installer scope decisions, installation
 * options, installer review, live dossier records) that the report's AI proposal
 * is built from, so it matches what the survey assistant shows. The hash of this
 * context is stored with the summary; a mismatch means the proposal is stale.
 */
final class SummaryDossierContext
{
    private const MAX_RECORDS = 60;

    /** @return array<string, mixed> */
    public function build(Intake $intake): array
    {
        $intake->load([
            'aircoRooms',
            'aircoInstallationOptions',
            'review',
            'dossierSubjects.records',
        ]);

        $rooms = $intake->aircoRooms
            ->sortBy(static fn (AircoRoom $room): int => (int) $room->sort_order)
            ->map(static fn (AircoRoom $room): array => [
                'name' => $room->name,
                'existing_installation_remains_confirmed_by_installer' => $room->isExistingInstallationConfirmed(),
            ])
            ->values()
            ->all();

        $options = $intake->aircoInstallationOptions
            ->reject(static fn ($option): bool => $option->status === AircoOptionStatus::Rejected)
            ->sortBy(static fn ($option): int => (int) $option->rank)
            ->map(static fn ($option): array => [
                'label' => $option->label,
                'status' => $option->status->value,
                'summary' => $option->summary,
            ])
            ->values()
            ->all();

        $records = [];
        foreach ($intake->dossierSubjects as $subject) {
            foreach ($subject->records as $record) {
                if ($record->status === DossierRecordStatus::Superseded) {
                    continue;
                }
                $records[] = [
                    'subject' => $subject->key,
                    'key' => $record->key,
                    'value' => $record->value,
                    'status' => $record->status->value,
                    'source' => $record->source_type,
                ];
            }
        }
        usort($records, static fn (array $a, array $b): int => strcmp(
            json_encode($a) ?: '',
            json_encode($b) ?: '',
        ));

        return [
            'rooms' => $rooms,
            'installation_options' => $options,
            'installer_review' => $intake->review === null ? null : [
                'decision' => $intake->review->decision->value,
                'summary' => $intake->review->summary,
            ],
            'dossier_records' => array_slice($records, 0, self::MAX_RECORDS),
        ];
    }

    /** @param  array<string, mixed>|null  $context */
    public function hash(Intake $intake, ?array $context = null): string
    {
        return hash('sha256', (string) json_encode($context ?? $this->build($intake), JSON_THROW_ON_ERROR));
    }

    /**
     * Queue a new summary when the stored one was built on an older dossier state.
     * Returns true when a job was dispatched.
     */
    public function dispatchIfStale(Intake $intake): bool
    {
        if (! in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)) {
            return false;
        }

        $intake->loadMissing('report');
        $meta = $intake->report?->meta;
        if (! is_array($meta) || ! is_array($meta['ai_summary'] ?? null)) {
            return false;
        }

        if (($meta['ai_dossier_hash'] ?? null) === $this->hash($intake)) {
            return false;
        }

        SummarizeIntakeJob::dispatch($intake->id);

        return true;
    }
}
