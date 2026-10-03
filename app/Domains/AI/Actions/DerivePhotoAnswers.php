<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiEnumNormalizer;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceSnapshotService;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\DerivedAnswerField;
use App\Domains\AI\Support\PhotoDerivationProfile;
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

/**
 * Derives confirmable answers from an uploaded photo set, for any question that opts in
 * through `meta.photo_analysis` (BL-020 generalised beyond the fusebox).
 *
 * Confidence decides how much work the applicant is left with:
 *   - `high`   → answer stored as SOURCE_DERIVED; the step disappears via
 *                `meta.skip_when_prefilled_by = 'ai'`. The evidence stays visible in the
 *                dossier as an external fact, so nothing is a hidden assumption.
 *   - `medium` → answer stored as SOURCE_SUGGESTED; the question is still asked, but
 *                pre-filled as a voorzet the applicant only has to confirm.
 *   - `low` or `unknown` → nothing stored; the question is asked normally.
 *
 * Re-uploading photos invalidates every earlier derivation for that photo question, so a
 * stale answer can never outlive the image it came from.
 */
final class DerivePhotoAnswers
{
    public const SOURCE = 'AI-fotoanalyse';

    /** Answer is trusted enough to replace the question entirely. */
    public const SOURCE_DERIVED = 'ai';

    /** Answer is only a voorzet; the applicant still confirms it. */
    public const SOURCE_SUGGESTED = 'ai_suggestion';

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
        $uploads = $this->uploads($intake, $photoQuestionKey, $sectionInstanceKey);

        if ($uploads->isEmpty()) {
            $this->invalidateDerivedState($intake, $photoQuestionKey, $sectionInstanceKey, $profile);

            return null;
        }

        if (! (bool) config('ai.photo_inference.enabled', false)) {
            return null;
        }

        $promptVersion = $this->promptVersions->version($profile->promptName);
        $promptBody = $this->promptVersions->body($profile->promptName);
        $persistenceManifest = $uploads->map(fn (IntakeUpload $upload): array => [
            'id' => $upload->id,
            ...$this->aiImageResolver->identity($upload),
        ])->values()->all();

        $input = [
            'task' => 'derive_answers_from_photos',
            'profile' => $profile->name,
            'expected_fields' => $this->schema($profile),
            'images' => $uploads
                ->map(fn (IntakeUpload $upload): array => $this->aiImageResolver->identity($upload))
                ->values()
                ->all(),
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

        // Do NOT invalidate before the provider call succeeds — a failed AI call must
        // never wipe existing dossier answers (klanttest 2026-10-02).

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
            'subject_type' => 'section',
            'subject_id' => $sectionInstanceKey ?? $photoQuestionKey,
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
                    fn (IntakeUpload $upload): array => $this->photoRefBuilder->fromUpload($upload, $profile->name),
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

            $run = $run->fresh() ?? $run;

            try {
                $trace->beginBuffer();
                DB::transaction(function () use ($intake, $run, $output, $uploads, $photoQuestionKey, $sectionInstanceKey, $profile, $persistenceManifest, $trace): void {
                    $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

                    if (! in_array($lockedIntake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
                        throw new \RuntimeException('Opname is afgerond tijdens AI-analyse; resultaat niet toegepast.');
                    }

                    $currentManifest = $this->uploads($intake, $photoQuestionKey, $sectionInstanceKey)
                        ->map(fn (IntakeUpload $upload): array => [
                            'id' => $upload->id,
                            ...$this->aiImageResolver->identity($upload),
                        ])->values()->all();

                    if ($currentManifest !== $persistenceManifest) {
                        throw new \RuntimeException('Foto’s gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
                    }

                    // Invalidate only after a successful, validated provider result.
                    $this->invalidateDerivedState($intake, $photoQuestionKey, $sectionInstanceKey, $profile);
                    $trace->step('invalidate_previous', [
                        'photo_question_key' => $photoQuestionKey,
                        'section_instance_key' => $sectionInstanceKey,
                    ]);

                    $this->storeObservation($intake, $run, $output, $uploads, $photoQuestionKey, $sectionInstanceKey, $profile);
                    $applied = $this->applyDerivedAnswers($intake, $output, $sectionInstanceKey, $profile);
                    $trace->step('apply', $applied);
                    $trace->recordFieldOutcomes(
                        $this->fieldOutcomesFromPhoto($intake, $output, $profile, $applied, $sectionInstanceKey),
                    );

                    IntakeActivityEvent::query()->create([
                        'intake_id' => $intake->id,
                        'actor_type' => 'system',
                        'actor_id' => null,
                        'event' => 'photo_answers_derived',
                        // Keys and confidence only — never derived answer values in logs (ADR-0002).
                        'properties' => [
                            'ai_run_id' => $run->id,
                            'ai_trace_id' => $trace->traceId(),
                            'profile' => $profile->name,
                            'question_key' => $photoQuestionKey,
                            'section_instance_key' => $sectionInstanceKey,
                            'confidence' => $output['confidence'],
                            'derived_question_keys' => $applied['derived'],
                            'suggested_question_keys' => $applied['suggested'],
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
                $dossierAfter = $this->traceSnapshots->answers($intake->fresh() ?? $intake);
                $trace->recordDossierSnapshots(
                    $dossierBefore,
                    $dossierAfter,
                    $this->traceSnapshots->changedFields($dossierBefore, $dossierAfter),
                );
                $trace->recordRemainingQuestions(
                    $questionsBefore,
                    $this->traceSnapshots->remainingQuestions($intake->fresh() ?? $intake),
                );
            }
            $trace->succeed();

            return $run;
        } catch (Throwable $exception) {
            Log::warning('AI photo answer derivation failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
                'ai_trace_id' => $trace->traceId(),
                'profile' => $profile->name,
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
     * Drops every answer and fact this profile previously derived for these photos, so a
     * replaced photo never leaves a stale conclusion behind. Answers the applicant edited
     * themselves (no AI prefill source) are left untouched.
     */
    public function invalidateDerivedState(
        Intake $intake,
        string $photoQuestionKey,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
    ): void {
        DB::transaction(function () use ($intake, $photoQuestionKey, $sectionInstanceKey, $profile): void {
            Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            IntakeExternalFact::query()
                ->where('intake_id', $intake->id)
                ->where('fact_key', $this->factKey($photoQuestionKey, $sectionInstanceKey))
                ->where('source', self::SOURCE)
                ->delete();

            $query = IntakeAnswer::query()
                ->where('intake_id', $intake->id)
                ->whereIn('question_key', $profile->questionKeys())
                ->whereIn('prefill_source', [self::SOURCE_DERIVED, self::SOURCE_SUGGESTED]);

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
    private function uploads(Intake $intake, string $photoQuestionKey, ?string $sectionInstanceKey): Collection
    {
        $maximum = max(1, min(3, (int) config('ai.photo_inference.max_images', 2)));

        $query = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $photoQuestionKey);

        $sectionInstanceKey === null
            ? $query->whereNull('section_instance_key')
            : $query->where('section_instance_key', $sectionInstanceKey);

        return $query
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
     * @return array{0: array<string, mixed>, 1: list<array{field: string, from: mixed, to: mixed, rule: string}>}
     */
    private function validateOutput(array $output, PhotoDerivationProfile $profile): array
    {
        $normalizations = [];
        $enums = app(AiEnumNormalizer::class);

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
    ): void {
        IntakeExternalFact::query()->updateOrCreate(
            [
                'intake_id' => $intake->id,
                'fact_key' => $this->factKey($photoQuestionKey, $sectionInstanceKey),
                'source' => self::SOURCE,
            ],
            [
                'label' => 'Automatische beoordeling van '.$photoQuestionKey,
                'value' => [
                    ...$output,
                    'profile' => $profile->name,
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

        // BL-074: stopcontactstatus altijd vastleggen zodat een extra wandfoto kan
        // verschijnen — ook bij lage zekerheid — zonder een ja/nee-klantvraag.
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

        foreach ($profile->fields as $field) {
            if ($field->questionKey === 'room_outlet_status') {
                continue;
            }

            $value = (string) ($output[$field->outputKey] ?? 'unknown');

            if ($value === 'unknown') {
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

            $applied[] = $field->questionKey;
        }

        return $confidence === 'high'
            ? ['derived' => $applied, 'suggested' => []]
            : ['derived' => [], 'suggested' => $applied];
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
        $status = ($confidence === 'high' && $raw === 'present')
            ? 'present'
            : 'needs_photo';

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

    /**
     * An answer the applicant or installer already gave always wins over a derivation.
     */
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

        return in_array($existing->prefill_source, [self::SOURCE_DERIVED, self::SOURCE_SUGGESTED], true);
    }

    private function factKey(string $photoQuestionKey, ?string $sectionInstanceKey): string
    {
        return $sectionInstanceKey === null
            ? $photoQuestionKey.'_derivation'
            : $photoQuestionKey.'_derivation::'.$sectionInstanceKey;
    }
}
