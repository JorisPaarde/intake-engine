<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\ContributionAudience;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DossierManager
{
    public function initialize(Intake $intake): DossierSubject
    {
        return DB::transaction(function () use ($intake): DossierSubject {
            $root = $this->root($intake);
            $this->syncLegacyEvidence($intake, $root);

            return $root;
        }, 3);
    }

    public function root(Intake $intake): DossierSubject
    {
        return DossierSubject::query()->firstOrCreate(
            [
                'intake_id' => $intake->id,
                'key' => 'survey',
            ],
            [
                'company_id' => $intake->company_id,
                'parent_id' => null,
                'type' => 'survey',
                'label' => 'Technische opname',
                'meta' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function subject(
        Intake $intake,
        string $key,
        string $type,
        string $label,
        ?DossierSubject $parent = null,
        ?array $meta = null,
    ): DossierSubject {
        if ($parent !== null
            && ($parent->intake_id !== $intake->id || $parent->company_id !== $intake->company_id)) {
            throw ValidationException::withMessages([
                'dossier' => 'Het bovenliggende dossieronderdeel hoort niet bij deze opname.',
            ]);
        }

        return DossierSubject::query()->updateOrCreate(
            [
                'intake_id' => $intake->id,
                'key' => $key,
            ],
            [
                'company_id' => $intake->company_id,
                'parent_id' => $parent?->id,
                'type' => $type,
                'label' => $label,
                'meta' => $meta,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<array{type: string, id: int, relationship?: string}>  $evidence
     */
    public function record(
        Intake $intake,
        DossierSubject $subject,
        DossierRecordKind $kind,
        string $key,
        array $value,
        string $actorType,
        ?int $actorId,
        string $sourceType,
        ?int $sourceId,
        string $method,
        ?float $confidence,
        DossierRecordStatus $status,
        array $evidence = [],
    ): DossierRecord {
        return DB::transaction(function () use (
            $intake,
            $subject,
            $kind,
            $key,
            $value,
            $actorType,
            $actorId,
            $sourceType,
            $sourceId,
            $method,
            $confidence,
            $status,
            $evidence,
        ): DossierRecord {
            $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($subject->intake_id !== $lockedIntake->id
                || $subject->company_id !== $lockedIntake->company_id) {
                throw ValidationException::withMessages([
                    'dossier' => 'Dit dossieronderdeel hoort niet bij deze opname.',
                ]);
            }

            $previous = DossierRecord::query()
                ->where('dossier_subject_id', $subject->id)
                ->where('key', $key)
                ->whereIn('status', [
                    DossierRecordStatus::Proposed,
                    DossierRecordStatus::Established,
                    DossierRecordStatus::Conflicted,
                ])
                ->lockForUpdate()
                ->get();

            $record = DossierRecord::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'dossier_subject_id' => $subject->id,
                'kind' => $kind,
                'key' => $key,
                'value' => $value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'method' => $method,
                'confidence' => $confidence,
                'status' => $status,
                'observed_at' => now(),
            ]);

            foreach ($evidence as $item) {
                $this->linkEvidence(
                    $intake,
                    $subject,
                    $item['type'],
                    $item['id'],
                    $record,
                    $item['relationship'] ?? 'supports',
                );
            }

            $previous->each(static function (DossierRecord $old) use ($record): void {
                $old->update([
                    'status' => DossierRecordStatus::Superseded,
                    'superseded_by_id' => $record->id,
                ]);
            });

            return $record;
        }, 3);
    }

    public function linkEvidence(
        Intake $intake,
        DossierSubject $subject,
        string $evidenceType,
        int $evidenceId,
        ?DossierRecord $record = null,
        string $relationship = 'supports',
    ): DossierEvidenceLink {
        if ($subject->intake_id !== $intake->id
            || $subject->company_id !== $intake->company_id
            || ($record !== null && (
                $record->intake_id !== $intake->id
                || $record->company_id !== $intake->company_id
                || $record->dossier_subject_id !== $subject->id
            ))) {
            throw ValidationException::withMessages([
                'dossier' => 'Dit bewijs hoort niet bij dit dossieronderdeel.',
            ]);
        }

        return DossierEvidenceLink::query()->firstOrCreate(
            [
                'dossier_subject_id' => $subject->id,
                'dossier_record_id' => $record?->id,
                'evidence_type' => $evidenceType,
                'evidence_id' => $evidenceId,
            ],
            [
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'relationship' => $relationship,
            ],
        );
    }

    public function syncLegacyEvidence(Intake $intake, ?DossierSubject $root = null): void
    {
        $root ??= $this->root($intake);
        $intake->loadMissing([
            'answers',
            'externalFacts',
            'uploads',
            'followUpRounds.items',
        ]);

        $roomSubjects = $this->syncRooms($intake, $root);

        foreach ($intake->answers as $answer) {
            $subject = $this->subjectForInstance($answer->section_instance_key, $roomSubjects) ?? $root;
            $record = DossierRecord::query()->updateOrCreate(
                [
                    'intake_id' => $intake->id,
                    'source_type' => 'intake_answer',
                    'source_id' => $answer->id,
                ],
                [
                    'company_id' => $intake->company_id,
                    'dossier_subject_id' => $subject->id,
                    'kind' => DossierRecordKind::Observation,
                    'key' => $this->answerRecordKey($answer),
                    'value' => $answer->value ?? [],
                    'actor_type' => $answer->prefill_source === null ? 'customer' : $answer->prefill_source,
                    'actor_id' => null,
                    'method' => $answer->prefill_source === null ? 'customer_input' : 'automatic_prefill',
                    'confidence' => $answer->prefill_source === 'ai' ? 0.9 : 1.0,
                    'status' => $answer->prefill_source === 'ai'
                        ? DossierRecordStatus::Proposed
                        : DossierRecordStatus::Established,
                    'observed_at' => $answer->answered_at ?? $answer->updated_at,
                    'superseded_by_id' => null,
                ],
            );
            $this->linkEvidence($intake, $subject, 'intake_answer', $answer->id, $record);
        }

        foreach ($intake->externalFacts as $fact) {
            $confidence = match ($fact->confidence) {
                'high' => 0.99,
                'medium' => 0.75,
                default => 0.5,
            };
            $record = DossierRecord::query()->updateOrCreate(
                [
                    'intake_id' => $intake->id,
                    'source_type' => 'intake_external_fact',
                    'source_id' => $fact->id,
                ],
                [
                    'company_id' => $intake->company_id,
                    'dossier_subject_id' => $root->id,
                    'kind' => DossierRecordKind::Observation,
                    'key' => 'external.'.$fact->fact_key,
                    'value' => [
                        'label' => $fact->label,
                        'value' => $fact->value,
                        'source' => $fact->source,
                        'source_reference' => $fact->source_reference,
                        'captured_at' => $fact->captured_at->toIso8601String(),
                    ],
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'method' => 'external_source',
                    'confidence' => $confidence,
                    'status' => $fact->confidence === 'high'
                        ? DossierRecordStatus::Established
                        : DossierRecordStatus::Proposed,
                    'observed_at' => $fact->captured_at ?? $fact->updated_at,
                    'superseded_by_id' => null,
                ],
            );
            $this->linkEvidence($intake, $root, 'intake_external_fact', $fact->id, $record);
        }

        foreach ($intake->uploads as $upload) {
            $subject = $this->subjectForUpload($intake, $upload, $roomSubjects) ?? $root;
            $this->linkEvidence($intake, $subject, 'intake_upload', $upload->id);
        }

        foreach ($intake->followUpRounds as $round) {
            foreach ($round->items as $item) {
                $existingTask = ContributionTask::query()
                    ->where('intake_follow_up_item_id', $item->id)
                    ->first();
                $subject = $existingTask->subject ?? $root;
                $task = ContributionTask::query()->updateOrCreate(
                    ['intake_follow_up_item_id' => $item->id],
                    [
                        'intake_id' => $intake->id,
                        'company_id' => $intake->company_id,
                        'dossier_subject_id' => $subject->id,
                        'audience' => ContributionAudience::Customer,
                        'type' => $item->type,
                        'prompt' => $item->prompt,
                        'decision_area_key' => $existingTask?->decision_area_key,
                        'status' => $item->answered_at === null
                            ? ContributionTaskStatus::Open
                            : ContributionTaskStatus::Completed,
                        'requested_by' => $round->requested_by,
                        'completed_by_type' => $item->answered_at === null ? null : 'customer',
                        'completed_by_id' => null,
                        'completed_at' => $item->answered_at,
                        'meta' => array_merge($existingTask->meta ?? [], [
                            'round_number' => $round->round_number,
                        ]),
                    ],
                );

                $record = null;
                if ($item->answered_at !== null) {
                    $record = DossierRecord::query()->updateOrCreate(
                        [
                            'intake_id' => $intake->id,
                            'source_type' => 'intake_follow_up_item',
                            'source_id' => $item->id,
                        ],
                        [
                            'company_id' => $intake->company_id,
                            'dossier_subject_id' => $subject->id,
                            'kind' => DossierRecordKind::Observation,
                            'key' => 'customer_contribution.'.$item->id,
                            'value' => [
                                'prompt' => $item->prompt,
                                'response_text' => $item->response_text,
                                'upload_ids' => $item->uploads()->pluck('id')->map(
                                    static fn (mixed $id): int => (int) $id,
                                )->all(),
                            ],
                            'actor_type' => 'customer',
                            'actor_id' => null,
                            'method' => 'targeted_customer_task',
                            'confidence' => 1.0,
                            'status' => DossierRecordStatus::Established,
                            'observed_at' => $item->answered_at,
                            'superseded_by_id' => null,
                        ],
                    );
                }

                $this->linkEvidence($intake, $subject, 'intake_follow_up_item', $item->id, $record);

                if ($task->status === ContributionTaskStatus::Completed) {
                    $task->update(['completed_at' => $item->answered_at ?? $round->completed_at]);
                }
            }
        }
    }

    /**
     * @return array<string, DossierSubject>
     */
    private function syncRooms(Intake $intake, DossierSubject $root): array
    {
        $instanceKeys = $intake->answers
            ->pluck('section_instance_key')
            ->merge($intake->uploads->pluck('section_instance_key'))
            ->filter(static fn (mixed $key): bool => is_string($key) && preg_match('/^room-\d+$/', $key) === 1)
            ->unique()
            ->sort(SORT_NATURAL)
            ->values();
        $subjects = [];

        foreach ($instanceKeys as $index => $instanceKey) {
            $typeAnswer = $intake->answers->first(
                static fn (IntakeAnswer $answer): bool => $answer->section_instance_key === $instanceKey
                    && $answer->question_key === 'room_type',
            );
            $useType = is_array($typeAnswer?->value) ? ($typeAnswer->value['value'] ?? null) : null;
            $name = $this->roomLabel(is_string($useType) ? $useType : null, $index + 1);
            $subject = $this->subject(
                $intake,
                'airco.room.'.$instanceKey,
                'airco_room',
                $name,
                $root,
                ['legacy_section_instance_key' => $instanceKey],
            );
            $subjects[$instanceKey] = $subject;

            AircoRoom::query()->updateOrCreate(
                [
                    'intake_id' => $intake->id,
                    'key' => $instanceKey,
                ],
                [
                    'company_id' => $intake->company_id,
                    'dossier_subject_id' => $subject->id,
                    'name' => $name,
                    'use_type' => is_string($useType) ? $useType : null,
                    'sort_order' => $index + 1,
                    'status' => 'desired',
                    'source_type' => 'template_bridge',
                    'source_id' => $typeAnswer?->id,
                    'dimensions' => $this->roomDimensions($intake, $instanceKey),
                ],
            );
        }

        return $subjects;
    }

    /**
     * @param  array<string, DossierSubject>  $subjects
     */
    private function subjectForInstance(?string $instanceKey, array $subjects): ?DossierSubject
    {
        return $instanceKey === null ? null : ($subjects[$instanceKey] ?? null);
    }

    /**
     * @param  array<string, DossierSubject>  $roomSubjects
     */
    private function subjectForUpload(
        Intake $intake,
        IntakeUpload $upload,
        array $roomSubjects,
    ): ?DossierSubject {
        $roomSubject = $this->subjectForInstance($upload->section_instance_key, $roomSubjects);

        if ($roomSubject !== null) {
            return $roomSubject;
        }

        if (! is_string($upload->section_instance_key)
            || preg_match('/^subject-(\d+)$/', $upload->section_instance_key, $matches) !== 1) {
            return null;
        }

        return DossierSubject::query()
            ->where('intake_id', $intake->id)
            ->where('company_id', $intake->company_id)
            ->find((int) $matches[1]);
    }

    /** @return array<string, float> */
    private function roomDimensions(Intake $intake, string $instanceKey): array
    {
        $mapping = [
            'room_length_m' => 'length_m',
            'room_width_m' => 'width_m',
            'ceiling_height_m' => 'height_m',
        ];
        $dimensions = [];

        foreach ($mapping as $questionKey => $dimensionKey) {
            $answer = $intake->answers->first(
                static fn (IntakeAnswer $answer): bool => $answer->section_instance_key === $instanceKey
                    && $answer->question_key === $questionKey,
            );
            $number = is_array($answer?->value) ? ($answer->value['number'] ?? null) : null;

            if (is_numeric($number)) {
                $dimensions[$dimensionKey] = (float) $number;
            }
        }

        return $dimensions;
    }

    private function roomLabel(?string $useType, int $index): string
    {
        $base = match ($useType) {
            'living_room' => 'Woonkamer',
            'bedroom' => 'Slaapkamer',
            'office' => 'Werkkamer',
            'attic' => 'Zolder',
            default => 'Ruimte',
        };

        return $base.' '.$index;
    }

    private function answerRecordKey(IntakeAnswer $answer): string
    {
        return implode('.', array_filter([
            'answer',
            $answer->section_instance_key,
            $answer->question_key,
        ], static fn (?string $value): bool => is_string($value) && $value !== ''));
    }
}
