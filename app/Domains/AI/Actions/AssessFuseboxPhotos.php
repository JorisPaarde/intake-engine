<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceSnapshotService;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
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
        $uploads = $this->uploads($intake);

        if ($uploads->isEmpty()) {
            $this->invalidateDerivedState($intake);

            return null;
        }

        if (! (bool) config('ai.photo_inference.enabled', false)) {
            return null;
        }

        $promptName = (string) config('ai.fusebox_prompt', 'fusebox_assessment');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);
        $manifest = $uploads
            ->map(fn (IntakeUpload $upload): array => $this->aiImageResolver->identity($upload))
            ->values()
            ->all();
        $persistenceManifest = $uploads->map(fn (IntakeUpload $upload): array => [
            'id' => $upload->id,
            ...$this->aiImageResolver->identity($upload),
        ])->values()->all();
        $input = [
            'task' => 'assess_fusebox_photos',
            'images' => $manifest,
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

        if ($existing instanceof AiRun) {
            return $existing;
        }

        // Invalidate only after a successful provider result — failed calls must not wipe answers.

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

        $latestUpload = $uploads->sortByDesc('id')->first();
        $trace = $this->traceRecorder->start($intake, AiTraceCallType::PhotoAnalysis, array_filter([
            'ai_run_id' => $run->id,
            'upload_id' => $latestUpload?->id,
            'subject_type' => 'question',
            'subject_id' => self::PHOTO_QUESTION,
            'provider' => (string) config('ai.provider', 'null'),
            'prompt_version' => $promptVersion,
            'correlation_id' => $correlationId,
        ], static fn (mixed $value): bool => $value !== null));
        if ($latestUpload !== null) {
            $trace->linkUpload($latestUpload);
        }
        $dossierBefore = [];
        $questionsBefore = [];
        if (! $trace->isNoop()) {
            $dossierBefore = $this->traceSnapshots->answers($intake);
            $questionsBefore = $this->traceSnapshots->remainingQuestions($intake);
        }

        try {
            $photoRefs = [];
            if (! $trace->isNoop()) {
                $photoRefs = $uploads->map(
                    fn (IntakeUpload $upload): array => $this->photoRefBuilder->fromUpload($upload, 'fusebox'),
                )->values()->all();
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
                images: $this->imageInputs($uploads),
            );
            $trace->recordProviderResult($result);

            try {
                [$output, $normalizations] = $this->validateOutput($result->output);
                $trace->recordParsed($output, [], $normalizations);
            } catch (ValidationException $exception) {
                $trace->recordParsed([], $exception->errors());
                throw $exception;
            }

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;

            try {
                DB::transaction(function () use ($intake, $run, $output, $uploads, $persistenceManifest, $trace): void {
                    $trace->beginBuffer();
                    $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

                    if (! in_array($lockedIntake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
                        throw new \RuntimeException('Opname is afgerond tijdens AI-analyse; resultaat niet toegepast.');
                    }

                    $currentManifest = $this->uploads($intake)->map(fn (IntakeUpload $upload): array => [
                        'id' => $upload->id,
                        ...$this->aiImageResolver->identity($upload),
                    ])->values()->all();

                    if ($currentManifest !== $persistenceManifest) {
                        throw new \RuntimeException('Meterkastfoto’s gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
                    }

                    $this->invalidateDerivedState($intake);
                    $trace->step('invalidate_previous', ['question_key' => self::PHOTO_QUESTION]);

                    $this->storeObservation($intake, $run, $output, $uploads);
                    $this->prefillFreeGroup($intake, $output);
                    $this->prefillClarity($intake, $output);
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
                            'clarity' => $this->clarityValue($output),
                        ],
                        'created_at' => now(),
                    ]);
                }, 3);
                $trace->flushBuffer();
            } catch (Throwable $transactionException) {
                $trace->discardBuffer();
                throw $transactionException;
            }

            $trace->linkAiRun($run);
            $trace->stopProcessTimer();
            if (! $trace->isNoop()) {
                $freshIntake = $intake->fresh() ?? $intake;
                $dossierAfter = $this->traceSnapshots->answers($freshIntake);
                $trace->recordDossierSnapshots(
                    $dossierBefore,
                    $dossierAfter,
                    $this->traceSnapshots->changedFields($dossierBefore, $dossierAfter),
                );
                $trace->recordRemainingQuestions($questionsBefore, $this->traceSnapshots->remainingQuestions($freshIntake));
            }
            $trace->succeed();

            return $run;
        } catch (Throwable $exception) {
            Log::warning('AI fusebox photo assessment failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
                'ai_trace_id' => $trace->traceId(),
                'exception' => $exception::class,
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);
            $trace->linkAiRun($run->fresh() ?? $run);
            $trace->discardBuffer();
            $trace->fail($exception->getMessage(), $exception);

            return $run->fresh() ?? $run;
        }
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
                ->where('prefill_source', 'ai')
                ->delete();
        });

        $intake->unsetRelation('answers');
        $intake->unsetRelation('externalFacts');
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
        $freeProtected = $freeExisting instanceof IntakeAnswer && $freeExisting->prefill_source !== 'ai';
        $freeAccepted = $confidence === 'high'
            && $output['free_group'] !== 'unknown'
            && $freeExisting instanceof IntakeAnswer
            && $freeExisting->prefill_source === 'ai';

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
                && $clarityExisting->prefill_source !== 'ai';
            $clarityAccepted = $clarityExisting instanceof IntakeAnswer
                && $clarityExisting->prefill_source === 'ai';

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

    /** @return Collection<int, IntakeUpload> */
    private function uploads(Intake $intake): Collection
    {
        $maximum = max(1, min(4, (int) config('ai.photo_inference.max_images', 2) + 1));

        // Prefer the latest primary + extra meterkast photos so a sharper retake
        // can clear a previous low-confidence / unknown-phase verdict (BL-074).
        return IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->whereIn('question_key', [self::PHOTO_QUESTION, self::EXTRA_PHOTO_QUESTION])
            ->whereNull('section_instance_key')
            ->latest('id')
            ->limit($maximum)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return list<AiImageInput>
     */
    private function imageInputs(Collection $uploads): array
    {
        $images = [];

        foreach ($uploads as $upload) {
            $images[] = $this->aiImageResolver->input($upload);
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{0: array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null}, 1: list<array{field: string, from: mixed, to: mixed, rule: string}>}
     */
    private function validateOutput(array $output): array
    {
        /** @var list<array{field: string, from: mixed, to: mixed, rule: string}> $normalizations */
        $normalizations = [];
        $validator = Validator::make($output, [
            'free_group' => ['required', Rule::in(['yes', 'no', 'unknown'])],
            'phase' => ['required', Rule::in(['one_phase', 'three_phase', 'unknown'])],
            'confidence' => ['required', Rule::in(['high', 'medium', 'low'])],
            'evidence' => ['required', 'string', 'min:3', 'max:300'],
            'retake_instruction' => ['nullable', 'string', 'min:5', 'max:300'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null} $validated */
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

        $retakeFrom = $validated['retake_instruction'];
        $retake = is_string($validated['retake_instruction'])
            ? trim($validated['retake_instruction'])
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
            ...$validated,
            'evidence' => $evidence,
            'retake_instruction' => $retake,
        ], $normalizations];
    }

    /**
     * @param  array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null}  $output
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    private function storeObservation(Intake $intake, AiRun $run, array $output, Collection $uploads): void
    {
        IntakeExternalFact::query()->updateOrCreate(
            [
                'intake_id' => $intake->id,
                'fact_key' => 'fusebox_photo_assessment',
                'source' => self::SOURCE,
            ],
            [
                'label' => 'Automatische beoordeling meterkastfoto',
                'value' => [
                    ...$output,
                    'provider' => $run->provider,
                    'model' => $run->model,
                    'upload_ids' => $uploads->pluck('id')->values()->all(),
                ],
                'source_reference' => 'ai-run:'.$run->id,
                'source_url' => null,
                'confidence' => 'medium',
                'captured_at' => now(),
            ],
        );
    }

    /** @param array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null} $output */
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

        if ($existing instanceof IntakeAnswer && $existing->prefill_source !== 'ai') {
            return;
        }

        $this->saveIntakeAnswer->handle(
            $intake,
            self::TARGET_QUESTION,
            null,
            ['value' => $output['free_group']],
            'ai',
        );
    }

    /**
     * Drive the optional extra meterkast photo without a standalone 1-/3-fase question.
     *
     * @param  array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null}  $output
     */
    private function prefillClarity(Intake $intake, array $output): void
    {
        if (! $this->hasQuestion($intake, self::CLARITY_QUESTION)) {
            return;
        }

        $existing = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', self::CLARITY_QUESTION)
            ->whereNull('section_instance_key')
            ->first();

        if ($existing instanceof IntakeAnswer && $existing->prefill_source !== 'ai') {
            return;
        }

        $this->saveIntakeAnswer->handle(
            $intake,
            self::CLARITY_QUESTION,
            null,
            ['value' => $this->clarityValue($output)],
            'ai',
        );
    }

    /**
     * @param  array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null}  $output
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
