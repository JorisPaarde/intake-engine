<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\AiTraceHandle;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceSnapshotService;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\IntakeStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AssessFuseboxPhotos
{
    public const SOURCE = 'AI-fotoanalyse';

    private const PHOTO_QUESTION = 'fusebox_photo';

    private const EXTRA_PHOTO_QUESTION = 'fusebox_photo_extra';

    private const TARGET_QUESTION = 'free_group_known';

    private const CLARITY_QUESTION = 'fusebox_clarity';

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTraceSnapshotService $traceSnapshots,
        private readonly AiTracePhotoRefBuilder $photoRefBuilder,
    ) {}

    public function handle(Intake $intake, ?string $correlationId = null): ?AiRun
    {
        $allUploads = $this->allUploads($intake);

        if ($allUploads->isEmpty()) {
            $this->invalidateDerivedState($intake);

            return null;
        }

        $expected = PhotoSubject::Fusebox;

        if (! (bool) config('ai.photo_inference.enabled', false)) {
            foreach ($allUploads as $upload) {
                if ($upload->contentAssessment() === null) {
                    $upload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
                }
            }

            return null;
        }

        $recentIds = $this->recentUploadIds($allUploads);
        $lastRun = null;
        /** @var list<array<string, mixed>> $matchingOutputs */
        $matchingOutputs = [];
        $hasMismatchOnly = true;
        $hasOkMatch = false;
        $needsClearer = false;
        $assessedAny = false;
        /** @var array{run: AiRun, trace: AiTraceHandle, dossier_before: array<string, mixed>, questions_before: array<string, mixed>}|null $applyContext */
        $applyContext = null;

        foreach ($allUploads as $upload) {
            if ($upload->contentAssessment() !== null) {
                $assessment = $upload->contentAssessment();
                if ($assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                    && ! $assessment->customerAcceptedMismatch()) {
                    continue;
                }

                $hasMismatchOnly = false;
                if ($assessment->status() === PhotoContentAssessment::STATUS_NEEDS_CLEARER) {
                    $needsClearer = true;
                }
                if ($assessment->status() === PhotoContentAssessment::STATUS_OK
                    || $assessment->customerAcceptedMismatch()) {
                    $hasOkMatch = true;
                }

                continue;
            }

            $assessedAny = true;
            $assessed = $this->assessUpload($intake, $upload, $expected, $correlationId);
            $run = $assessed['run'];
            $lastRun = $run;

            $fresh = $upload->fresh() ?? $upload;
            $assessment = $fresh->contentAssessment();

            if ($assessment === null) {
                $fresh->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
                $assessment = $fresh->fresh()?->contentAssessment()
                    ?? PhotoContentAssessment::notAssessed($expected);
            }

            if ($assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                && ! $assessment->customerAcceptedMismatch()) {
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

            $hasMismatchOnly = false;

            if ($assessment->status() === PhotoContentAssessment::STATUS_NEEDS_CLEARER) {
                $needsClearer = true;
            }

            if ($assessment->status() === PhotoContentAssessment::STATUS_OK
                || $assessment->customerAcceptedMismatch()) {
                $hasOkMatch = true;
            }

            $isMatching = $run->status === AiRunStatus::Succeeded
                && is_array($run->output)
                && ($run->output['subject_match'] ?? 'yes') === 'yes'
                && in_array($upload->id, $recentIds, true);

            if ($isMatching) {
                $matchingOutputs[] = $run->output;
                if ($assessed['trace'] instanceof AiTraceHandle) {
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

        $hasMismatch = $hasMismatchOnly && $allUploads->isNotEmpty();

        if (! $assessedAny && ! $hasMismatch) {
            return AiRun::query()
                ->where('intake_id', $intake->id)
                ->where('type', AiRunType::PhotoAssessment)
                ->where('status', AiRunStatus::Succeeded)
                ->latest('id')
                ->first() ?? $lastRun;
        }

        if ($matchingOutputs === [] && ! $hasMismatch) {
            return $lastRun;
        }

        if ($matchingOutputs !== []) {
            $output = $this->bestOutput($matchingOutputs);
            $matchingUploads = $allUploads->filter(static function (IntakeUpload $upload) use ($recentIds): bool {
                if (! in_array($upload->id, $recentIds, true)) {
                    return false;
                }

                $assessment = ($upload->fresh() ?? $upload)->contentAssessment();

                if ($assessment === null) {
                    return true;
                }

                return $assessment->status() !== PhotoContentAssessment::STATUS_WRONG_SUBJECT
                    || $assessment->customerAcceptedMismatch();
            })->values();

            if ($applyContext !== null) {
                $persistenceManifest = [$applyContext['persistence']];
                foreach ($matchingUploads as $matchingUpload) {
                    if ((int) $matchingUpload->id === (int) $applyContext['persistence']['id']) {
                        continue;
                    }
                    $persistenceManifest[] = [
                        'id' => $matchingUpload->id,
                        ...$this->aiImageResolver->identity($matchingUpload),
                    ];
                }
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
                $correlationId ??= (string) Str::uuid();
                $latestMatching = $matchingUploads->sortByDesc('id')->first();
                $trace = $this->traceRecorder->start($intake, AiTraceCallType::PhotoAnalysis, array_filter([
                    'ai_run_id' => $run?->id,
                    'upload_id' => $latestMatching?->id,
                    'subject_type' => 'question',
                    'subject_id' => self::PHOTO_QUESTION,
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

            if ($run instanceof AiRun) {
                try {
                    DB::transaction(function () use (
                        $intake,
                        $run,
                        $output,
                        $matchingUploads,
                        $needsClearer,
                        $hasOkMatch,
                        $hasMismatch,
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
                            throw new \RuntimeException('Meterkastfoto’s gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
                        }

                        $this->invalidateDerivedState($intake);
                        $trace->step('invalidate_previous', ['question_key' => self::PHOTO_QUESTION]);

                        $this->storeObservation($intake, $run, $output, $matchingUploads, $trace->traceId());

                        if ($hasOkMatch) {
                            $this->prefillFreeGroup($intake, $output);
                            $this->prefillClarity($intake, $this->clarityValue($output));
                        } elseif ($needsClearer) {
                            $this->prefillClarity($intake, 'needs_clearer_photo');
                        }

                        $trace->step('apply', [
                            'free_group' => $output['free_group'],
                            'confidence' => $output['confidence'],
                        ]);
                        $trace->recordFieldOutcomes($this->fieldOutcomesFromFusebox($intake, $output));

                        IntakeActivityEvent::query()->create([
                            'intake_id' => $intake->id,
                            'actor_type' => 'system',
                            'actor_id' => null,
                            'event' => 'photo_assessment_completed',
                            'properties' => [
                                'ai_run_id' => $run->id,
                                'ai_trace_id' => $trace->traceId(),
                                'question_key' => self::PHOTO_QUESTION,
                                'confidence' => $output['confidence'],
                                'free_group' => $output['free_group'],
                                'phase' => $output['phase'],
                                'clarity' => $hasOkMatch ? $this->clarityValue($output) : ($needsClearer ? 'needs_clearer_photo' : null),
                                'subject_match' => $output['subject_match'] ?? null,
                                'detected_subject' => $output['detected_subject'] ?? null,
                                'content_mismatch' => $hasMismatch,
                            ],
                            'created_at' => now(),
                        ]);
                    }, 3);
                    $trace->flushBuffer();
                } catch (Throwable $transactionException) {
                    $trace->discardBuffer();
                    Log::warning('AI fusebox photo assessment failed', [
                        'intake_id' => $intake->id,
                        'ai_run_id' => $run->id,
                        'ai_trace_id' => $trace->traceId(),
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
        } elseif ($hasMismatch) {
            $this->invalidateDerivedState($intake);

            if ($lastRun instanceof AiRun) {
                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'event' => 'photo_assessment_completed',
                    'properties' => [
                        'ai_run_id' => $lastRun->id,
                        'ai_trace_id' => isset($applyContext['trace']) ? $applyContext['trace']->traceId() : null,
                        'question_key' => self::PHOTO_QUESTION,
                        'content_mismatch' => true,
                        'clarity' => null,
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
     * @return array{
     *     run: AiRun,
     *     trace: AiTraceHandle|null,
     *     dossier_before: array<string, mixed>,
     *     questions_before: array<string, mixed>,
     *     persistence: array{id: int|string, checksum: mixed, mime_type: string, variant: string}
     * }
     */
    private function assessUpload(Intake $intake, IntakeUpload $upload, PhotoSubject $expected, ?string $correlationId = null): array
    {
        $promptName = (string) config('ai.fusebox_prompt', 'fusebox_assessment');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);

        $persistence = [
            'id' => $upload->id,
            ...$this->aiImageResolver->identity($upload),
        ];

        $input = [
            'task' => 'assess_fusebox_photos',
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
            $this->applyUploadAssessment($upload, $expected, $existing->output);

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
            'type' => AiRunType::PhotoAssessment,
            'provider' => (string) config('ai.provider', 'null'),
            'model' => null,
            'prompt_version' => $promptVersion,
            'input_hash' => $inputHash,
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        $correlationId ??= (string) Str::uuid();

        $trace = $this->traceRecorder->start($intake, AiTraceCallType::PhotoAnalysis, [
            'ai_run_id' => $run->id,
            'upload_id' => $upload->id,
            'subject_type' => 'question',
            'subject_id' => self::PHOTO_QUESTION,
            'provider' => (string) config('ai.provider', 'null'),
            'prompt_version' => $promptVersion,
            'correlation_id' => $correlationId,
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
                $photoRefs = [$this->photoRefBuilder->fromUpload($upload, 'fusebox')];
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
            );
            $trace->recordProviderResult($result);

            try {
                [$output, $normalizations] = $this->validateOutput($result->output);
                $trace->recordParsed($output, [], $normalizations);
            } catch (ValidationException $exception) {
                $trace->recordParsed([], $exception->errors());
                throw $exception;
            }
            $trace->step('normalize', [
                'confidence' => $output['confidence'],
                'normalization_count' => count($normalizations),
            ]);

            $current = IntakeUpload::query()->whereKey($upload->id)->first();
            if (! $current instanceof IntakeUpload
                || $this->aiImageResolver->identity($current) !== $this->aiImageResolver->identity($upload)) {
                throw new \RuntimeException('Meterkastfoto’s gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
            }

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $this->applyUploadAssessment($current, $expected, $output);

            $freshRun = $run->fresh() ?? $run;
            $trace->linkAiRun($freshRun);

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
            Log::warning('AI fusebox photo assessment failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
                'ai_trace_id' => $trace->traceId(),
                'upload_id' => $upload->id,
                'exception' => $exception::class,
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            if (IntakeUpload::query()->whereKey($upload->id)->exists()) {
                $upload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
            }

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
    private function applyUploadAssessment(IntakeUpload $upload, PhotoSubject $expected, array $output): void
    {
        $previous = $upload->contentAssessment();
        $assessment = PhotoContentAssessment::fromModelOutput($expected, $output);

        // Meterkast: onleesbare fase/lage confidence zonder retake → toch scherpere foto vragen.
        if ($assessment->status() === PhotoContentAssessment::STATUS_OK
            && (($output['confidence'] ?? '') !== 'high' || ($output['phase'] ?? '') === 'unknown')) {
            $message = is_string($output['retake_instruction'] ?? null) && trim((string) $output['retake_instruction']) !== ''
                ? trim((string) $output['retake_instruction'])
                : 'Maak een scherpere foto van de meterkast, recht van voren.';
            $assessment = PhotoContentAssessment::needsClearer($expected, $message);
        }

        $upload->storeContentAssessment($assessment->preservingCustomerAcceptance($previous));
    }

    /**
     * @param  list<array<string, mixed>>  $outputs
     * @return array<string, mixed>
     */
    private function bestOutput(array $outputs): array
    {
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

    public function invalidateDerivedState(Intake $intake): void
    {
        DB::transaction(function () use ($intake): void {
            Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            IntakeExternalFact::query()
                ->where('intake_id', $intake->id)
                ->where('fact_key', 'fusebox_photo_assessment')
                ->where('source', self::SOURCE)
                ->delete();

            IntakeAnswer::query()
                ->where('intake_id', $intake->id)
                ->whereIn('question_key', [self::TARGET_QUESTION, self::CLARITY_QUESTION])
                ->whereNull('section_instance_key')
                ->whereIn('prefill_source', [
                    PrefillSources::AI_PHOTO,
                    PrefillSources::AI_PHOTO_SUGGESTION,
                    PrefillSources::AI_SUGGESTION_LEGACY,
                    PrefillSources::AI_LEGACY,
                ])
                ->delete();
        });

        $intake->unsetRelation('answers');
        $intake->unsetRelation('externalFacts');
    }

    /** @return Collection<int, IntakeUpload> */
    private function allUploads(Intake $intake): Collection
    {
        return IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->whereIn('question_key', [self::PHOTO_QUESTION, self::EXTRA_PHOTO_QUESTION])
            ->whereNull('section_instance_key')
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * @param  Collection<int, IntakeUpload>  $all
     * @return list<int>
     */
    private function recentUploadIds(Collection $all): array
    {
        $maximum = max(1, min(4, (int) config('ai.photo_inference.max_images', 2) + 1));

        return $all->sortByDesc('id')->take($maximum)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null, detected_subject: string|null, subject_match: string}
     */
    /**
     * @param  array<string, mixed>  $output
     * @return array{0: array<string, mixed>, 1: list<array{field: string, from: mixed, to: mixed, rule: string}>}
     */
    private function validateOutput(array $output): array
    {
        $subjectValues = array_map(
            static fn (PhotoSubject $subject): string => $subject->value,
            PhotoSubject::cases(),
        );

        /** @var list<array{field: string, from: mixed, to: mixed, rule: string}> $normalizations */
        $normalizations = [];
        $validator = Validator::make($output, [
            'free_group' => ['required', Rule::in(['yes', 'no', 'unknown'])],
            'phase' => ['required', Rule::in(['one_phase', 'three_phase', 'unknown'])],
            'confidence' => ['required', Rule::in(['high', 'medium', 'low'])],
            'evidence' => ['required', 'string', 'min:3', 'max:300'],
            'retake_instruction' => ['nullable', 'string', 'min:5', 'max:300'],
            'detected_subject' => ['nullable', Rule::in($subjectValues)],
            'subject_match' => ['nullable', Rule::in(['yes', 'no'])],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null, detected_subject?: string|null, subject_match?: string} $validated */
        $validated = $validator->validated();

        $evidenceFrom = $validated['evidence'];
        $evidence = trim($validated['evidence']);
        if ($evidence !== $evidenceFrom) {
            $normalizations[] = [
                'field' => 'evidence',
                'from' => $evidenceFrom,
                'to' => $evidence,
                'rule' => 'trim',
            ];
        }

        $retakeFrom = $validated['retake_instruction'] ?? null;
        $retake = is_string($validated['retake_instruction'] ?? null)
            ? trim((string) $validated['retake_instruction'])
            : null;
        if ($retake !== $retakeFrom) {
            $normalizations[] = [
                'field' => 'retake_instruction',
                'from' => $retakeFrom,
                'to' => $retake,
                'rule' => 'trim',
            ];
        }

        return [[
            'free_group' => $validated['free_group'],
            'phase' => $validated['phase'],
            'confidence' => $validated['confidence'],
            'evidence' => $evidence,
            'retake_instruction' => $retake,
            'detected_subject' => is_string($validated['detected_subject'] ?? null)
                ? $validated['detected_subject']
                : null,
            'subject_match' => is_string($validated['subject_match'] ?? null)
                ? $validated['subject_match']
                : 'yes',
        ], $normalizations];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    private function storeObservation(Intake $intake, AiRun $run, array $output, Collection $uploads, ?string $traceId = null): void
    {
        IntakeExternalFact::query()->updateOrCreate(
            [
                'intake_id' => $intake->id,
                'fact_key' => 'fusebox_photo_assessment',
                'source' => self::SOURCE,
            ],
            [
                'label' => 'Automatische beoordeling meterkastfoto',
                'value' => array_filter([
                    ...$output,
                    'provider' => $run->provider,
                    'model' => $run->model,
                    'upload_ids' => $uploads->pluck('id')->values()->all(),
                    'ai_trace_id' => $traceId,
                ], static fn (mixed $value): bool => $value !== null),
                'source_reference' => 'ai-run:'.$run->id,
                'source_url' => null,
                'confidence' => ($output['subject_match'] ?? 'yes') === 'no' ? 'low' : 'medium',
                'captured_at' => now(),
            ],
        );
    }

    /**
     * @param  array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null}  $output
     * @return list<array<string, mixed>>
     */
    private function fieldOutcomesFromFusebox(Intake $intake, array $output): array
    {
        $confidence = $output['confidence'];
        $outcomes = [];

        $freeExisting = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', self::TARGET_QUESTION)
            ->whereNull('section_instance_key')
            ->first();
        $freeProtected = $freeExisting instanceof IntakeAnswer && ! in_array($freeExisting->prefill_source, [PrefillSources::AI_PHOTO, PrefillSources::AI_PHOTO_SUGGESTION, PrefillSources::AI_LEGACY], true);
        $freeAccepted = $confidence === 'high'
            && $output['free_group'] !== 'unknown'
            && $freeExisting instanceof IntakeAnswer
            && in_array($freeExisting->prefill_source, [PrefillSources::AI_PHOTO, PrefillSources::AI_PHOTO_SUGGESTION, PrefillSources::AI_LEGACY], true);

        $freeReason = match (true) {
            $freeAccepted => 'accepted_'.$confidence,
            $freeProtected => 'protected_user_edit',
            $output['free_group'] === 'unknown' => 'unknown',
            $confidence !== 'high' => 'low_confidence',
            default => 'missing',
        };

        $outcomes[] = [
            'question_key' => self::TARGET_QUESTION,
            'output_key' => 'free_group',
            'disposition' => $freeAccepted ? 'accepted' : 'rejected',
            'confidence' => $confidence,
            'source' => self::SOURCE,
            'reason' => $freeReason,
            'has_value' => $output['free_group'] !== 'unknown',
        ];

        if ($this->hasQuestion($intake, self::CLARITY_QUESTION)) {
            $clarityExisting = IntakeAnswer::query()
                ->where('intake_id', $intake->id)
                ->where('question_key', self::CLARITY_QUESTION)
                ->whereNull('section_instance_key')
                ->first();
            $clarityProtected = $clarityExisting instanceof IntakeAnswer
                && ! in_array($clarityExisting->prefill_source, [PrefillSources::AI_PHOTO, PrefillSources::AI_PHOTO_SUGGESTION, PrefillSources::AI_LEGACY], true);
            $clarityAccepted = $clarityExisting instanceof IntakeAnswer
                && in_array($clarityExisting->prefill_source, [PrefillSources::AI_PHOTO, PrefillSources::AI_PHOTO_SUGGESTION, PrefillSources::AI_LEGACY], true);

            $clarityReason = match (true) {
                $clarityAccepted => 'accepted_'.$confidence,
                $clarityProtected => 'protected_user_edit',
                default => 'missing',
            };

            $outcomes[] = [
                'question_key' => self::CLARITY_QUESTION,
                'output_key' => 'clarity',
                'disposition' => $clarityAccepted ? 'accepted' : 'rejected',
                'confidence' => $confidence,
                'source' => self::SOURCE,
                'reason' => $clarityReason,
                'has_value' => $clarityAccepted,
            ];
        }

        return $outcomes;
    }

    /** @param array<string, mixed> $output */
    private function prefillFreeGroup(Intake $intake, array $output): void
    {
        if ($output['confidence'] !== 'high' || $output['free_group'] === 'unknown') {
            return;
        }

        $existing = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', self::TARGET_QUESTION)
            ->whereNull('section_instance_key')
            ->first();

        if ($existing instanceof IntakeAnswer && ! PrefillSources::photoMayOverwrite($existing->prefill_source)) {
            return;
        }

        $this->saveIntakeAnswer->handle(
            $intake,
            self::TARGET_QUESTION,
            null,
            ['value' => $output['free_group']],
            PrefillSources::AI_PHOTO,
        );
    }

    private function prefillClarity(Intake $intake, string $clarity): void
    {
        if (! $this->hasQuestion($intake, self::CLARITY_QUESTION)) {
            return;
        }

        $existing = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', self::CLARITY_QUESTION)
            ->whereNull('section_instance_key')
            ->first();

        if ($existing instanceof IntakeAnswer && ! PrefillSources::photoMayOverwrite($existing->prefill_source)) {
            return;
        }

        $this->saveIntakeAnswer->handle(
            $intake,
            self::CLARITY_QUESTION,
            null,
            ['value' => $clarity],
            PrefillSources::AI_PHOTO,
        );
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function clarityValue(array $output): string
    {
        $phaseKnown = in_array($output['phase'], ['one_phase', 'three_phase'], true);
        $instruction = is_string($output['retake_instruction'] ?? null)
            ? trim((string) $output['retake_instruction'])
            : '';

        if ($output['confidence'] === 'high' && $phaseKnown && $instruction === '') {
            return 'clear';
        }

        return 'needs_clearer_photo';
    }

    private function hasQuestion(Intake $intake, string $questionKey): bool
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
}
