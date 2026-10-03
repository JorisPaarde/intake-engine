<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceSnapshotService;
use App\Domains\AI\Services\AiValidationFailureFormatter;
use App\Domains\AI\Services\DossierSynthesisJsonSchema;
use App\Domains\AI\Services\DossierSynthesisOutputNormalizer;
use App\Domains\AI\Services\DossierSynthesisPartialAcceptor;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Services\SurveySynthesisContextBuilder;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\ContributionAudience;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Produces non-binding, evidence-bound dossier proposals.
 *
 * Candidate options and customer tasks are stored as proposals only. No connection
 * becomes approved and no customer link is activated by this action.
 */
final class SynthesizeSurveyDossier
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
        private readonly SurveySynthesisContextBuilder $contextBuilder,
        private readonly DossierManager $dossierManager,
        private readonly DecisionReadinessService $decisionReadiness,
        private readonly DossierSynthesisOutputNormalizer $outputNormalizer,
        private readonly DossierSynthesisPartialAcceptor $partialAcceptor,
        private readonly DossierSynthesisJsonSchema $jsonSchema,
        private readonly AiValidationFailureFormatter $validationFailureFormatter,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTraceSnapshotService $traceSnapshots,
        private readonly AiTracePhotoRefBuilder $photoRefBuilder,
    ) {}

    public function handle(Intake $intake): ?AiRun
    {
        if (! (bool) config('ai.dossier.enabled', false)) {
            return null;
        }

        $run = null;
        $trace = null;
        $imageUploads = collect();
        $result = null;
        $model = (string) config('ai.dossier.model', 'gpt-5.6-terra');

        try {
            $promptName = (string) config('ai.dossier.prompt', 'dossier_synthesis');
            $promptVersion = $this->promptVersions->version($promptName);
            $promptBody = $this->promptVersions->body($promptName);
            $model = (string) config('ai.dossier.model', 'gpt-5.6-terra');
            $input = $this->contextBuilder->build($intake);
            $imageUploads = $this->imageUploads($intake);
            $input['image_manifest'] = $this->imageManifest($imageUploads);
            $input['synthesis_policy'] = $this->synthesisPolicy($intake, $imageUploads);
            $inputHash = $this->hash($input, $promptVersion, $model);

            $run = AiRun::query()->create([
                'intake_id' => $intake->id,
                'type' => AiRunType::DossierSynthesis,
                'provider' => (string) config('ai.provider', 'null'),
                'model' => $model,
                'prompt_version' => $promptVersion,
                'input_hash' => $inputHash,
                'output' => null,
                'status' => AiRunStatus::Pending,
                'started_at' => now(),
            ]);

            $trace = $this->traceRecorder->start($intake, AiTraceCallType::DossierSynthesis, [
                'ai_run_id' => $run->id,
                'provider' => (string) config('ai.provider', 'null'),
                'prompt_version' => $promptVersion,
            ]);
            $dossierBefore = [];
            $questionsBefore = [];
            $photoRefs = [];
            if (! $trace->isNoop()) {
                $dossierBefore = $this->traceSnapshots->answers($intake);
                $questionsBefore = $this->traceSnapshots->remainingQuestions($intake);
                $photoRefs = $imageUploads->map(
                    fn (IntakeUpload $upload): array => $this->photoRefBuilder->fromUpload($upload, 'dossier'),
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
                images: $imageUploads
                    ->map(fn (IntakeUpload $upload) => $this->aiImageResolver->input($upload))
                    ->values()
                    ->all(),
                model: $model,
                responseSchema: [
                    'name' => 'dossier_synthesis',
                    'schema' => $this->jsonSchema->schema(),
                ],
                timeoutSeconds: max(1, (int) config('ai.dossier.timeout_seconds', 45)),
            );
            $trace->recordProviderResult($result);

            // Persist usage even if later validation drops everything.
            $run->update($run->completionResultAttributes($result, $model));

            $normalizedDiff = $this->outputNormalizer->normalizeWithDiff($result->output);
            $normalized = $normalizedDiff['output'];
            $normalizations = $normalizedDiff['normalizations'];
            $trace->step('normalize', [
                'keys' => array_keys($normalized),
                'normalization_count' => count($normalizations),
            ]);

            $acceptance = $this->partialAcceptor->accept($normalized, $input);
            $trace->recordParsed(
                $acceptance['accepted'],
                $acceptance['validation_errors'],
                $normalizations,
            );
            $trace->recordFieldOutcomes($acceptance['field_outcomes']);

            if (! $acceptance['has_accepted_proposals']) {
                throw ValidationException::withMessages(
                    $acceptance['validation_errors'] !== []
                        ? $acceptance['validation_errors']
                        : ['output' => ['Geen geldige AI-voorstellen overgebleven.']],
                );
            }

            $output = $acceptance['accepted'];
            $runStatus = $acceptance['had_rejections']
                ? AiRunStatus::Partial
                : AiRunStatus::Succeeded;
            $partialMessage = $acceptance['had_rejections']
                ? $acceptance['summary_message']
                : null;

            try {
                DB::transaction(function () use (
                    $intake,
                    $run,
                    $result,
                    $output,
                    $inputHash,
                    $promptVersion,
                    $model,
                    $trace,
                    $runStatus,
                    $partialMessage,
                ): void {
                    $trace->beginBuffer();
                    $locked = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
                    $currentInput = $this->contextBuilder->build($locked);
                    $currentUploads = $this->imageUploads($locked);
                    $currentInput['image_manifest'] = $this->imageManifest($currentUploads);
                    $currentInput['synthesis_policy'] = $this->synthesisPolicy($locked, $currentUploads);

                    if (! hash_equals($inputHash, $this->hash($currentInput, $promptVersion, $model))) {
                        throw new RuntimeException('Opnamedossier gewijzigd tijdens AI-synthese; resultaat niet toegepast.');
                    }

                    $this->replaceProposals($locked, $run, $output);
                    $this->decisionReadiness->recalculate($locked);
                    $trace->step('apply', [
                        'placement_count' => count($output['placement_proposals']),
                        'option_count' => count($output['option_proposals']),
                        'customer_task_count' => count($output['customer_tasks']),
                        'status' => $runStatus->value,
                    ]);

                    $run->update($run->completionResultAttributes($result, $model) + [
                        'status' => $runStatus,
                        'output' => $output,
                        'error_message' => $partialMessage !== null
                            ? Str::limit($partialMessage, 1000, '')
                            : null,
                        'finished_at' => now(),
                    ]);
                }, 3);
                $trace->flushBuffer();
            } catch (Throwable $transactionException) {
                $trace->discardBuffer();
                throw $transactionException;
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
                $trace->recordRemainingQuestions($questionsBefore, $this->traceSnapshots->remainingQuestions($freshIntake));
            }
            $trace->succeed();

            return $run->fresh() ?? $run;
        } catch (Throwable $exception) {
            $errorMessage = $exception instanceof ValidationException
                ? $this->validationFailureFormatter->fromException($exception)
                : Str::limit($exception->getMessage(), 1000, '');

            Log::warning('AI dossier synthesis failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run?->id,
                'ai_trace_id' => $trace?->traceId(),
                'exception' => $exception::class,
                'error_class' => $exception instanceof ValidationException
                    ? 'validation'
                    : class_basename($exception),
                'error_message' => $errorMessage,
            ]);

            if ($run !== null) {
                $failAttributes = [
                    'status' => AiRunStatus::Failed,
                    'error_message' => $errorMessage,
                    'finished_at' => now(),
                ];
                if ($result !== null) {
                    $failAttributes = $run->completionResultAttributes($result, $model) + $failAttributes;
                } elseif ($run->image_count === 0 && $imageUploads->isNotEmpty()) {
                    $failAttributes['image_count'] = $imageUploads->count();
                }
                $run->update($failAttributes);
                $trace?->linkAiRun($run->fresh() ?? $run);
                $trace?->discardBuffer();
                $trace?->fail($errorMessage, $exception);

                return $run->fresh() ?? $run;
            }

            $trace?->fail($errorMessage, $exception);

            return null;
        }
    }

    /** @param array<string, mixed> $output */
    private function replaceProposals(Intake $intake, AiRun $run, array $output): void
    {
        $candidateOptionIds = AircoInstallationOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->where('status', AircoOptionStatus::Candidate)
            ->pluck('id');
        $obsoleteSubjectIds = AircoConnection::query()
            ->whereIn('airco_installation_option_id', $candidateOptionIds)
            ->pluck('dossier_subject_id');

        AircoInstallationOption::query()
            ->whereIn('id', $candidateOptionIds)
            ->delete();

        $obsoletePlacements = AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->where('status', AircoOptionStatus::Candidate)
            ->whereDoesntHave('installationOptions')
            ->get(['id', 'dossier_subject_id']);
        $obsoleteSubjectIds = $obsoleteSubjectIds
            ->merge($obsoletePlacements->pluck('dossier_subject_id'))
            ->filter()
            ->unique()
            ->values();

        AircoPlacementOption::query()
            ->whereIn('id', $obsoletePlacements->pluck('id'))
            ->delete();
        DossierSubject::query()
            ->where('intake_id', $intake->id)
            ->whereIn('id', $obsoleteSubjectIds)
            ->delete();

        ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('status', ContributionTaskStatus::Proposed)
            ->get()
            ->filter(static fn (ContributionTask $task): bool => ($task->meta['source_type'] ?? null) === 'ai')
            ->each(static fn (ContributionTask $task) => $task->update([
                'status' => ContributionTaskStatus::Cancelled,
            ]));

        $subjects = DossierSubject::query()
            ->where('intake_id', $intake->id)
            ->get()
            ->keyBy(static fn (DossierSubject $subject): string => 'subject:'.$subject->id);
        $rooms = AircoRoom::query()
            ->where('intake_id', $intake->id)
            ->get()
            ->keyBy(static fn (AircoRoom $room): string => 'room:'.$room->id);
        $root = $this->dossierManager->root($intake);
        $placements = AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->get()
            ->keyBy(static fn (AircoPlacementOption $placement): string => 'placement:'.$placement->id);

        foreach ($output['placement_proposals'] as $proposalIndex => $proposal) {
            /** @var DossierSubject $parent */
            $parent = $subjects->get($proposal['subject_reference']) ?? $root;
            /** @var AircoRoom|null $room */
            $room = $proposal['room_reference'] === null
                ? null
                : $rooms->get($proposal['room_reference']);
            $subject = $this->dossierManager->subject(
                $intake,
                'airco.placement.ai.'.$run->id.'.'.$proposalIndex,
                'airco_placement',
                trim($proposal['label']),
                $parent,
                [
                    'placement_type' => $proposal['type'],
                    'ai_run_id' => $run->id,
                    'evidence_references' => $proposal['evidence_references'],
                ],
            );
            $placement = AircoPlacementOption::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'airco_room_id' => $room?->id,
                'dossier_subject_id' => $subject->id,
                'type' => $proposal['type'],
                'label' => trim($proposal['label']),
                'description' => trim($proposal['description']),
                'location_data' => [
                    'evidence_references' => $proposal['evidence_references'],
                ],
                'status' => AircoOptionStatus::Candidate,
                'source_type' => 'ai',
                'source_id' => $run->id,
                'confidence' => round((float) $proposal['confidence'], 3),
                'cost_risks' => null,
            ]);
            $placements->put($proposal['key'], $placement);
        }

        foreach ($output['option_proposals'] as $optionIndex => $proposal) {
            $option = AircoInstallationOption::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'label' => trim($proposal['label']),
                'configuration_type' => $proposal['configuration_type'],
                'rank' => $optionIndex + 1,
                'status' => AircoOptionStatus::Candidate,
                'summary' => trim($proposal['summary']),
                'cost_impact' => $proposal['cost_impact'],
                'source_type' => 'ai',
                'source_id' => $run->id,
                'confidence' => round((float) $proposal['confidence'], 3),
                'created_by' => null,
            ]);

            foreach ($proposal['placement_references'] as $sortOrder => $reference) {
                /** @var AircoPlacementOption $placement */
                $placement = $placements->get($reference);
                $option->placements()->attach($placement->id, [
                    'role' => $placement->type->value,
                    'sort_order' => $sortOrder + 1,
                ]);
            }

            foreach ($proposal['connections'] as $connectionIndex => $proposalConnection) {
                $subject = $this->dossierManager->subject(
                    $intake,
                    'airco.connection.ai.'.$run->id.'.'.$optionIndex.'.'.$connectionIndex,
                    'airco_connection',
                    trim($proposalConnection['label']),
                    $root,
                    [
                        'connection_type' => $proposalConnection['type'],
                        'installation_option_id' => $option->id,
                        'ai_run_id' => $run->id,
                    ],
                );
                $from = $proposalConnection['from_placement_reference'] === null
                    ? null
                    : $placements->get($proposalConnection['from_placement_reference']);
                $to = $proposalConnection['to_placement_reference'] === null
                    ? null
                    : $placements->get($proposalConnection['to_placement_reference']);

                AircoConnection::query()->create([
                    'intake_id' => $intake->id,
                    'company_id' => $intake->company_id,
                    'airco_installation_option_id' => $option->id,
                    'from_placement_id' => $from?->id,
                    'to_placement_id' => $to?->id,
                    'dossier_subject_id' => $subject->id,
                    'type' => $proposalConnection['type'],
                    'label' => trim($proposalConnection['label']),
                    'status' => $proposalConnection['status'],
                    'length_class' => $proposalConnection['length_class'],
                    'segments' => $proposalConnection['segments'],
                    'obstacles' => $proposalConnection['obstacles'],
                    'uncertainties' => $proposalConnection['uncertainties'],
                    'cost_impact' => $proposalConnection['cost_impact'],
                    'confidence' => round((float) $proposalConnection['confidence'], 3),
                    'source_type' => 'ai',
                    'source_id' => $run->id,
                    'safety_check_required' => $proposalConnection['type'] === AircoConnectionType::Power->value,
                ]);
            }
        }

        foreach ($output['customer_tasks'] as $task) {
            /** @var DossierSubject|null $subject */
            $subject = $task['subject_reference'] === null ? null : $subjects->get($task['subject_reference']);
            ContributionTask::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'dossier_subject_id' => $subject?->id,
                'intake_follow_up_item_id' => null,
                'audience' => ContributionAudience::Customer,
                'type' => $task['type'],
                'prompt' => trim($task['prompt']),
                'decision_area_key' => $task['decision_area_key'],
                'status' => ContributionTaskStatus::Proposed,
                'requested_by' => null,
                'meta' => [
                    'source_type' => 'ai',
                    'source_id' => $run->id,
                    'reason' => trim($task['reason']),
                    'evidence_references' => $task['evidence_references'],
                ],
            ]);
        }

        $this->dossierManager->record(
            intake: $intake,
            subject: $root,
            kind: DossierRecordKind::Conclusion,
            key: 'ai_dossier_synthesis',
            value: [
                'summary' => trim($output['summary']),
                'exceptions' => $output['exceptions'],
                'placement_count' => count($output['placement_proposals']),
                'option_count' => count($output['option_proposals']),
                'customer_task_count' => count($output['customer_tasks']),
            ],
            actorType: 'ai',
            actorId: null,
            sourceType: 'ai_run',
            sourceId: $run->id,
            method: 'evidence_synthesis',
            confidence: null,
            status: DossierRecordStatus::Proposed,
        );
    }

    /** @return Collection<int, IntakeUpload> */
    private function imageUploads(Intake $intake): Collection
    {
        $maximum = max(0, min(20, (int) config('ai.dossier.max_images', 12)));
        if ($maximum === 0) {
            return collect();
        }

        /** @var Collection<string, Collection<int, IntakeUpload>> $groups */
        $groups = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->orderBy('id')
            ->get()
            ->groupBy(
                static fn (IntakeUpload $upload): string => $upload->question_key
                    .'|'.($upload->section_instance_key ?? 'survey'),
            );
        /** @var Collection<int, IntakeUpload> $selected */
        $selected = collect();

        // First take the newest image from every dossier part, then a second one.
        // This keeps a twelve-image budget representative across rooms and routes.
        for ($offset = 0; $offset < 2 && $selected->count() < $maximum; $offset++) {
            foreach ($groups as $uploads) {
                $candidate = $uploads->reverse()->values()->get($offset);

                if ($candidate instanceof IntakeUpload) {
                    $selected->push($candidate);
                }

                if ($selected->count() >= $maximum) {
                    break;
                }
            }
        }

        return $selected->values();
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return list<array<string, mixed>>
     */
    private function imageManifest(Collection $uploads): array
    {
        return $uploads
            ->map(function (IntakeUpload $upload): array {
                $assessment = $upload->contentAssessment();
                $eligible = $assessment === null
                    || $assessment->solvesContent()
                    || $assessment->status() === PhotoContentAssessment::STATUS_NEEDS_CLEARER
                    || $assessment->status() === PhotoContentAssessment::STATUS_NOT_ASSESSED;

                return [
                    'reference' => 'dossier_image:'.$upload->id,
                    'question_key' => $upload->question_key,
                    'section_instance_key' => $upload->section_instance_key,
                    'follow_up_item_reference' => $upload->intake_follow_up_item_id === null
                        ? null
                        : 'follow_up_item:'.$upload->intake_follow_up_item_id,
                    'sort_order' => $upload->sort_order,
                    'image_identity' => $this->aiImageResolver->identity($upload),
                    'content_assessment' => $assessment?->toArray(),
                    // Wrong-subject photos (without “Toch doorgaan”) are never usable evidence.
                    'evidence_eligible' => $eligible,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Server-side policy hints for partial acceptance (staging intakes 76/77).
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return array{
     *     free_group: string|null,
     *     subjects_with_room_photo: list<string>
     * }
     */
    private function synthesisPolicy(Intake $intake, Collection $uploads): array
    {
        $intake->loadMissing(['answers', 'externalFacts', 'aircoRooms']);

        $freeGroup = null;
        $answer = $intake->answers->first(
            static fn ($row): bool => $row->question_key === 'free_group_known',
        );
        if (is_array($answer?->value) && is_string($answer->value['value'] ?? null)) {
            $freeGroup = $answer->value['value'];
        }

        $fact = $intake->externalFacts->first(
            static fn ($row): bool => $row->fact_key === 'fusebox_photo_assessment',
        );
        if (is_array($fact?->value) && is_string($fact->value['free_group'] ?? null)) {
            $freeGroup = $fact->value['free_group'];
        }

        $roomSubjectsByKey = [];
        foreach ($intake->aircoRooms as $room) {
            $roomSubjectsByKey[$room->key] = 'subject:'.$room->dossier_subject_id;
        }

        $covered = [];
        foreach ($uploads as $upload) {
            if (! in_array($upload->question_key, [
                'room_photos',
                'indoor_unit_position_photo',
                'room_wall_outlet_photo',
            ], true)) {
                continue;
            }

            $assessment = $upload->contentAssessment();
            if ($assessment instanceof PhotoContentAssessment
                && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                && ! $assessment->customerAcceptedMismatch()) {
                continue;
            }

            $instance = $upload->section_instance_key;
            if (is_string($instance) && isset($roomSubjectsByKey[$instance])) {
                $covered[] = $roomSubjectsByKey[$instance];
            }
        }

        return [
            'free_group' => is_string($freeGroup) ? $freeGroup : null,
            'subjects_with_room_photo' => array_values(array_unique($covered)),
        ];
    }

    /** @param array<string, mixed> $input */
    private function hash(array $input, string $promptVersion, string $model): string
    {
        return hash('sha256', (string) json_encode([
            'prompt_version' => $promptVersion,
            'model' => $model,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));
    }
}
