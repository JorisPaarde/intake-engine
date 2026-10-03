<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
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
                if ($upload->contentAssessment() === null) {
                    $upload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
                }
            }

            return null;
        }

        $recentIds = $this->recentUploadIds($allUploads);
        $lastRun = null;
        $matchingOutputs = [];
        $anyMismatch = false;
        $assessedAny = false;

        foreach ($allUploads as $upload) {
            // Alleen uploads zonder assessment — bestaande content_assessment blijft staan.
            if ($upload->contentAssessment() !== null) {
                $existing = $upload->contentAssessment();
                if ($existing->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                    && ! $existing->customerAcceptedMismatch()) {
                    $anyMismatch = true;
                }

                continue;
            }

            $assessedAny = true;
            $run = $this->assessUpload(
                $intake,
                $upload,
                $photoQuestionKey,
                $sectionInstanceKey,
                $profile,
                $expected,
                $correlationId,
            );
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

                continue;
            }

            if ($run->status === AiRunStatus::Succeeded
                && is_array($run->output)
                && ($run->output['subject_match'] ?? 'yes') === 'yes'
                && in_array($upload->id, $recentIds, true)) {
                $matchingOutputs[] = $run->output;
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

        $this->invalidateDerivedState($intake, $photoQuestionKey, $sectionInstanceKey, $profile);

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

            if ($lastRun instanceof AiRun && is_array($output)) {
                DB::transaction(function () use ($intake, $lastRun, $output, $matchingUploads, $photoQuestionKey, $sectionInstanceKey, $profile, $anyMismatch): void {
                    $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

                    if (! in_array($lockedIntake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
                        throw new \RuntimeException('Opname is afgerond tijdens AI-analyse; resultaat niet toegepast.');
                    }

                    if ($this->hasRoomTypeTextConflict($intake, $output, $sectionInstanceKey, $profile)) {
                        $this->storeObservation($intake, $lastRun, $output, $matchingUploads, $photoQuestionKey, $sectionInstanceKey, $profile);
                        $applied = ['derived' => [], 'suggested' => []];
                    } else {
                        $this->storeObservation($intake, $lastRun, $output, $matchingUploads, $photoQuestionKey, $sectionInstanceKey, $profile);
                        $applied = $this->applyDerivedAnswers($intake, $output, $sectionInstanceKey, $profile);
                    }

                    IntakeActivityEvent::query()->create([
                        'intake_id' => $intake->id,
                        'actor_type' => 'system',
                        'actor_id' => null,
                        'event' => 'photo_answers_derived',
                        'properties' => [
                            'ai_run_id' => $lastRun->id,
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
            }
        } elseif ($anyMismatch && $lastRun instanceof AiRun) {
            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'photo_answers_derived',
                'properties' => [
                    'ai_run_id' => $lastRun->id,
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

        return $lastRun;
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

    private function assessUpload(
        Intake $intake,
        IntakeUpload $upload,
        string $photoQuestionKey,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
        PhotoSubject $expected,
        ?string $correlationId = null,
    ): AiRun {
        $promptVersion = $this->promptVersions->version($profile->promptName);
        $promptBody = $this->promptVersions->body($profile->promptName);

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
            $this->applyUploadAssessment($upload, $expected, $existing->output);

            return $existing;
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
            'subject_type' => 'section',
            'subject_id' => $sectionInstanceKey ?? $photoQuestionKey,
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
            );
            $trace->recordProviderResult($result);

            $output = $this->validateOutput($result->output, $profile);
            $trace->recordParsed($output, []);

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $this->applyUploadAssessment($upload, $expected, $output);

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
        } catch (Throwable $exception) {
            $trace->linkAiRun($run->fresh() ?? $run);
            $trace->fail($exception->getMessage(), $exception);
            Log::warning('AI photo answer derivation failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
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

            return $run->fresh() ?? $run;
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function applyUploadAssessment(IntakeUpload $upload, PhotoSubject $expected, array $output): void
    {
        $previous = $upload->contentAssessment();
        $assessment = PhotoContentAssessment::fromModelOutput($expected, $output)
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
     * @return array<string, mixed>
     */
    private function validateOutput(array $output, PhotoDerivationProfile $profile): array
    {
        $subjectValues = array_map(
            static fn (PhotoSubject $subject): string => $subject->value,
            PhotoSubject::cases(),
        );

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

        return $validated;
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
                'value' => [
                    ...$factOutput,
                    'profile' => $profile->name,
                    'provider' => $run->provider,
                    'model' => $run->model,
                    'upload_ids' => $uploads->pluck('id')->values()->all(),
                    'proposal_fields' => $isPipeRoute
                        ? TechnicalDecisionKeys::ROUTE_PROPOSAL_KEYS
                        : [],
                    'uncertainty_note' => $isPipeRoute
                        ? 'Technische route-/doorboringconclusies zijn voorstellen voor de installateur; afwezigheid van een zichtbaar gat bewijst geen “geen doorboring”.'
                        : null,
                    'reason' => is_string($factOutput['evidence'] ?? null) ? $factOutput['evidence'] : null,
                ],
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

            if ($value === 'unknown') {
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
}
