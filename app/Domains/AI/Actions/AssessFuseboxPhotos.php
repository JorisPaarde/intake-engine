<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
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

final class AssessFuseboxPhotos
{
    public const SOURCE = 'AI-fotoanalyse';

    private const PHOTO_QUESTION = 'fusebox_photo';

    private const TARGET_QUESTION = 'free_group_known';

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
    ) {}

    public function handle(Intake $intake): ?AiRun
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
        $manifest = $uploads->map(static fn (IntakeUpload $upload): array => [
            'upload_id' => $upload->id,
            'checksum' => $upload->checksum,
            'mime_type' => $upload->mime_type,
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

        $this->invalidateDerivedState($intake);

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
            $output = $this->validateOutput($result->output);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'provider' => $result->provider,
                'model' => $result->model,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;
            $this->storeObservation($intake, $run, $output, $uploads);
            $this->prefillFreeGroup($intake, $output);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'photo_assessment_completed',
                'properties' => [
                    'ai_run_id' => $run->id,
                    'question_key' => self::PHOTO_QUESTION,
                    'confidence' => $output['confidence'],
                    'free_group' => $output['free_group'],
                    'phase' => $output['phase'],
                ],
                'created_at' => now(),
            ]);

            return $run;
        } catch (Throwable $exception) {
            Log::warning('AI fusebox photo assessment failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
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

    public function invalidateDerivedState(Intake $intake): void
    {
        DB::transaction(function () use ($intake): void {
            IntakeExternalFact::query()
                ->where('intake_id', $intake->id)
                ->where('fact_key', 'fusebox_photo_assessment')
                ->where('source', self::SOURCE)
                ->delete();

            IntakeAnswer::query()
                ->where('intake_id', $intake->id)
                ->where('question_key', self::TARGET_QUESTION)
                ->whereNull('section_instance_key')
                ->where('prefill_source', 'ai')
                ->delete();
        });

        $intake->unsetRelation('answers');
        $intake->unsetRelation('externalFacts');
    }

    /** @return Collection<int, IntakeUpload> */
    private function uploads(Intake $intake): Collection
    {
        $maximum = max(1, min(3, (int) config('ai.photo_inference.max_images', 2)));

        return IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', self::PHOTO_QUESTION)
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
     * @return array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null}
     */
    private function validateOutput(array $output): array
    {
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

        return [
            ...$validated,
            'evidence' => trim($validated['evidence']),
            'retake_instruction' => is_string($validated['retake_instruction'])
                ? trim($validated['retake_instruction'])
                : null,
        ];
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
}
