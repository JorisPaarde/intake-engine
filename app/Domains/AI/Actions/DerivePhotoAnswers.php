<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        private readonly PromptVersionRepository $promptVersions,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
    ) {}

    public function handle(
        Intake $intake,
        string $photoQuestionKey,
        ?string $sectionInstanceKey,
        PhotoDerivationProfile $profile,
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

        $input = [
            'task' => 'derive_answers_from_photos',
            'profile' => $profile->name,
            'expected_fields' => $this->schema($profile),
            'images' => $uploads->map(static fn (IntakeUpload $upload): array => [
                'upload_id' => $upload->id,
                'checksum' => $upload->checksum,
                'mime_type' => $upload->mime_type,
            ])->values()->all(),
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

        $this->invalidateDerivedState($intake, $photoQuestionKey, $sectionInstanceKey, $profile);

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

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
                images: $this->imageInputs($uploads),
            );

            $output = $this->validateOutput($result->output, $profile);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'provider' => $result->provider,
                'model' => $result->model,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;

            $this->storeObservation($intake, $run, $output, $uploads, $photoQuestionKey, $sectionInstanceKey, $profile);
            $applied = $this->applyDerivedAnswers($intake, $output, $sectionInstanceKey, $profile);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'photo_answers_derived',
                // Keys and confidence only — never derived answer values in logs (ADR-0002).
                'properties' => [
                    'ai_run_id' => $run->id,
                    'profile' => $profile->name,
                    'question_key' => $photoQuestionKey,
                    'section_instance_key' => $sectionInstanceKey,
                    'confidence' => $output['confidence'],
                    'derived_question_keys' => $applied['derived'],
                    'suggested_question_keys' => $applied['suggested'],
                ],
                'created_at' => now(),
            ]);

            return $run;
        } catch (Throwable $exception) {
            Log::warning('AI photo answer derivation failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
                'profile' => $profile->name,
                'exception' => $exception::class,
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            return $run->fresh() ?? $run;
        }
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
            if (! in_array($upload->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new \RuntimeException('Opgeslagen foto heeft geen ondersteund formaat voor beeldanalyse.');
            }

            $images[] = new AiImageInput(
                mimeType: $upload->mime_type,
                binary: Storage::disk($upload->disk)->get($upload->path),
            );
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function validateOutput(array $output, PhotoDerivationProfile $profile): array
    {
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

        if ($confidence === 'low') {
            return ['derived' => [], 'suggested' => []];
        }

        $source = $confidence === 'high' ? self::SOURCE_DERIVED : self::SOURCE_SUGGESTED;
        $applied = [];

        foreach ($profile->fields as $field) {
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
