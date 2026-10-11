<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Removing a hoofdwizard photo happens in two steps (BL-147):
 * softRemove() hides it right away (soft delete, file and assessment stay),
 * restore() undoes that (including snapshot derived answers/facts), and finalize()
 * does the real wipe after the undo window or on navigation.
 * handle() keeps the immediate delete for “Vervang foto”.
 * A soft-deleted upload is invisible to every query (report, AI, workspace).
 * Closing the tab within the undo window leaves it in that “bin”; it is purged on
 * the next visit of the customer link (purgePendingFor) or by the hourly cleanup.
 */
final class DeleteIntakeUpload
{
    public function __construct(
        private readonly ProgressCalculator $progressCalculator,
    ) {}

    public function handle(Intake $intake, IntakeUpload $upload): void
    {
        $this->softRemove($intake, $upload);
        $this->finalize($intake, $upload->id);
    }

    /**
     * @return array{answers: list<array<string, mixed>>, facts: list<array<string, mixed>>}
     */
    public function snapshotDerivedState(Intake $intake, IntakeUpload $upload): array
    {
        $this->guard($intake, $upload);

        $sectionInstanceKey = $upload->section_instance_key;
        $answerQuery = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->whereIn('prefill_source', $this->derivedPrefillSources());

        if ($sectionInstanceKey === null) {
            $answerQuery->whereNull('section_instance_key');
        } else {
            $answerQuery->where('section_instance_key', $sectionInstanceKey);
        }

        /** @var list<array<string, mixed>> $answers */
        $answers = [];
        foreach ($answerQuery->get() as $answer) {
            $answeredAt = $answer->getAttribute('answered_at');
            $answers[] = [
                'question_key' => $answer->question_key,
                'section_instance_key' => $answer->section_instance_key,
                'value' => $answer->value,
                'prefill_source' => $answer->prefill_source,
                'fact_provenance' => $answer->fact_provenance,
                'fact_confidence' => $answer->fact_confidence,
                'fact_evidence' => $answer->fact_evidence,
                'fact_source' => $answer->fact_source,
                'answered_at' => $answeredAt instanceof CarbonInterface
                    ? $answeredAt->toIso8601String()
                    : null,
            ];
        }

        $factKey = $sectionInstanceKey === null
            ? $upload->question_key.'_derivation'
            : $upload->question_key.'_derivation::'.$sectionInstanceKey;

        /** @var list<array<string, mixed>> $facts */
        $facts = [];
        $factRows = IntakeExternalFact::query()
            ->where('intake_id', $intake->id)
            ->whereIn('fact_key', [$factKey, 'fusebox_photo_assessment'])
            ->get();
        foreach ($factRows as $fact) {
            $capturedAt = $fact->getAttribute('captured_at');
            $facts[] = [
                'fact_key' => $fact->fact_key,
                'label' => $fact->label,
                'value' => $fact->value,
                'source' => $fact->source,
                'source_reference' => $fact->source_reference,
                'source_url' => $fact->source_url,
                'confidence' => $fact->confidence,
                'captured_at' => $capturedAt instanceof CarbonInterface
                    ? $capturedAt->toIso8601String()
                    : null,
            ];
        }

        return [
            'answers' => $answers,
            'facts' => $facts,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreDerivedState(Intake $intake, array $snapshot): void
    {
        $answers = is_array($snapshot['answers'] ?? null) ? $snapshot['answers'] : [];
        $facts = is_array($snapshot['facts'] ?? null) ? $snapshot['facts'] : [];

        DB::transaction(function () use ($intake, $answers, $facts): void {
            foreach ($answers as $row) {
                if (! is_array($row) || ! is_string($row['question_key'] ?? null)) {
                    continue;
                }

                $sectionKey = isset($row['section_instance_key']) && is_string($row['section_instance_key'])
                    ? $row['section_instance_key']
                    : null;
                $query = IntakeAnswer::query()
                    ->where('intake_id', $intake->id)
                    ->where('question_key', $row['question_key']);
                $sectionKey === null
                    ? $query->whereNull('section_instance_key')
                    : $query->where('section_instance_key', $sectionKey);

                $answeredAt = isset($row['answered_at']) && is_string($row['answered_at'])
                    ? Carbon::parse($row['answered_at'])
                    : now();

                $attributes = [
                    'value' => is_array($row['value'] ?? null) ? $row['value'] : null,
                    'prefill_source' => isset($row['prefill_source']) && is_string($row['prefill_source'])
                        ? $row['prefill_source']
                        : null,
                    'fact_provenance' => isset($row['fact_provenance']) && is_string($row['fact_provenance'])
                        ? $row['fact_provenance']
                        : null,
                    'fact_confidence' => isset($row['fact_confidence']) && is_numeric($row['fact_confidence'])
                        ? (int) $row['fact_confidence']
                        : null,
                    'fact_evidence' => isset($row['fact_evidence']) && is_string($row['fact_evidence'])
                        ? $row['fact_evidence']
                        : null,
                    'fact_source' => isset($row['fact_source']) && is_string($row['fact_source'])
                        ? $row['fact_source']
                        : null,
                    'answered_at' => $answeredAt,
                ];

                $existing = $query->first();
                if ($existing instanceof IntakeAnswer) {
                    $existing->update($attributes);
                } else {
                    IntakeAnswer::query()->create([
                        'intake_id' => $intake->id,
                        'question_key' => $row['question_key'],
                        'section_instance_key' => $sectionKey,
                        ...$attributes,
                    ]);
                }
            }

            foreach ($facts as $row) {
                if (! is_array($row) || ! is_string($row['fact_key'] ?? null)) {
                    continue;
                }

                $capturedAt = isset($row['captured_at']) && is_string($row['captured_at'])
                    ? Carbon::parse($row['captured_at'])
                    : now();

                $attributes = [
                    'label' => isset($row['label']) && is_string($row['label']) ? $row['label'] : $row['fact_key'],
                    'value' => is_array($row['value'] ?? null) ? $row['value'] : [],
                    'source' => isset($row['source']) && is_string($row['source']) ? $row['source'] : 'AI-fotoanalyse',
                    'source_reference' => isset($row['source_reference']) && is_string($row['source_reference'])
                        ? $row['source_reference']
                        : null,
                    'source_url' => isset($row['source_url']) && is_string($row['source_url'])
                        ? $row['source_url']
                        : null,
                    'confidence' => isset($row['confidence']) && is_string($row['confidence'])
                        ? $row['confidence']
                        : 'medium',
                    'captured_at' => $capturedAt,
                ];

                $source = isset($row['source']) && is_string($row['source']) ? $row['source'] : 'AI-fotoanalyse';
                $existing = IntakeExternalFact::query()
                    ->where('intake_id', $intake->id)
                    ->where('fact_key', $row['fact_key'])
                    ->where('source', $source)
                    ->first();

                if ($existing instanceof IntakeExternalFact) {
                    $existing->update($attributes);
                } else {
                    IntakeExternalFact::query()->create([
                        'intake_id' => $intake->id,
                        'fact_key' => $row['fact_key'],
                        ...$attributes,
                    ]);
                }
            }
        }, 3);

        $intake->unsetRelation('answers');
        $intake->unsetRelation('externalFacts');
    }

    public function softRemove(Intake $intake, IntakeUpload $upload): void
    {
        $this->guard($intake, $upload);

        DB::transaction(function () use ($intake, $upload): void {
            [$lockedIntake, $lockedUpload] = $this->lock($intake, $upload->id);

            $questionKey = $lockedUpload->question_key;
            $sectionInstanceKey = $lockedUpload->section_instance_key;
            $lockedUpload->delete();
            $this->syncAnswerUploadIds($lockedIntake, $questionKey, $sectionInstanceKey);
            $this->touchProgress($lockedIntake);
        }, 3);
    }

    public function restore(Intake $intake, int $uploadId): void
    {
        $upload = IntakeUpload::withTrashed()->whereKey($uploadId)->firstOrFail();
        $this->guard($intake, $upload);

        DB::transaction(function () use ($intake, $uploadId): void {
            [$lockedIntake, $lockedUpload] = $this->lock($intake, $uploadId);

            if ($lockedUpload->purged_at !== null) {
                throw ValidationException::withMessages([
                    'photo' => 'Ongedaan maken mislukt.',
                ]);
            }

            if ($lockedUpload->trashed()) {
                $lockedUpload->restore();
            }

            $this->syncAnswerUploadIds(
                $lockedIntake,
                $lockedUpload->question_key,
                $lockedUpload->section_instance_key,
            );
            $this->touchProgress($lockedIntake);
        }, 3);
    }

    public function finalize(Intake $intake, int $uploadId): void
    {
        $this->purge($uploadId, $intake->id, 'customer');
    }

    public function purgePendingFor(Intake $intake): int
    {
        $ids = IntakeUpload::onlyTrashed()
            ->where('intake_id', $intake->id)
            ->whereNull('intake_follow_up_item_id')
            ->whereNull('purged_at')
            ->pluck('id');

        foreach ($ids as $id) {
            $this->purge((int) $id, $intake->id, 'customer');
        }

        return $ids->count();
    }

    public function purgeRemovedBefore(CarbonInterface $cutoff): int
    {
        $purged = 0;

        IntakeUpload::onlyTrashed()
            ->whereNull('intake_follow_up_item_id')
            ->whereNull('purged_at')
            ->where('deleted_at', '<=', $cutoff)
            ->select(['id'])
            ->chunkById(100, function ($uploads) use (&$purged): void {
                foreach ($uploads as $upload) {
                    $this->purge((int) $upload->id, null, 'system');
                    $purged++;
                }
            });

        return $purged;
    }

    private function purge(int $uploadId, ?int $intakeId, string $actorType): void
    {
        $result = DB::transaction(function () use ($uploadId, $intakeId, $actorType): ?array {
            $lockedUpload = IntakeUpload::withTrashed()->whereKey($uploadId)->lockForUpdate()->first();

            if ($lockedUpload === null
                || ! $lockedUpload->trashed()
                || $lockedUpload->purged_at !== null
                || $lockedUpload->intake_follow_up_item_id !== null
                || ($intakeId !== null && $lockedUpload->intake_id !== $intakeId)) {
                return null;
            }

            DossierEvidenceLink::query()
                ->where('intake_id', $lockedUpload->intake_id)
                ->where('evidence_type', 'intake_upload')
                ->where('evidence_id', $lockedUpload->id)
                ->delete();

            $lockedUpload->forceFill(['purged_at' => now()])->saveQuietly();

            IntakeActivityEvent::query()->create([
                'intake_id' => $lockedUpload->intake_id,
                'actor_type' => $actorType,
                'actor_id' => null,
                'event' => 'upload_deleted',
                'properties' => [
                    'upload_id' => $lockedUpload->id,
                    'question_key' => $lockedUpload->question_key,
                ],
                'created_at' => now(),
            ]);

            return [$lockedUpload->disk, $lockedUpload->path, $lockedUpload->analysis_path];
        }, 3);

        if ($result === null) {
            return;
        }

        [$disk, $path, $analysisPath] = $result;
        $this->deleteStoredMedia($disk, $path);

        if (is_string($analysisPath) && $analysisPath !== '') {
            $this->deleteStoredMedia($disk, $analysisPath);
        }
    }

    private function guard(Intake $intake, IntakeUpload $upload): void
    {
        if ($upload->intake_id !== $intake->id || $upload->intake_follow_up_item_id !== null) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto hoort niet bij deze opname.',
            ]);
        }

        if (! in_array($intake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto kan niet meer worden verwijderd.',
            ]);
        }
    }

    /**
     * @return array{0: Intake, 1: IntakeUpload}
     */
    private function lock(Intake $intake, int $uploadId): array
    {
        $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
        $lockedUpload = IntakeUpload::withTrashed()->whereKey($uploadId)->lockForUpdate()->firstOrFail();

        if (! in_array($lockedIntake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)
            || $lockedUpload->intake_id !== $lockedIntake->id
            || $lockedUpload->intake_follow_up_item_id !== null) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto kan niet meer worden verwijderd.',
            ]);
        }

        return [$lockedIntake, $lockedUpload];
    }

    private function deleteStoredMedia(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
            // Retry asynchronously after the database mutation has committed.
        }

        DeleteStoredMediaJob::dispatch($disk, $path);
    }

    private function syncAnswerUploadIds(Intake $intake, string $questionKey, ?string $sectionInstanceKey): void
    {
        $query = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey);

        if ($sectionInstanceKey === null) {
            $query->whereNull('section_instance_key');
        } else {
            $query->where('section_instance_key', $sectionInstanceKey);
        }

        $ids = $query->orderBy('sort_order')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $answerQuery = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey);

        if ($sectionInstanceKey === null) {
            $answerQuery->whereNull('section_instance_key');
        } else {
            $answerQuery->where('section_instance_key', $sectionInstanceKey);
        }

        $answer = $answerQuery->first();

        if ($answer === null) {
            return;
        }

        $answer->update([
            'value' => ['upload_ids' => $ids],
            'answered_at' => now(),
        ]);
    }

    private function touchProgress(Intake $intake): void
    {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
        $progress = $this->progressCalculator->calculate($intake, $version);

        $intake->update([
            'progress_percent' => $progress['percent'],
        ]);
    }

    /** @return list<string> */
    private function derivedPrefillSources(): array
    {
        return array_values(array_unique([
            ...PrefillSources::photoInvalidationSources(),
            PrefillSources::AI_LEGACY,
        ]));
    }
}
