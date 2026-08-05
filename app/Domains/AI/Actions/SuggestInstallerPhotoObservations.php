<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Maakt maximaal drie beslisrelevante, bevestigbare constateringen uit één
 * installateursfoto. De foto blijft het bewijs; AI-uitvoer blijft een voorstel
 * totdat de installateur haar bevestigt of aanpast.
 */
final class SuggestInstallerPhotoObservations
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
        private readonly DossierManager $dossierManager,
    ) {}

    public function handle(
        Intake $intake,
        DossierSubject $subject,
        IntakeUpload $upload,
    ): ?AiRun {
        if (! (bool) config('ai.photo_inference.enabled', false)) {
            return null;
        }

        $this->guardContext($intake, $subject, $upload);

        $promptName = (string) config(
            'ai.installer_photo_observation_prompt',
            'installer_photo_observation',
        );
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);
        $imageIdentity = $this->aiImageResolver->identity($upload);
        $subjectIdentity = [
            'type' => $subject->type,
            'context' => $this->safeSubjectContext($subject),
        ];
        $input = [
            'task' => 'suggest_installer_photo_observations',
            'subject' => $subjectIdentity,
            'allowed_impacts' => ['feasibility', 'materials', 'cost', 'installation'],
            'image' => $imageIdentity,
        ];
        $inputHash = hash('sha256', (string) json_encode([
            'prompt_version' => $promptVersion,
            'upload_id' => $upload->id,
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
                images: [$this->aiImageResolver->input($upload)],
            );
            $output = $this->validateOutput($result->output);

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);
            $run = $run->fresh() ?? $run;

            DB::transaction(function () use (
                $intake,
                $subject,
                $upload,
                $imageIdentity,
                $subjectIdentity,
                $run,
                $output,
            ): void {
                Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
                $currentSubject = DossierSubject::query()
                    ->whereKey($subject->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $currentUpload = IntakeUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
                $this->guardContext($intake, $currentSubject, $currentUpload);

                if ($this->aiImageResolver->identity($currentUpload) !== $imageIdentity
                    || [
                        'type' => $currentSubject->type,
                        'context' => $this->safeSubjectContext($currentSubject),
                    ] !== $subjectIdentity) {
                    throw new \RuntimeException('Foto of dossieronderdeel gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
                }

                $minimumConfidence = max(
                    0.0,
                    min(1.0, (float) config('ai.photo_inference.observation_min_confidence', 0.65)),
                );
                $recordIds = [];

                foreach ($output['observations'] as $index => $observation) {
                    if ($observation['confidence'] < $minimumConfidence) {
                        continue;
                    }

                    $record = $this->dossierManager->record(
                        intake: $intake,
                        subject: $currentSubject,
                        kind: DossierRecordKind::Observation,
                        key: 'photo_observation.'.$upload->id.'.'.($index + 1),
                        value: [
                            'text' => $observation['text'],
                            'impact' => $observation['impact'],
                        ],
                        actorType: 'system',
                        actorId: null,
                        sourceType: 'ai',
                        sourceId: $run->id,
                        method: 'photo_inference',
                        confidence: $observation['confidence'],
                        status: DossierRecordStatus::Proposed,
                        evidence: [
                            ['type' => 'intake_upload', 'id' => $upload->id],
                            ['type' => 'ai_run', 'id' => $run->id],
                        ],
                    );
                    $recordIds[] = $record->id;
                }

                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'event' => 'installer_photo_observations_suggested',
                    'properties' => [
                        'ai_run_id' => $run->id,
                        'upload_id' => $upload->id,
                        'subject_key' => $currentSubject->key,
                        'record_ids' => $recordIds,
                    ],
                    'created_at' => now(),
                ]);

                $run->update([
                    'output' => [
                        ...$output,
                        'stored_observation_count' => count($recordIds),
                    ],
                ]);
            }, 3);

            return $run->fresh() ?? $run;
        } catch (Throwable $exception) {
            Log::warning('Installer photo observation suggestion failed', [
                'intake_id' => $intake->id,
                'upload_id' => $upload->id,
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

    private function guardContext(
        Intake $intake,
        DossierSubject $subject,
        IntakeUpload $upload,
    ): void {
        $linked = DossierEvidenceLink::query()
            ->where('intake_id', $intake->id)
            ->where('dossier_subject_id', $subject->id)
            ->where('evidence_type', 'intake_upload')
            ->where('evidence_id', $upload->id)
            ->exists();

        if ($subject->intake_id !== $intake->id
            || $subject->company_id !== $intake->company_id
            || ! in_array($subject->type, ['airco_room', 'airco_placement'], true)
            || $upload->intake_id !== $intake->id
            || ! $linked) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto hoort niet bij dit onderdeel van de opname.',
            ]);
        }
    }

    /**
     * Alleen technische typecontext; nooit vrije klant- of locatiegegevens.
     *
     * @return array<string, string|int|float|bool|null>
     */
    private function safeSubjectContext(DossierSubject $subject): array
    {
        $allowedKeys = ['placement_type'];
        $context = [];

        foreach ($allowedKeys as $key) {
            $value = $subject->meta[$key] ?? null;

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{observations: list<array{text: string, impact: string, confidence: float}>}
     */
    private function validateOutput(array $output): array
    {
        $validator = Validator::make($output, [
            'observations' => ['present', 'array', 'max:3'],
            'observations.*.text' => ['required', 'string', 'min:3', 'max:300'],
            'observations.*.impact' => [
                'required',
                Rule::in(['feasibility', 'materials', 'cost', 'installation']),
            ],
            'observations.*.confidence' => ['required', 'numeric', 'between:0,1'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{observations: list<array{text: string, impact: string, confidence: int|float|string}>} $validated */
        $validated = $validator->validated();

        $observations = array_map(
            static fn (array $observation): array => [
                'text' => trim($observation['text']),
                'impact' => $observation['impact'],
                'confidence' => round((float) $observation['confidence'], 3),
            ],
            $validated['observations'],
        );

        foreach ($observations as $index => $observation) {
            if (mb_strlen($observation['text']) < 3) {
                throw ValidationException::withMessages([
                    'observations.'.($index + 1).'.text' => 'De fotoconstatering bevat geen bruikbare tekst.',
                ]);
            }
        }

        return ['observations' => $observations];
    }
}
