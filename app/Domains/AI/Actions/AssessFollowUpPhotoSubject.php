<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceSnapshotService;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Category check for targeted customer follow-up photo tasks.
 * Expected subject + accepted set from decision_area_key; unknown areas skip.
 * AssessPhotoUsability blijft een lokale GD-heuristic (geen vision).
 * AI-beoordeling draait via AssessUploadedPhotoJob (queue ai-photo).
 */
final class AssessFollowUpPhotoSubject
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTraceSnapshotService $traceSnapshots,
        private readonly AiTracePhotoRefBuilder $photoRefBuilder,
    ) {}

    /**
     * @return array{assessment: PhotoContentAssessment|null, message: string|null}
     */
    public function handle(
        Intake $intake,
        IntakeFollowUpItem $item,
        IntakeUpload $upload,
        ?string $correlationId = null,
    ): array {
        $area = $this->decisionAreaKey($item);
        $accepted = PhotoSubject::acceptedSubjectsForDecisionArea($area);
        $expected = PhotoSubject::expectedFromDecisionArea($area);

        if ($accepted === null || $expected === null) {
            // Gebied zonder subject-check (placement/condens/…): geen AI-call.
            return ['assessment' => null, 'message' => null];
        }

        $previous = $upload->contentAssessment();

        if (! (bool) config('ai.photo_inference.enabled', false)) {
            $assessment = PhotoContentAssessment::notAssessed($expected);
            $upload->storeContentAssessment($assessment);

            return ['assessment' => $assessment, 'message' => $assessment->customerMessage()];
        }

        $promptName = 'follow_up_photo_subject';
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);

        $input = [
            'task' => 'classify_follow_up_photo_subject',
            'expected_subject' => $expected->value,
            'accepted_subjects' => array_map(
                static fn (PhotoSubject $subject): string => $subject->value,
                $accepted,
            ),
            'decision_area_key' => $area,
            'prompt' => $item->prompt,
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
            $assessment = PhotoContentAssessment::fromModelOutput($expected, $existing->output, $accepted)
                ->preservingCustomerAcceptance($previous);
            $upload->storeContentAssessment($assessment);
            $this->ensureTraceForCachedRun($intake, $upload, $item, $existing, $promptVersion, $promptBody, $input);

            return [
                'assessment' => $assessment,
                'message' => $this->customerFacingMessage($assessment),
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

        $correlationId = (is_string($correlationId) && $correlationId !== '')
            ? $correlationId
            : $this->correlationIdForUpload($upload);
        $trace = $this->traceRecorder->start($intake, AiTraceCallType::FollowUpPhotoSubject, [
            'ai_run_id' => $run->id,
            'upload_id' => $upload->id,
            'subject_type' => 'follow_up_item',
            'subject_id' => (string) $item->id,
            'provider' => (string) config('ai.provider', 'null'),
            'prompt_version' => $promptVersion,
            'correlation_id' => $correlationId,
        ]);
        $trace->linkUpload($upload);
        $trace->linkAiRun($run);

        if (! $trace->isNoop()) {
            $this->traceSnapshots->answers($intake);
            $this->traceSnapshots->remainingQuestions($intake);
        }

        try {
            $photoRefs = [];
            if (! $trace->isNoop()) {
                $photoRefs = [$this->photoRefBuilder->fromUpload($upload, 'follow_up_subject')];
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
                $output = $this->validateOutput($result->output);
                $trace->recordParsed($output, []);
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

            $assessment = PhotoContentAssessment::fromModelOutput($expected, $output, $accepted)
                ->preservingCustomerAcceptance($previous);
            $upload->storeContentAssessment($assessment);

            $freshRun = $run->fresh() ?? $run;
            $trace->linkAiRun($freshRun);
            $trace->step('apply', [
                'content_status' => $assessment->status(),
                'upload_id' => $upload->id,
            ]);
            $trace->stopProcessTimer();
            $trace->succeed();

            return [
                'assessment' => $assessment,
                'message' => $this->customerFacingMessage($assessment),
            ];
        } catch (Throwable $exception) {
            $trace->linkAiRun($run->fresh() ?? $run);
            $trace->fail($exception->getMessage(), $exception);

            Log::warning('AI follow-up photo subject check failed', [
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

            $assessment = PhotoContentAssessment::notAssessed($expected);
            $upload->storeContentAssessment($assessment);

            return ['assessment' => $assessment, 'message' => $assessment->customerMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function ensureTraceForCachedRun(
        Intake $intake,
        IntakeUpload $upload,
        IntakeFollowUpItem $item,
        AiRun $run,
        string $promptVersion,
        string $promptBody,
        array $input,
    ): void {
        $existingTrace = AiTrace::query()
            ->where('ai_run_id', $run->id)
            ->where('call_type', AiTraceCallType::FollowUpPhotoSubject)
            ->first();

        if ($existingTrace !== null) {
            return;
        }

        $trace = $this->traceRecorder->start($intake, AiTraceCallType::FollowUpPhotoSubject, [
            'ai_run_id' => $run->id,
            'upload_id' => $upload->id,
            'subject_type' => 'follow_up_item',
            'subject_id' => (string) $item->id,
            'provider' => $run->provider ?: (string) config('ai.provider', 'null'),
            'model' => $run->model,
            'prompt_version' => $promptVersion,
            'correlation_id' => $this->correlationIdForUpload($upload),
        ]);
        $trace->linkUpload($upload);
        $trace->linkAiRun($run);
        $trace->recordRequest(
            systemAndUser: [
                'system' => $promptBody,
                'user' => $input,
            ],
            photoRefs: [$this->photoRefBuilder->fromUpload($upload, 'follow_up_subject')],
            promptVersion: $promptVersion,
        );
        $trace->step('cache_hit', ['ai_run_id' => $run->id]);
        $trace->succeed('Hergebruikte eerdere foto-beoordeling');
    }

    private function customerFacingMessage(PhotoContentAssessment $assessment): ?string
    {
        return $assessment->solvesContent() ? null : $assessment->customerMessage();
    }

    private function decisionAreaKey(IntakeFollowUpItem $item): ?string
    {
        $task = ContributionTask::query()
            ->where('intake_follow_up_item_id', $item->id)
            ->first();

        $key = $task?->decision_area_key;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{detected_subject: string, subject_match: string, evidence: string}
     */
    private function validateOutput(array $output): array
    {
        $validator = Validator::make($output, [
            'detected_subject' => ['required', Rule::in(array_map(
                static fn (PhotoSubject $subject): string => $subject->value,
                PhotoSubject::cases(),
            ))],
            'subject_match' => ['required', Rule::in(['yes', 'no'])],
            'evidence' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{detected_subject: string, subject_match: string, evidence: string} $validated */
        $validated = $validator->validated();

        return $validated;
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
