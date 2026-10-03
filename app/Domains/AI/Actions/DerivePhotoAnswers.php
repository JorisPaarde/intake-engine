<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiEnumNormalizer;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\AiTraceHandle;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceSnapshotService;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\DerivedAnswerField;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\TechnicalDecisionKeys;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\IntakeStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Derives confirmable answers from an uploaded photo set, for any question that opts in
 * through `meta.photo_analysis` (BL-020 generalised beyond the fusebox).
 *
 * Each newly uploaded photo is assessed separately (one verdict per upload). Technical
 * route conclusions never become customer intake_answers — only installer-side facts.
 */
final class DerivePhotoAnswers
{
    public const SOURCE = 'AI-fotoanalyse';

    /** Answer is trusted enough to replace the question entirely. */
    public const SOURCE_DERIVED = PrefillSources::AI_PHOTO;

    /** Answer is only a voorzet; the applicant still confirms it. */
    public const SOURCE_SUGGESTED = PrefillSources::AI_PHOTO_SUGGESTION;

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTraceSnapshotService $traceSnapshots,
        private readonly AiTracePhotoRefBuilder $photoRefBuilder,
    ) {}

    public function handle(
        Intake $intake,
        string $photoQuestionKey,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
        ?string $correlationId = null,
    ): ?AiRun {
        $expected = PhotoSubject::expectedForPhotoQuestion($photoQuestionKey, $profile->name)
            ?? PhotoSubject::Other;

        $allUploads = $this->allUploads($intake, $photoQuestionKey, $sectionInstanceKey);

        if ($allUploads->isEmpty()) {
            $this->invalidateDerivedState($intake, $photoQuestionKey, $sectionInstanceKey, $profile);

            return null;
        }

        if (! (bool) config('ai.photo_inference.enabled', false)) {
            foreach ($allUploads as $upload) {
                $existing = $upload->contentAssessment();
                if ($existing === null || $existing->needsReassessment()) {
                    $upload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
                }
            }

            return null;
        }

        $recentIds = $this->recentUploadIds($allUploads);
        $lastRun = null;
        /** @var list<array<string, mixed>> $matchingOutputs */
        $matchingOutputs = [];
        $anyMismatch = false;
        $assessedAny = false;
        /** @var array{run: AiRun, trace: AiTraceHandle, dossier_before: array<string, mixed>, questions_before: array<string, mixed>}|null $applyContext */
        $applyContext = null;

        foreach ($allUploads as $upload) {
            // Bestaande definitieve assessment blijft staan; not_assessed mag opnieuw.
            $existing = $upload->contentAssessment();
            if ($existing !== null && ! $existing->needsReassessment()) {
                if ($existing->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                    && ! $existing->customerAcceptedMismatch()) {
                    $anyMismatch = true;
                }

                continue;
            }

            $assessedAny = true;
            $assessed = $this->assessUpload(
                $intake,
                $upload,
                $photoQuestionKey,
                $sectionInstanceKey,
                $profile,
                $expected,
                $correlationId,
            );
            $run = $assessed['run'];
            $lastRun = $run;

            $freshUpload = $upload->fresh() ?? $upload;
            $assessment = $freshUpload->contentAssessment();

            // Garantie: nooit null na beoordelingspoging.
            if ($assessment === null) {
                $freshUpload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
                $assessment = $freshUpload->fresh()?->contentAssessment()
                    ?? PhotoContentAssessment::notAssessed($expected);
            }

            if ($assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                && ! $assessment->customerAcceptedMismatch()) {
                $anyMismatch = true;
                if ($assessed['trace'] instanceof AiTraceHandle) {
                    $this->finalizeContentOnlyTrace(
                        $assessed['trace'],
                        $intake,
                        $assessed['dossier_before'],
                        $assessed['questions_before'],
                    );
                }

                continue;
            }

            $isMatching = $run->status === AiRunStatus::Succeeded
                && is_array($run->output)
                && ($run->output['subject_match'] ?? 'yes') === 'yes'
                && in_array($upload->id, $recentIds, true);

            if ($isMatching) {
                $matchingOutputs[] = $run->output;
                if ($assessed['trace'] instanceof AiTraceHandle) {
                    // Eerdere apply-kandidaat sluiten zonder apply — alleen de laatste krijgt apply.
                    if ($applyContext !== null) {
                        $this->finalizeContentOnlyTrace(
                            $applyContext['trace'],
                            $intake,
                            $applyContext['dossier_before'],
                            $applyContext['questions_before'],
                        );
                    }
                    $applyContext = $assessed;
                }
            } elseif ($assessed['trace'] instanceof AiTraceHandle) {
                $this->finalizeContentOnlyTrace(
                    $assessed['trace'],
                    $intake,
                    $assessed['dossier_before'],
                    $assessed['questions_before'],
                );
            }
        }

        // Geen nieuwe beoordeling én geen onopgeloste mismatch → afleidingen intact.
        if (! $assessedAny && ! $anyMismatch) {
            return AiRun::query()
                ->where('intake_id', $intake->id)
                ->where('type', AiRunType::PhotoAssessment)
                ->where('status', AiRunStatus::Succeeded)
                ->latest('id')
                ->first() ?? $lastRun;
        }

        // Alleen invalideren bij succesvolle match of onopgeloste mismatch — nooit bij pure AI-fout.
        if ($matchingOutputs === [] && ! $anyMismatch) {
            return $lastRun;
        }

        if ($matchingOutputs !== []) {
            $output = $this->mergeOutputs($matchingOutputs, $profile);
            $matchingUploads = $allUploads->filter(static function (IntakeUpload $upload) use ($recentIds): bool {
                if (! in_array($upload->id, $recentIds, true)) {
                    return false;
                }

                $fresh = $upload->fresh() ?? $upload;
                $assessment = $fresh->contentAssessment();

                if ($assessment === null) {
                    return true;
                }

                return $assessment->status() !== PhotoContentAssessment::STATUS_WRONG_SUBJECT
                    || $assessment->customerAcceptedMismatch();
            })->values();

            // Manifest van de analyse (niet van na de provider-call).
            if ($applyContext !== null) {
                $persistenceManifest = [$applyContext['persistence']];
                // Voeg persistences van eerdere matching uploads toe via matchingUploads ids die in applyContext zitten.
                // Bij één apply-context (laatste match) dekken we die upload; overige matching komen uit mergeOutputs ranked.
                foreach ($matchingUploads as $matchingUpload) {
                    if ((int) $matchingUpload->id === (int) $applyContext['persistence']['id']) {
                        continue;
                    }
                    $persistenceManifest[] = [
                        'id' => $matchingUpload->id,
                        ...$this->aiImageResolver->identity($matchingUpload),
                    ];
                }
                // Stabiele volgorde op id
                usort($persistenceManifest, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
            } else {
                $persistenceManifest = $matchingUploads
                    ->sortBy('id')
                    ->map(fn (IntakeUpload $upload): array => [
                        'id' => $upload->id,
                        ...$this->aiImageResolver->identity($upload),
                    ])
                    ->values()
                    ->all();
            }

            $run = $applyContext['run'] ?? $lastRun;
            if ($applyContext !== null) {
                $trace = $applyContext['trace'];
                $dossierBefore = $applyContext['dossier_before'];
                $questionsBefore = $applyContext['questions_before'];
            } else {
                // Cache-hit pad: geen open assess-trace — start apply-trace voor #117-stappen.
                $correlationId ??= (string) Str::uuid();
                $latestMatching = $matchingUploads->sortByDesc('id')->first();
                $trace = $this->traceRecorder->start($intake, AiTraceCallType::PhotoDerive, array_filter([
                    'ai_run_id' => $run?->id,
                    'upload_id' => $latestMatching?->id,
                    'subject_type' => 'section',
                    'subject_id' => $sectionInstanceKey ?? $photoQuestionKey,
                    'provider' => (string) config('ai.provider', 'null'),
                    'correlation_id' => $correlationId,
                ], static fn (mixed $value): bool => $value !== null));
                if ($latestMatching instanceof IntakeUpload) {
                    $trace->linkUpload($latestMatching);
                }
                $dossierBefore = [];
                $questionsBefore = [];
                if (! $trace->isNoop()) {
                    $dossierBefore = $this->traceSnapshots->answers($intake);
                    $questionsBefore = $this->traceSnapshots->remainingQuestions($intake);
                }
            }

            if ($run instanceof AiRun && is_array($output)) {
                try {
                    DB::transaction(function () use (
                        $intake,
                        $run,
                        $output,
                        $matchingUploads,
                        $photoQuestionKey,
                        $sectionInstanceKey,
                        $profile,
                        $anyMismatch,
                        $persistenceManifest,
                        $trace,
                    ): void {
                        $trace->beginBuffer();
                        $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

                        if (! in_array($lockedIntake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
                            throw new \RuntimeException('Opname is afgerond tijdens AI-analyse; resultaat niet toegepast.');
                        }

                        $currentManifest = $matchingUploads
                            ->sortBy('id')
                            ->map(function (IntakeUpload $upload): array {
                                $fresh = IntakeUpload::query()->whereKey($upload->id)->first() ?? $upload;

                                return [
                                    'id' => $fresh->id,
                                    ...$this->aiImageResolver->identity($fresh),
                                ];
                            })
                            ->values()
                            ->all();

                        if ($currentManifest !== $persistenceManifest) {
                            throw new \RuntimeException('Foto’s gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
                        }

                        if ($this->hasRoomTypeTextConflict($intake, $output, $sectionInstanceKey, $profile)) {
                            $this->storeObservation(
                                $intake,
                                $run,
                                $output,
                                $matchingUploads,
                                $photoQuestionKey,
                                $sectionInstanceKey,
                                $profile,
                                $trace->traceId(),
                            );
                            $applied = ['derived' => [], 'suggested' => []];
                            $trace->step('room_type_text_conflict', [
                                'photo_question_key' => $photoQuestionKey,
                                'section_instance_key' => $sectionInstanceKey,
                            ]);
                        } else {
                            $this->invalidateDerivedState($intake, $photoQuestionKey, $sectionInstanceKey, $profile);
                            $trace->step('invalidate_previous', [
                                'photo_question_key' => $photoQuestionKey,
                                'section_instance_key' => $sectionInstanceKey,
                            ]);
                            $this->storeObservation(
                                $intake,
                                $run,
                                $output,
                                $matchingUploads,
                                $photoQuestionKey,
                                $sectionInstanceKey,
                                $profile,
                                $trace->traceId(),
                            );
                            $applied = $this->applyDerivedAnswers($intake, $output, $sectionInstanceKey, $profile);
                            $trace->step('apply', $applied);
                        }

                        $trace->recordFieldOutcomes(
                            $this->fieldOutcomesFromPhoto($intake, $output, $profile, $applied, $sectionInstanceKey),
                        );

                        IntakeActivityEvent::query()->create([
                            'intake_id' => $intake->id,
                            'actor_type' => 'system',
                            'actor_id' => null,
                            'event' => 'photo_answers_derived',
                            'properties' => [
                                'ai_run_id' => $run->id,
                                'ai_trace_id' => $trace->traceId(),
                                'profile' => $profile->name,
                                'question_key' => $photoQuestionKey,
                                'section_instance_key' => $sectionInstanceKey,
                                'confidence' => $output['confidence'],
                                'subject_match' => $output['subject_match'] ?? null,
                                'detected_subject' => $output['detected_subject'] ?? null,
                                'derived_question_keys' => $applied['derived'],
                                'suggested_question_keys' => $applied['suggested'],
                                'content_mismatch' => $anyMismatch,
                            ],
                            'created_at' => now(),
                        ]);
                    }, 3);
                    $trace->flushBuffer();
                } catch (Throwable $transactionException) {
                    $trace->discardBuffer();
                    Log::warning('AI photo answer derivation failed', [
                        'intake_id' => $intake->id,
                        'ai_run_id' => $run->id,
                        'ai_trace_id' => $trace->traceId(),
                        'profile' => $profile->name,
                        'exception' => $transactionException::class,
                    ]);

                    $run->update([
                        'status' => AiRunStatus::Failed,
                        'error_message' => Str::limit($transactionException->getMessage(), 1000, ''),
                        'finished_at' => now(),
                    ]);
                    $trace->linkAiRun($run->fresh() ?? $run);
                    $trace->fail($transactionException->getMessage(), $transactionException);

                    return $run->fresh() ?? $run;
                }

                $trace->linkAiRun($run->fresh() ?? $run);
                $trace->stopProcessTimer();
                if (! $trace->isNoop()) {
                    $freshIntake = $intake->fresh() ?? $intake;
                    $dossierAfter = $this->traceSnapshots->answers($freshIntake);
                    $trace->recordDossierSnapshots(
                        $dossierBefore,
                        $dossierAfter,
                        $this->traceSnapshots->changedFields($dossierBefore, $dossierAfter),
                    );
                    $trace->recordRemainingQuestions(
                        $questionsBefore,
                        $this->traceSnapshots->remainingQuestions($freshIntake),
                    );
                }
                $trace->succeed();

                return $run->fresh() ?? $run;
            }
        }

        if ($anyMismatch) {
            $this->invalidateDerivedState($intake, $photoQuestionKey, $sectionInstanceKey, $profile);

            if ($lastRun instanceof AiRun) {
                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'event' => 'photo_answers_derived',
                    'properties' => [
                        'ai_run_id' => $lastRun->id,
                        'ai_trace_id' => isset($applyContext['trace']) ? $applyContext['trace']->traceId() : null,
                        'profile' => $profile->name,
                        'question_key' => $photoQuestionKey,
                        'section_instance_key' => $sectionInstanceKey,
                        'content_mismatch' => true,
                        'derived_question_keys' => [],
                        'suggested_question_keys' => [],
                    ],
                    'created_at' => now(),
                ]);
            }
        }

        return $lastRun;
    }

    /**
     * @param  array<string, mixed>  $dossierBefore
     * @param  array<string, mixed>  $questionsBefore
     */
    private function finalizeContentOnlyTrace(
        AiTraceHandle $trace,
        Intake $intake,
        array $dossierBefore,
        array $questionsBefore,
    ): void {
        $trace->stopProcessTimer();
        if (! $trace->isNoop()) {
            $freshIntake = $intake->fresh() ?? $intake;
            $dossierAfter = $this->traceSnapshots->answers($freshIntake);
            $trace->recordDossierSnapshots(
                $dossierBefore,
                $dossierAfter,
                $this->traceSnapshots->changedFields($dossierBefore, $dossierAfter),
            );
            $trace->recordRemainingQuestions(
                $questionsBefore,
                $this->traceSnapshots->remainingQuestions($freshIntake),
            );
        }
        $trace->succeed();
    }

    /**
     * @param  list<array<string, mixed>>  $outputs
     * @return array<string, mixed>|null
     */
    private function mergeOutputs(array $outputs, PhotoDerivationProfile $profile): ?array
    {
        if ($outputs === []) {
            return null;
        }

        $rank = static fn (string $confidence): int => match ($confidence) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };

        usort($outputs, static function (array $a, array $b) use ($rank): int {
            return $rank((string) ($b['confidence'] ?? 'low')) <=> $rank((string) ($a['confidence'] ?? 'low'));
        });

        return $outputs[0];
    }

    /**
     * @return array{
     *     run: AiRun,
     *     trace: AiTraceHandle|null,
     *     dossier_before: array<string, mixed>,
     *     questions_before: array<string, mixed>,
     *     persistence: array{id: int|string, checksum: mixed, mime_type: string, variant: string}
     * }
     */
    private function assessUpload(
        Intake $intake,
        IntakeUpload $upload,
        string $photoQuestionKey,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
        PhotoSubject $expected,
        ?string $correlationId = null,
    ): array {
        $promptVersion = $this->promptVersions->version($profile->promptName);
        $promptBody = $this->promptVersions->body($profile->promptName);

        $persistence = [
            'id' => $upload->id,
            ...$this->aiImageResolver->identity($upload),
        ];

        $input = [
            'task' => 'derive_answers_from_photos',
            'profile' => $profile->name,
            'expected_fields' => $this->schema($profile),
            'expected_subject' => $expected->value,
            'images' => [$this->aiImageResolver->identity($upload)],
            'upload_id' => $upload->id,
        ];

        $inputHash = hash('sha256', (string) json_encode([
            'prompt_version' => $promptVersion,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));

        $existing = AiRun::query()
            ->where('intake_id', $intake->id)
            ->where('type', AiRunType::PhotoAssessment)
            ->where('input_hash', $inputHash)
            ->where('status', AiRunStatus::Succeeded)
            ->latest('id')
            ->first();

        if ($existing instanceof AiRun && is_array($existing->output)) {
            $this->applyUploadAssessment(
                $upload,
                $expected,
                $existing->output,
                $photoQuestionKey,
                $profile->name,
            );

            return [
                'run' => $existing,
                'trace' => null,
                'dossier_before' => [],
                'questions_before' => [],
                'persistence' => $persistence,
            ];
        }

        $run = AiRun::query()->create([
            'intake_id' => $intake->id,
            'upload_id' => $upload->id,
            'type' => AiRunType::PhotoAssessment,
            'provider' => (string) config('ai.provider', 'null'),
            'model' => null,
            'prompt_version' => $promptVersion,
            'input_hash' => $inputHash,
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        // Correlation is per upload (BL-129), not reused from a sibling upload in the same batch.
        $uploadCorrelationId = $this->correlationIdForUpload($upload);

        $trace = $this->traceRecorder->start($intake, AiTraceCallType::PhotoDerive, [
            'ai_run_id' => $run->id,
            'upload_id' => $upload->id,
            'subject_type' => 'section',
            'subject_id' => $sectionInstanceKey ?? $photoQuestionKey,
            'provider' => (string) config('ai.provider', 'null'),
            'prompt_version' => $promptVersion,
            'correlation_id' => $uploadCorrelationId,
        ]);
        $trace->linkUpload($upload);

        $dossierBefore = [];
        $questionsBefore = [];
        if (! $trace->isNoop()) {
            $dossierBefore = $this->traceSnapshots->answers($intake);
            $questionsBefore = $this->traceSnapshots->remainingQuestions($intake);
        }

        try {
            $photoRefs = [];
            if (! $trace->isNoop()) {
                $photoRefs = [$this->photoRefBuilder->fromUpload($upload, $profile->name)];
            }
            $trace->recordRequest(
                systemAndUser: [
                    'system' => $promptBody,
                    'user' => $input,
                ],
                photoRefs: $photoRefs,
                promptVersion: $promptVersion,
            );

            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
                images: [$this->aiImageResolver->input($upload)],
                temperature: (float) config('ai.classification_temperature', 0),
            );
            $trace->recordProviderResult($result);

            try {
                [$output, $normalizations] = $this->validateOutput($result->output, $profile);
                $trace->recordParsed($output, [], $normalizations);
            } catch (ValidationException $exception) {
                $trace->recordParsed([], $exception->errors());
                throw $exception;
            }
            $trace->step('normalize', [
                'profile' => $profile->name,
                'confidence' => $output['confidence'],
                'normalization_count' => count($normalizations),
            ]);

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $this->applyUploadAssessment(
                $upload,
                $expected,
                $output,
                $photoQuestionKey,
                $profile->name,
            );

            $freshRun = $run->fresh() ?? $run;
            $trace->linkAiRun($freshRun);

            // Trace blijft open: handle() doet apply (of finalizeContentOnlyTrace).
            return [
                'run' => $freshRun,
                'trace' => $trace,
                'dossier_before' => $dossierBefore,
                'questions_before' => $questionsBefore,
                'persistence' => $persistence,
            ];
        } catch (Throwable $exception) {
            $trace->linkAiRun($run->fresh() ?? $run);
            $trace->fail($exception->getMessage(), $exception);
            Log::warning('AI photo answer derivation failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
                'ai_trace_id' => $trace->traceId(),
                'profile' => $profile->name,
                'upload_id' => $upload->id,
                'exception' => $exception::class,
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            $upload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));

            return [
                'run' => $run->fresh() ?? $run,
                'trace' => null,
                'dossier_before' => [],
                'questions_before' => [],
                'persistence' => $persistence,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function applyUploadAssessment(
        IntakeUpload $upload,
        PhotoSubject $expected,
        array $output,
        ?string $photoQuestionKey = null,
        ?string $profileName = null,
    ): void {
        $previous = $upload->contentAssessment();
        $accepted = $photoQuestionKey !== null
            ? PhotoSubject::acceptedSubjectsForPhotoQuestion($photoQuestionKey, $profileName)
            : null;
        $assessment = PhotoContentAssessment::fromModelOutput($expected, $output, $accepted)
            ->preservingCustomerAcceptance($previous);

        $upload->storeContentAssessment($assessment);
    }

    public function invalidateDerivedState(
        Intake $intake,
        string $photoQuestionKey,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
    ): void {
        DB::transaction(function () use ($intake, $photoQuestionKey, $sectionInstanceKey, $profile): void {
            Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            $factKey = $this->factKey($photoQuestionKey, $sectionInstanceKey);
            $hadPhotoFact = IntakeExternalFact::query()
                ->where('intake_id', $intake->id)
                ->where('fact_key', $factKey)
                ->where('source', self::SOURCE)
                ->exists();

            IntakeExternalFact::query()
                ->where('intake_id', $intake->id)
                ->where('fact_key', $factKey)
                ->where('source', self::SOURCE)
                ->delete();

            $sources = PrefillSources::photoInvalidationSources();
            if ($hadPhotoFact) {
                $sources[] = PrefillSources::AI_LEGACY;
            }

            $query = IntakeAnswer::query()
                ->where('intake_id', $intake->id)
                ->whereIn('question_key', $profile->questionKeys())
                ->whereIn('prefill_source', array_values(array_unique($sources)));

            $sectionInstanceKey === null
                ? $query->whereNull('section_instance_key')
                : $query->where('section_instance_key', $sectionInstanceKey);

            $query->delete();
        });

        $intake->unsetRelation('answers');
        $intake->unsetRelation('externalFacts');
    }

    /**
     * @return array<string, list<string>>
     */
    private function schema(PhotoDerivationProfile $profile): array
    {
        $schema = [];

        foreach ($profile->fields as $field) {
            $schema[$field->outputKey] = $field->schemaValues();
        }

        return $schema;
    }

    /** @return Collection<int, IntakeUpload> */
    private function allUploads(Intake $intake, string $photoQuestionKey, ?string $sectionInstanceKey): Collection
    {
        $query = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $photoQuestionKey);

        $sectionInstanceKey === null
            ? $query->whereNull('section_instance_key')
            : $query->where('section_instance_key', $sectionInstanceKey);

        return $query->orderBy('id')->get()->values();
    }

    /**
     * @param  Collection<int, IntakeUpload>  $all
     * @return list<int>
     */
    private function recentUploadIds(Collection $all): array
    {
        $maximum = max(1, min(3, (int) config('ai.photo_inference.max_images', 2)));

        return $all->sortByDesc('id')->take($maximum)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{0: array<string, mixed>, 1: list<array{field: string, from: mixed, to: mixed, rule: string}>}
     */
    private function validateOutput(array $output, PhotoDerivationProfile $profile): array
    {
        $normalizations = [];
        $enums = app(AiEnumNormalizer::class);
        $subjectValues = array_map(
            static fn (PhotoSubject $subject): string => $subject->value,
            PhotoSubject::cases(),
        );

        if (array_key_exists('confidence', $output)) {
            $from = $output['confidence'];
            $to = $enums->normalize(
                $from,
                ['high', 'medium', 'low'],
                ['hoog' => 'high', 'middel' => 'medium', 'matig' => 'medium', 'laag' => 'low'],
            );
            if ($from !== $to) {
                $normalizations[] = ['field' => 'confidence', 'from' => $from, 'to' => $to, 'rule' => 'confidence'];
            }
            $output['confidence'] = $to;
        }

        foreach ($profile->fields as $field) {
            if (! array_key_exists($field->outputKey, $output)) {
                // Nieuwe optionele schema-velden (bijv. glazing_type) soft-defaulten zodat
                // oudere fixtures/provider-responses zonder het veld niet hard falen (BL-129).
                $output[$field->outputKey] = 'unknown';
                $normalizations[] = [
                    'field' => $field->outputKey,
                    'from' => null,
                    'to' => 'unknown',
                    'rule' => 'missing_defaults_unknown',
                ];

                continue;
            }
            $from = $output[$field->outputKey];
            $to = $enums->normalize($from, $field->schemaValues());
            if ($from !== $to) {
                $normalizations[] = [
                    'field' => $field->outputKey,
                    'from' => $from,
                    'to' => $to,
                    'rule' => 'photo_field',
                ];
            }
            $output[$field->outputKey] = $to;
        }

        $rules = [
            'confidence' => ['required', Rule::in(['high', 'medium', 'low'])],
            'evidence' => ['required', 'string', 'min:3', 'max:300'],
            'retake_instruction' => ['nullable', 'string', 'min:5', 'max:300'],
            'detected_subject' => ['nullable', Rule::in($subjectValues)],
            'subject_match' => ['nullable', Rule::in(['yes', 'no'])],
        ];

        foreach ($profile->fields as $field) {
            $rules[$field->outputKey] = ['required', Rule::in($field->schemaValues())];
        }

        $validator = Validator::make($output, $rules);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        $validated['evidence'] = trim((string) $validated['evidence']);
        $validated['retake_instruction'] = is_string($validated['retake_instruction'] ?? null)
            ? trim((string) $validated['retake_instruction'])
            : null;
        $validated['detected_subject'] = is_string($validated['detected_subject'] ?? null)
            ? $validated['detected_subject']
            : null;
        $validated['subject_match'] = is_string($validated['subject_match'] ?? null)
            ? $validated['subject_match']
            : 'yes';

        return [$validated, $normalizations];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    private function storeObservation(
        Intake $intake,
        AiRun $run,
        array $output,
        Collection $uploads,
        string $photoQuestionKey,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
        ?string $traceId = null,
    ): void {
        $isPipeRoute = $profile->name === 'pipe_route';
        $factOutput = $output;

        if ($isPipeRoute && ($factOutput['drillings_needed'] ?? null) === 'no') {
            // Afwezigheid van zichtbaar gat ≠ bewijs van geen doorboring.
            $factOutput['drillings_needed'] = 'unknown';
            $factOutput['drillings_proposal_note'] = 'geen bewijs voor doorboring zichtbaar';
        }

        IntakeExternalFact::query()->updateOrCreate(
            [
                'intake_id' => $intake->id,
                'fact_key' => $this->factKey($photoQuestionKey, $sectionInstanceKey),
                'source' => self::SOURCE,
            ],
            [
                'label' => $isPipeRoute
                    ? 'Voorstel leidingroute uit foto'
                    : 'Automatische beoordeling van '.$photoQuestionKey,
                'value' => array_filter([
                    ...$factOutput,
                    'profile' => $profile->name,
                    'provider' => $run->provider,
                    'model' => $run->model,
                    'upload_ids' => $uploads->pluck('id')->values()->all(),
                    'ai_trace_id' => $traceId,
                    'proposal_fields' => $isPipeRoute
                        ? TechnicalDecisionKeys::ROUTE_PROPOSAL_KEYS
                        : [],
                    'uncertainty_note' => $isPipeRoute
                        ? 'Technische route-/doorboringconclusies zijn voorstellen voor de installateur; afwezigheid van een zichtbaar gat bewijst geen “geen doorboring”.'
                        : null,
                    'reason' => is_string($factOutput['evidence'] ?? null) ? $factOutput['evidence'] : null,
                ], static fn (mixed $value): bool => $value !== null),
                'source_reference' => 'ai-run:'.$run->id,
                'source_url' => null,
                'confidence' => $this->observationConfidence($profile, $factOutput),
                'captured_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $output
     */

    /**
     * @param  array<string, mixed>  $output
     * @param  array{derived: list<string>, suggested: list<string>}  $applied
     * @return list<array<string, mixed>>
     */
    private function fieldOutcomesFromPhoto(
        Intake $intake,
        array $output,
        PhotoDerivationProfile $profile,
        array $applied,
        ?string $sectionInstanceKey,
    ): array {
        $outcomes = [];
        $confidence = (string) ($output['confidence'] ?? 'low');

        foreach ($profile->fields as $field) {
            $key = $field->questionKey;
            $hasKey = array_key_exists($field->outputKey, $output);
            $rawValue = $hasKey ? (string) $output[$field->outputKey] : null;

            if (in_array($key, $applied['derived'], true)) {
                $outcomes[] = [
                    'question_key' => $key,
                    'output_key' => $field->outputKey,
                    'disposition' => 'accepted',
                    'confidence' => $confidence,
                    'source' => self::SOURCE,
                    'reason' => 'accepted_'.$confidence,
                    'has_value' => true,
                ];

                continue;
            }

            if (in_array($key, $applied['suggested'], true)) {
                $outcomes[] = [
                    'question_key' => $key,
                    'output_key' => $field->outputKey,
                    'disposition' => 'suggested',
                    'confidence' => $confidence,
                    'source' => self::SOURCE,
                    'reason' => 'suggested_'.$confidence,
                    'has_value' => true,
                ];

                continue;
            }

            $reason = match (true) {
                ! $hasKey => 'missing',
                $rawValue === 'unknown' => 'unknown',
                $confidence === 'low' => 'low_confidence',
                ! $this->mayOverwrite($intake, $field, $sectionInstanceKey) => 'protected_user_edit',
                default => 'skipped',
            };

            $outcomes[] = [
                'question_key' => $key,
                'output_key' => $field->outputKey,
                'disposition' => 'rejected',
                'confidence' => $confidence,
                'source' => self::SOURCE,
                'reason' => $reason,
                'has_value' => $hasKey && $rawValue !== 'unknown',
            ];
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function hasRoomTypeTextConflict(
        Intake $intake,
        array $output,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
    ): bool {
        if ($profile->name !== 'room') {
            return false;
        }

        $roomTypeField = null;
        foreach ($profile->fields as $candidate) {
            if ($candidate->questionKey === 'room_type') {
                $roomTypeField = $candidate;
                break;
            }
        }

        if ($roomTypeField === null) {
            return false;
        }

        $photoType = (string) ($output[$roomTypeField->outputKey] ?? 'unknown');
        if ($photoType === 'unknown') {
            return false;
        }

        $typeConflict = $this->textConflict($intake, $roomTypeField, $sectionInstanceKey, $photoType);
        if ($typeConflict === null) {
            return false;
        }

        $this->recordPhotoTextConflict(
            $intake,
            'room_type',
            $sectionInstanceKey,
            $typeConflict,
            $photoType,
        );

        return true;
    }

    /**
     * @return array{source: string, value: array<string, mixed>}|null
     */
    private function textConflict(
        Intake $intake,
        DerivedAnswerField $field,
        ?string $sectionInstanceKey,
        string $photoValue,
    ): ?array {
        // Afgeleide grootte uit L×B telt niet als tekstfeit tegen foto-size.
        if ($field->questionKey === 'room_size_indication') {
            return null;
        }

        $query = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $field->questionKey);

        $sectionInstanceKey === null
            ? $query->whereNull('section_instance_key')
            : $query->where('section_instance_key', $sectionInstanceKey);

        $existing = $query->first();
        if (! $existing instanceof IntakeAnswer || ! PrefillSources::isTextSide($existing->prefill_source)) {
            return null;
        }

        // derived_lxw op oppervlak: wel tekstzijde voor overwrite-guard, maar size apart hierboven.
        if ($existing->prefill_source === PrefillSources::DERIVED_LXW
            && $field->questionKey === 'room_area_m2') {
            return null;
        }

        $existingComparable = $existing->value['value'] ?? $existing->value['bool'] ?? $existing->value['number'] ?? null;
        $photoComparable = $field->answerValue($photoValue)['value']
            ?? $field->answerValue($photoValue)['bool']
            ?? $field->answerValue($photoValue)['number']
            ?? null;

        if ($existingComparable === $photoComparable) {
            return null;
        }

        return [
            'source' => (string) $existing->prefill_source,
            'value' => $existing->value,
        ];
    }

    /**
     * @param  array{source: string, value: array<string, mixed>}  $conflict
     */
    private function recordPhotoTextConflict(
        Intake $intake,
        string $questionKey,
        ?string $sectionInstanceKey,
        array $conflict,
        string $photoValue,
    ): void {
        $code = 'photo_text_conflict:'.$questionKey.($sectionInstanceKey !== null ? ':'.$sectionInstanceKey : '');

        $existingPoint = IntakeAttentionPoint::query()
            ->where('intake_id', $intake->id)
            ->where('code', $code)
            ->where('status', AttentionPointStatus::Proposed)
            ->first();

        $label = 'Foto wijkt af van de aanvraagtekst bij “'.$questionKey.'”'
            .($sectionInstanceKey !== null ? ' ('.$sectionInstanceKey.')' : '')
            .'. Tekst blijft leidend; foto-uitkomst is voorstel ('.$photoValue.').';

        if ($existingPoint instanceof IntakeAttentionPoint) {
            $existingPoint->update([
                'label' => $label,
                'ai_confidence' => 'medium',
            ]);

            return;
        }

        IntakeAttentionPoint::query()->create([
            'intake_id' => $intake->id,
            'source' => AttentionPointSource::Ai,
            'code' => $code,
            'label' => $label,
            'status' => AttentionPointStatus::Proposed,
            'ai_confidence' => 'medium',
            'evidence' => [
                [
                    'source_type' => 'answer',
                    'reference' => $questionKey.($sectionInstanceKey !== null ? '::'.$sectionInstanceKey : ''),
                ],
            ],
            'is_resolved' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function observationConfidence(PhotoDerivationProfile $profile, array $output): string
    {
        if ($profile->name === 'pipe_route') {
            return 'low';
        }

        return ($output['subject_match'] ?? 'yes') === 'no' ? 'low' : 'medium';
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{derived: list<string>, suggested: list<string>}
     */
    private function applyDerivedAnswers(
        Intake $intake,
        array $output,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
    ): array {
        $confidence = (string) $output['confidence'];
        $applied = [];

        if ($profile->name === 'room') {
            $outletApplied = $this->applyRoomOutletStatus(
                $intake,
                $output,
                $sectionInstanceKey,
                $confidence,
            );

            if ($outletApplied !== null) {
                $applied[] = $outletApplied;
            }
        }

        if ($confidence === 'low') {
            return ['derived' => $applied, 'suggested' => []];
        }

        $source = $confidence === 'high' ? self::SOURCE_DERIVED : self::SOURCE_SUGGESTED;
        $suggested = [];
        $derived = $applied;

        foreach ($profile->fields as $field) {
            if ($field->questionKey === 'room_outlet_status') {
                continue;
            }

            // Technische routeconclusies: alleen fact/dossier, nooit klant-intake_answer.
            if (TechnicalDecisionKeys::isRouteProposal($field->questionKey)) {
                continue;
            }

            $value = (string) ($output[$field->outputKey] ?? 'unknown');

            if ($value === 'unknown' && ! $field->allowsPersistingUnknown()) {
                continue;
            }

            if ($value !== 'unknown' && ! in_array($value, $field->allowedValues, true) && ! in_array($value, $field->schemaValues(), true)) {
                continue;
            }

            $conflict = $this->textConflict($intake, $field, $sectionInstanceKey, $value);
            if ($conflict !== null) {
                $this->recordPhotoTextConflict($intake, $field->questionKey, $sectionInstanceKey, $conflict, $value);

                continue;
            }

            if (! $this->mayOverwrite($intake, $field, $sectionInstanceKey)) {
                continue;
            }

            $this->saveIntakeAnswer->handle(
                $intake,
                $field->questionKey,
                $sectionInstanceKey,
                $field->answerValue($value),
                $source,
            );

            if ($source === self::SOURCE_DERIVED) {
                $derived[] = $field->questionKey;
            } else {
                $suggested[] = $field->questionKey;
            }
        }

        return ['derived' => $derived, 'suggested' => $suggested];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function applyRoomOutletStatus(
        Intake $intake,
        array $output,
        ?string $sectionInstanceKey,
        string $confidence,
    ): ?string {
        $field = null;

        foreach (PhotoDerivationProfile::require('room')->fields as $candidate) {
            if ($candidate->questionKey === 'room_outlet_status') {
                $field = $candidate;
                break;
            }
        }

        if ($field === null || ! $this->mayOverwrite($intake, $field, $sectionInstanceKey)) {
            return null;
        }

        if (! $this->intakeHasQuestion($intake, 'room_outlet_status')) {
            return null;
        }

        $raw = (string) ($output['room_outlet_status'] ?? 'unknown');

        // unknown: geen answer → geen wall_outlet_photo (lege woonkamer forceren geen stopcontactvraag).
        if ($raw === 'unknown' || $raw === '') {
            return null;
        }

        if ($raw === 'present' && $confidence === 'high') {
            $status = 'present';
        } elseif ($raw === 'needs_photo') {
            $status = 'needs_photo';
        } else {
            // present met medium/low: geen harde claim en geen extra foto forceren.
            return null;
        }

        $this->saveIntakeAnswer->handle(
            $intake,
            'room_outlet_status',
            $sectionInstanceKey,
            ['value' => $status],
            self::SOURCE_DERIVED,
        );

        return 'room_outlet_status';
    }

    private function intakeHasQuestion(Intake $intake, string $questionKey): bool
    {
        $intake->loadMissing('templateVersion.sections.questions');

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mayOverwrite(Intake $intake, DerivedAnswerField $field, ?string $sectionInstanceKey): bool
    {
        $query = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $field->questionKey);

        $sectionInstanceKey === null
            ? $query->whereNull('section_instance_key')
            : $query->where('section_instance_key', $sectionInstanceKey);

        $existing = $query->first();

        if (! $existing instanceof IntakeAnswer) {
            return true;
        }

        return PrefillSources::photoMayOverwrite($existing->prefill_source);
    }

    private function factKey(string $photoQuestionKey, ?string $sectionInstanceKey): string
    {
        return $sectionInstanceKey === null
            ? $photoQuestionKey.'_derivation'
            : $photoQuestionKey.'_derivation::'.$sectionInstanceKey;
    }

    private function correlationIdForUpload(IntakeUpload $upload): string
    {
        $timings = is_array($upload->processing_timings) ? $upload->processing_timings : [];
        if (is_string($timings['correlation_id'] ?? null) && $timings['correlation_id'] !== '') {
            return (string) $timings['correlation_id'];
        }

        $id = (string) Str::uuid();
        $timings['correlation_id'] = $id;
        $upload->update(['processing_timings' => $timings]);

        return $id;
    }
}
