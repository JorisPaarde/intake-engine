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
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\FactAcceptance;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\RoomAreaAcceptance;
use App\Domains\Intake\Support\RoomLabelResolver;
use App\Domains\Intake\Support\TechnicalProposalCopy;
use App\Enums\ContributionAudience;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\QuestionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $questionIndex = $this->questionIndex($intake);

        foreach ($intake->answers as $answer) {
            $subject = $this->subjectForInstance($answer->section_instance_key, $roomSubjects) ?? $root;
            $question = $questionIndex[$answer->question_key] ?? null;
            $provenance = FactProvenance::tryFromMixed($answer->fact_provenance);
            $factSource = FactSource::tryFrom((string) ($answer->fact_source ?? ''))
                ?? FactAcceptance::sourceFrom($answer->prefill_source, $provenance);
            $confidencePercent = is_int($answer->fact_confidence)
                ? $answer->fact_confidence
                : FactAcceptance::normalizeConfidence($answer->fact_confidence);
            $isAssumption = PrefillSources::needsCustomerConfirmation(
                $answer->prefill_source,
                $provenance,
                $answer->question_key,
            ) || FactAcceptance::needsConfirmation(
                $confidencePercent,
                $factSource,
                $provenance,
                $answer->question_key,
            );

            $recordKey = $this->answerRecordKey($answer);
            $existingAnswerRecord = DossierRecord::query()
                ->where('intake_id', $intake->id)
                ->where('source_type', 'intake_answer')
                ->where('source_id', $answer->id)
                ->first();

            // Do not revive records that a later correction already superseded
            // (legacy bridge used to reset superseded_by_id to null every sync).
            if ($existingAnswerRecord?->status === DossierRecordStatus::Superseded
                && $existingAnswerRecord->superseded_by_id !== null) {
                $this->linkEvidence($intake, $subject, 'intake_answer', $answer->id, $existingAnswerRecord);

                continue;
            }

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
                    'key' => $recordKey,
                    'value' => $this->answerRecordValue(
                        $intake,
                        $answer,
                        $question,
                        $provenance,
                        $isAssumption,
                        $confidencePercent,
                        $factSource,
                    ),
                    'actor_type' => $answer->prefill_source === null ? 'customer' : $answer->prefill_source,
                    'actor_id' => null,
                    'method' => $this->answerRecordMethod($answer, $isAssumption),
                    'confidence' => $this->prefillConfidence($intake, $answer, $confidencePercent),
                    'status' => $this->prefillStatus($intake, $answer, $isAssumption),
                    'observed_at' => $answer->answered_at ?? $answer->updated_at,
                    'superseded_by_id' => $existingAnswerRecord?->superseded_by_id,
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
                // Keep an intentional null subject (area-level ask). Only default brand-new
                // follow-up items without a task row to the survey root.
                $dossierSubjectId = $existingTask !== null
                    ? $existingTask->dossier_subject_id
                    : $root->id;
                $subject = $dossierSubjectId !== null
                    ? (DossierSubject::query()
                        ->where('intake_id', $intake->id)
                        ->whereKey($dossierSubjectId)
                        ->first() ?? $root)
                    : $root;

                // Photo items answered when the round completed with uploads, even if
                // older code left answered_at null.
                $answeredAt = $item->answered_at
                    ?? ($item->type === FollowUpItemType::Photo
                        && $round->completed_at !== null
                        && $item->uploads()->exists()
                        ? $round->completed_at
                        : null);

                $task = ContributionTask::query()->updateOrCreate(
                    ['intake_follow_up_item_id' => $item->id],
                    [
                        'intake_id' => $intake->id,
                        'company_id' => $intake->company_id,
                        'dossier_subject_id' => $dossierSubjectId,
                        'audience' => ContributionAudience::Customer,
                        'type' => $item->type,
                        'prompt' => $item->prompt,
                        'decision_area_key' => $existingTask?->decision_area_key,
                        'status' => $answeredAt === null
                            ? ContributionTaskStatus::Open
                            : ContributionTaskStatus::Completed,
                        'requested_by' => $round->requested_by,
                        'completed_by_type' => $answeredAt === null ? null : 'customer',
                        'completed_by_id' => null,
                        'completed_at' => $answeredAt,
                        'meta' => array_merge(
                            ($existingTask !== null && is_array($existingTask->meta)) ? $existingTask->meta : [],
                            ['round_number' => $round->round_number],
                        ),
                    ],
                );

                $record = null;
                if ($answeredAt !== null) {
                    $taskMeta = is_array($task->meta) ? $task->meta : [];
                    $value = [
                        'prompt' => $item->prompt,
                        'response_text' => $item->response_text,
                        'upload_ids' => $item->uploads()->pluck('id')->map(
                            static fn (mixed $id): int => (int) $id,
                        )->all(),
                    ];

                    if (($taskMeta['kind'] ?? null) === InstallationOptionPreferenceService::META_KIND) {
                        $preferenceService = app(InstallationOptionPreferenceService::class);
                        $preferredOptionId = $preferenceService->parsePreferredOptionId($item->response_text);
                        $value['preference_kind'] = InstallationOptionPreferenceService::META_KIND;
                        $value['preferred_option_id'] = $preferredOptionId;
                        $value['no_preference'] = $preferenceService->isNoPreference($item->response_text);
                        $value['feasible_fingerprint'] = $taskMeta['feasible_fingerprint'] ?? null;
                        $value['stale'] = (bool) ($taskMeta['stale'] ?? false);
                        $value['auto_selected'] = false;
                    }

                    // Stable unique key per follow-up item (never reuse another item's id).
                    $recordKey = ($taskMeta['kind'] ?? null) === InstallationOptionPreferenceService::META_KIND
                        ? 'customer_installation_preference'
                        : 'customer_contribution.'.$item->id;

                    $existingRecord = DossierRecord::query()
                        ->where('intake_id', $intake->id)
                        ->where('source_type', 'intake_follow_up_item')
                        ->where('source_id', $item->id)
                        ->first();

                    // Preserve an intentional supersession (installer correction / later record).
                    $preserveSupersededBy = $existingRecord?->superseded_by_id;
                    $preserveStatus = $existingRecord?->status === DossierRecordStatus::Superseded
                        ? DossierRecordStatus::Superseded
                        : DossierRecordStatus::Established;

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
                            'key' => $recordKey,
                            'value' => $value,
                            'actor_type' => 'customer',
                            'actor_id' => null,
                            'method' => 'targeted_customer_task',
                            'confidence' => 1.0,
                            'status' => $preserveStatus,
                            'observed_at' => $answeredAt,
                            'superseded_by_id' => $preserveSupersededBy,
                        ],
                    );

                    // Collapse accidental duplicate keys on the same subject (finding: .8 twice).
                    $this->dedupeActiveRecordsByKey($intake, $subject, $recordKey, $record);
                }

                $this->linkEvidence($intake, $subject, 'intake_follow_up_item', $item->id, $record);

                if ($task->status === ContributionTaskStatus::Completed) {
                    $task->update(['completed_at' => $answeredAt ?? $round->completed_at]);
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
        $typeCounts = [];

        // Botsingsset: alleen installateursnamen + manual-* rooms (geen flip-flop tussen templatekamers).
        $usedNames = AircoRoom::query()
            ->where('intake_id', $intake->id)
            ->get()
            ->filter(static function (AircoRoom $room): bool {
                if ($room->name_source === 'installer') {
                    return trim($room->name) !== '';
                }

                return str_starts_with($room->key, 'manual-') && trim($room->name) !== '';
            })
            ->map(static fn (AircoRoom $room): string => trim($room->name))
            ->values()
            ->all();

        foreach ($instanceKeys as $index => $instanceKey) {
            $typeAnswer = $intake->answers->first(
                static fn (IntakeAnswer $answer): bool => $answer->section_instance_key === $instanceKey
                    && $answer->question_key === 'room_type',
            );
            $useType = is_array($typeAnswer?->value) ? ($typeAnswer->value['value'] ?? null) : null;
            $generatedName = RoomLabelResolver::label(is_string($useType) ? $useType : null, $typeCounts);
            $explicitName = $this->roomNameFromAnswers($intake, $instanceKey);

            $existing = AircoRoom::query()
                ->where('intake_id', $intake->id)
                ->where('key', $instanceKey)
                ->first();

            $typeSource = $typeAnswer?->prefill_source;
            $typeIsAi = PrefillSources::isProposedAi($typeSource);
            $typeIsCustomer = $typeAnswer !== null && $typeSource === null;

            // Installateur > klant (expliciete naam + verdieping) > AI/gegenereerd.
            // room_name-antwoord (klant of AI-prefill) wint altijd van Slaapkamer N, tenzij installateur.
            if ($existing !== null && $existing->name_source === 'installer' && $existing->name !== '') {
                $name = $existing->name;
            } else {
                if ($explicitName !== null) {
                    $name = RoomLabelResolver::uniqueAmong($explicitName, $usedNames);
                } elseif ($typeIsAi || $typeIsCustomer || $existing === null) {
                    $name = RoomLabelResolver::uniqueAmong($generatedName, $usedNames);
                } else {
                    $name = $this->resolveRoomName($existing->name, null, $generatedName);
                    $name = RoomLabelResolver::uniqueAmong($name, $usedNames);
                }

                $floorLabel = $this->floorLabelFromAnswers($intake, $instanceKey);
                if ($floorLabel !== null) {
                    $name = $this->appendFloorLabel($name, $floorLabel);
                }

                $usedNames[] = $name;
            }

            $subject = $this->subject(
                $intake,
                'airco.room.'.$instanceKey,
                'airco_room',
                $name,
                $root,
                ['legacy_section_instance_key' => $instanceKey],
            );
            $subjects[$instanceKey] = $subject;

            $answerDimensions = $this->roomDimensions($intake, $instanceKey);
            $dimensions = $this->mergeRoomDimensions(
                is_array($existing?->dimensions) ? $existing->dimensions : null,
                $answerDimensions,
            );

            $useTypeSource = $this->resolveUseTypeSource($existing, $typeSource, is_string($useType));

            if ($existing === null) {
                AircoRoom::query()->create([
                    'intake_id' => $intake->id,
                    'company_id' => $intake->company_id,
                    'dossier_subject_id' => $subject->id,
                    'key' => $instanceKey,
                    'name' => $name,
                    'name_source' => null,
                    'use_type' => is_string($useType) ? $useType : null,
                    'use_type_source' => $useTypeSource,
                    'sort_order' => $index + 1,
                    'status' => 'desired',
                    'source_type' => 'template_bridge',
                    'source_id' => $typeAnswer?->id,
                    'dimensions' => $dimensions,
                ]);

                continue;
            }

            $updates = [
                'company_id' => $intake->company_id,
                'dossier_subject_id' => $subject->id,
                'sort_order' => $index + 1,
                'status' => 'desired',
                'dimensions' => $dimensions,
            ];

            if ($existing->name_source !== 'installer') {
                $updates['name'] = $name;
            }

            if (is_string($useType) && $this->mayUpdateUseType($existing, $typeSource)) {
                $updates['use_type'] = $useType;
                $updates['use_type_source'] = $useTypeSource;
            }

            $existing->update($updates);
        }

        return $subjects;
    }

    private function mayUpdateUseType(?AircoRoom $existing, ?string $typeSource): bool
    {
        if ($existing === null) {
            return true;
        }

        $locked = $existing->use_type_source;
        if ($locked === 'installer') {
            return false;
        }

        // Klantcorrectie (lege prefill-bron) mag AI en eerdere klant bijwerken.
        if ($typeSource === null) {
            return true;
        }

        // AI alleen als use_type_source niet installer of klant is.
        if ($locked === 'customer') {
            return false;
        }

        return PrefillSources::isProposedAi($typeSource) || $existing->use_type === null;
    }

    private function resolveUseTypeSource(?AircoRoom $existing, ?string $typeSource, bool $hasType): ?string
    {
        if (! $hasType) {
            return $existing?->use_type_source;
        }

        if ($existing?->use_type_source === 'installer') {
            return 'installer';
        }

        if ($typeSource === null) {
            return 'customer';
        }

        if ($existing?->use_type_source === 'customer') {
            return 'customer';
        }

        return PrefillSources::isProposedAi($typeSource) ? 'ai' : ($existing?->use_type_source);
    }

    /**
     * Antwoordmaten winnen tenzij de bestaande maten van de installateur komen.
     *
     * `dimensions_source=installer`: geen gatenvulling vanuit antwoorden (L/W en m² blijven
     * gescheiden routes — geen area_* bij L×B, geen L/W bij area_m2).
     * Legacy: `area_source=installer` is tijdelijk voor rijen van vóór de backfill.
     *
     * @param  array<string, float|string>|null  $existing
     * @param  array<string, float|string>  $fromAnswers
     * @return array<string, float|string>
     */
    private function mergeRoomDimensions(?array $existing, array $fromAnswers): array
    {
        if ($existing === null || $existing === []) {
            return $fromAnswers;
        }

        $installerOwned = ($existing['dimensions_source'] ?? null) === 'installer'
            // Tijdelijk: rijen van vóór dimensions_source-backfill.
            || ($existing['area_source'] ?? null) === 'installer';

        if ($installerOwned) {
            return $existing;
        }

        if ($fromAnswers === []) {
            return $existing;
        }

        return array_merge($existing, $fromAnswers);
    }

    /**
     * @return array{0: float, 1: DossierRecordStatus}
     */
    private function prefillConfidenceAndStatus(Intake $intake, IntakeAnswer $answer, bool $isAssumption = false): array
    {
        $source = $answer->prefill_source;
        $percent = is_int($answer->fact_confidence)
            ? $answer->fact_confidence
            : FactAcceptance::normalizeConfidence($answer->fact_confidence);

        if ($source === PrefillSources::DERIVED_LXW) {
            return $this->derivedLxwConfidenceStatus($intake, $answer->section_instance_key);
        }

        if ($isAssumption || PrefillSources::isSuggestion($source)) {
            return [
                $percent !== null ? FactAcceptance::dossierFloat($percent) : 0.7,
                DossierRecordStatus::Proposed,
            ];
        }

        if (PrefillSources::isStrongAi($source)) {
            return [
                $percent !== null ? FactAcceptance::dossierFloat($percent) : 0.9,
                DossierRecordStatus::Proposed,
            ];
        }

        return [
            $percent !== null ? FactAcceptance::dossierFloat($percent) : 1.0,
            DossierRecordStatus::Established,
        ];
    }

    private function prefillConfidence(Intake $intake, IntakeAnswer $answer, ?int $confidencePercent = null): float
    {
        if ($confidencePercent !== null) {
            return FactAcceptance::dossierFloat($confidencePercent);
        }

        return $this->prefillConfidenceAndStatus($intake, $answer)[0];
    }

    private function prefillStatus(Intake $intake, IntakeAnswer $answer, bool $isAssumption = false): DossierRecordStatus
    {
        return $this->prefillConfidenceAndStatus($intake, $answer, $isAssumption)[1];
    }

    /**
     * @return array{0: float, 1: DossierRecordStatus}
     */
    private function derivedLxwConfidenceStatus(Intake $intake, ?string $instanceKey): array
    {
        $sources = [];
        foreach (['room_length_m', 'room_width_m'] as $key) {
            $dim = $intake->answers->first(
                static fn (IntakeAnswer $answer): bool => $answer->question_key === $key
                    && $answer->section_instance_key === $instanceKey,
            );
            $sources[] = $dim?->prefill_source;
        }

        foreach ($sources as $source) {
            if (PrefillSources::isSuggestion($source)) {
                return [0.7, DossierRecordStatus::Proposed];
            }
        }

        foreach ($sources as $source) {
            if (PrefillSources::isStrongAi($source)) {
                return [0.9, DossierRecordStatus::Proposed];
            }
        }

        return [1.0, DossierRecordStatus::Established];
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

    /**
     * @return array<string, float|string>
     */
    private function roomDimensions(Intake $intake, string $instanceKey): array
    {
        $mapping = [
            'room_length_m' => 'length_m',
            'room_width_m' => 'width_m',
            'ceiling_height_m' => 'height_m',
            'room_area_m2' => 'area_m2',
        ];
        $dimensions = [];
        $areaAnswer = null;

        foreach ($mapping as $questionKey => $dimensionKey) {
            $answer = $intake->answers->first(
                static fn (IntakeAnswer $answer): bool => $answer->section_instance_key === $instanceKey
                    && $answer->question_key === $questionKey,
            );
            $number = is_array($answer?->value) ? ($answer->value['number'] ?? null) : null;

            if (! is_numeric($number)) {
                continue;
            }

            $dimensions[$dimensionKey] = (float) $number;

            if ($questionKey === 'room_area_m2') {
                $areaAnswer = $answer;
            }
        }

        if ($areaAnswer instanceof IntakeAnswer) {
            $mapped = RoomAreaAcceptance::fromPrefillSource($areaAnswer->prefill_source);
            $dimensions['area_source'] = $mapped['source'];
            $dimensions['area_confidence'] = $mapped['confidence'];

            // Keep a short evidence trail for AI-/L×B-derived exact m².
            if (in_array($mapped['source'], ['ai', 'ai_suggestion', 'derived_lxw'], true)) {
                $dimensions['area_evidence'] = $mapped['source'] === 'derived_lxw'
                    ? 'Berekend uit L×B'
                    : 'Uit bekende context of AI-prefill';
            }
        }

        // Never invent length/width from area alone — leave L×B empty when only m² is known.
        return $dimensions;
    }

    private function roomNameFromAnswers(Intake $intake, string $instanceKey): ?string
    {
        $answer = $intake->answers->first(
            static fn (IntakeAnswer $answer): bool => $answer->section_instance_key === $instanceKey
                && $answer->question_key === 'room_name',
        );
        $value = $answer?->value;
        if (! is_array($value)) {
            return null;
        }

        $text = $value['text'] ?? $value['value'] ?? null;
        if (! is_string($text)) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function floorLabelFromAnswers(Intake $intake, string $instanceKey): ?string
    {
        // Installateurscorrectie op de werkplek wint van prefill-antwoord.
        $room = AircoRoom::query()
            ->where('intake_id', $intake->id)
            ->where('key', $instanceKey)
            ->first();
        $roomFloor = is_array($room?->dimensions) ? ($room->dimensions['floor_level'] ?? null) : null;
        $roomFloorSource = is_array($room?->dimensions) ? ($room->dimensions['floor_level_source'] ?? null) : null;
        if ($roomFloorSource === 'installer' && is_string($roomFloor) && $roomFloor !== '') {
            return $this->floorLevelDisplayLabel($roomFloor);
        }

        $answer = $intake->answers->first(
            static fn (IntakeAnswer $answer): bool => $answer->section_instance_key === $instanceKey
                && $answer->question_key === 'floor_level',
        );
        $value = $answer?->value;
        if (! is_array($value)) {
            return null;
        }

        $raw = $value['value'] ?? $value['text'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $this->floorLevelDisplayLabel(trim($raw));
    }

    private function floorLevelDisplayLabel(string $raw): ?string
    {
        return match ($raw) {
            'basement' => 'kelder / souterrain',
            'ground' => 'begane grond',
            '1' => '1e verdieping',
            '2' => '2e verdieping',
            '3_plus' => '3e verdieping of hoger',
            'attic' => 'zolder',
            default => null,
        };
    }

    private function appendFloorLabel(string $name, string $floorLabel): string
    {
        $name = trim($name);
        $floorLabel = trim($floorLabel);
        if ($name === '' || $floorLabel === '') {
            return $name;
        }

        $haystack = mb_strtolower($name);
        $needle = mb_strtolower($floorLabel);
        if (str_contains($haystack, $needle)) {
            return $name;
        }

        // Avoid "Zolder, zolder" when the generated label already is the floor.
        if ($needle === 'zolder' && preg_match('/\bzolder\b/u', $haystack) === 1) {
            return $name;
        }

        return $name.', '.$floorLabel;
    }

    private function resolveRoomName(?string $existingName, ?string $explicitName, string $generatedName): string
    {
        if (is_string($explicitName) && $explicitName !== '') {
            return $explicitName;
        }

        if (is_string($existingName) && $existingName !== '') {
            // Upgrade placeholder "Ruimte N" once we know a typed label.
            if (preg_match('/^Ruimte\s+\d+$/u', $existingName) === 1
                && preg_match('/^Ruimte\s+\d+$/u', $generatedName) !== 1) {
                return $generatedName;
            }

            return $existingName;
        }

        return $generatedName;
    }

    private function answerRecordKey(IntakeAnswer $answer): string
    {
        return implode('.', array_filter([
            'answer',
            $answer->section_instance_key,
            $answer->question_key,
        ], static fn (?string $value): bool => is_string($value) && $value !== ''));
    }

    private function answerRecordMethod(IntakeAnswer $answer, bool $isAssumption): string
    {
        if ($answer->prefill_source === null) {
            return 'customer_input';
        }

        if ($isAssumption) {
            return 'ai_assumption';
        }

        if (PrefillSources::isProposedAi($answer->prefill_source)) {
            return 'ai_proposal';
        }

        return 'automatic_prefill';
    }

    /**
     * @return array<string, mixed>
     */
    private function answerRecordValue(
        Intake $intake,
        IntakeAnswer $answer,
        ?IntakeQuestion $question,
        ?FactProvenance $provenance,
        bool $isAssumption,
        ?int $confidencePercent = null,
        ?FactSource $factSource = null,
    ): array {
        $value = is_array($answer->value) ? $answer->value : [];

        $enrichProposal = $isAssumption || PrefillSources::isProposedAi($answer->prefill_source);
        if (! $enrichProposal) {
            return $value;
        }

        $fieldLabel = is_string($question?->label) && trim($question->label) !== ''
            ? trim($question->label)
            : TechnicalProposalCopy::fallbackFieldLabel((string) $answer->question_key);
        $displayValue = $this->dutchDisplayValue($question, $value);
        $resolvedSource = $factSource
            ?? FactAcceptance::sourceFrom($answer->prefill_source, $provenance);
        $percent = $confidencePercent
            ?? (is_int($answer->fact_confidence) ? $answer->fact_confidence : null)
            ?? FactAcceptance::normalizeConfidence($answer->fact_confidence)
            ?? (PrefillSources::isSuggestion($answer->prefill_source)
                ? FactAcceptance::LEVEL_MEDIUM
                : FactAcceptance::LEVEL_HIGH);
        $confidenceBand = FactAcceptance::levelFromPercent($percent);
        $confidenceNl = match ($confidenceBand) {
            'high' => 'hoog',
            'medium' => 'middel',
            default => 'laag',
        };
        $uncertainty = TechnicalProposalCopy::uncertainty(
            $intake,
            (string) $answer->question_key,
            $confidenceNl,
        );
        $sourceLabel = $resolvedSource->installerLabel($isAssumption);
        if ($isAssumption && $resolvedSource === FactSource::Derived) {
            $sourceLabel = 'afgeleid, niet bevestigd';
        }

        return array_merge($value, [
            '_field_label' => $fieldLabel,
            '_display_value' => $displayValue,
            '_uncertainty' => $uncertainty,
            '_provenance_label' => ($provenance ?? FactProvenance::Inferred)->installerLabel(),
            '_source_label' => $sourceLabel,
            '_confidence_percent' => $percent,
            '_confidence_label' => $percent.'%',
            '_status_label' => $isAssumption ? 'nog te bevestigen' : null,
            '_evidence' => $answer->fact_evidence,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function dutchDisplayValue(?IntakeQuestion $question, array $value): string
    {
        if ($question === null) {
            return $this->fallbackDisplayValue($value);
        }

        return match ($question->type) {
            QuestionType::SingleChoice => $this->optionDisplayLabel($question, is_string($value['value'] ?? null) ? $value['value'] : null)
                ?? $this->fallbackDisplayValue($value),
            QuestionType::MultiChoice => $this->multiChoiceDisplayLabels($question, $value),
            QuestionType::Boolean => array_key_exists('bool', $value) && is_bool($value['bool'])
                ? ($value['bool'] ? 'ja' : 'nee')
                : $this->fallbackDisplayValue($value),
            QuestionType::Number => isset($value['number']) && is_numeric($value['number'])
                ? (string) $value['number']
                : $this->fallbackDisplayValue($value),
            QuestionType::ShortText, QuestionType::LongText => is_string($value['text'] ?? null) && trim($value['text']) !== ''
                ? trim($value['text'])
                : $this->fallbackDisplayValue($value),
            default => $this->fallbackDisplayValue($value),
        };
    }

    private function optionDisplayLabel(IntakeQuestion $question, ?string $optionValue): ?string
    {
        if ($optionValue === null || $optionValue === '') {
            return null;
        }

        $label = $question->options->firstWhere('value', $optionValue)?->label;

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function multiChoiceDisplayLabels(IntakeQuestion $question, array $value): string
    {
        $values = is_array($value['values'] ?? null) ? $value['values'] : [];
        $labels = [];
        foreach ($values as $optionValue) {
            if (! is_string($optionValue)) {
                continue;
            }
            $labels[] = $this->optionDisplayLabel($question, $optionValue) ?? $optionValue;
        }

        return $labels === [] ? $this->fallbackDisplayValue($value) : implode(', ', $labels);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function fallbackDisplayValue(array $value): string
    {
        $bits = [];
        foreach ($value as $key => $item) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            if (is_bool($item)) {
                $bits[] = $item ? 'ja' : 'nee';
            } elseif (is_scalar($item) && (string) $item !== '') {
                $bits[] = (string) $item;
            }
        }

        return implode(' · ', $bits);
    }

    /**
     * @return array<string, IntakeQuestion>
     */
    private function questionIndex(Intake $intake): array
    {
        $intake->loadMissing(['templateVersion.sections.questions.options']);
        $index = [];

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                $index[$question->key] = $question;
            }
        }

        return $index;
    }

    /**
     * When two active records share a dossier key on the same subject, keep the
     * canonical follow-up-item record and supersede the duplicate (BL-146).
     */
    private function dedupeActiveRecordsByKey(
        Intake $intake,
        DossierSubject $subject,
        string $key,
        DossierRecord $canonical,
    ): void {
        $duplicates = DossierRecord::query()
            ->where('intake_id', $intake->id)
            ->where('dossier_subject_id', $subject->id)
            ->where('key', $key)
            ->whereIn('status', [
                DossierRecordStatus::Proposed,
                DossierRecordStatus::Established,
                DossierRecordStatus::Conflicted,
            ])
            ->where('id', '!=', $canonical->id)
            ->get();

        foreach ($duplicates as $duplicate) {
            // Prefer keeping the record whose source_id matches the key suffix.
            $canonicalSourceId = $canonical->source_id;
            $keySuffix = Str::afterLast($key, '.');
            if (is_numeric($keySuffix)
                && (int) $keySuffix === (int) $duplicate->source_id
                && (int) $keySuffix !== (int) $canonicalSourceId) {
                $canonical->update([
                    'status' => DossierRecordStatus::Superseded,
                    'superseded_by_id' => $duplicate->id,
                ]);

                continue;
            }

            $duplicate->update([
                'status' => DossierRecordStatus::Superseded,
                'superseded_by_id' => $canonical->id,
            ]);
        }
    }
}
