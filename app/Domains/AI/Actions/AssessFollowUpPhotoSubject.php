<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Category check for targeted customer follow-up photo tasks.
 * Expected subject + accepted set from decision_area_key; unknown areas skip.
 * AssessPhotoUsability blijft een lokale GD-heuristic (geen vision) — geen
 * gecombineerde vision-call; async beoordeling staat op de backlog.
 */
final class AssessFollowUpPhotoSubject
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
    ) {}

    /**
     * @return array{assessment: PhotoContentAssessment|null, message: string|null}
     */
    public function handle(Intake $intake, IntakeFollowUpItem $item, IntakeUpload $upload): array
    {
        $area = $this->decisionAreaKey($item);
        $accepted = PhotoSubject::acceptedSubjectsForDecisionArea($area);
        $expected = PhotoSubject::expectedFromDecisionArea($area);

        if ($accepted === null || $expected === null) {
            return ['assessment' => null, 'message' => null];
        }

        if (! (bool) config('ai.photo_inference.enabled', false)) {
            $assessment = PhotoContentAssessment::notAssessed($expected);
            $upload->storeContentAssessment($assessment);

            return ['assessment' => $assessment, 'message' => null];
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
                ->preservingCustomerAcceptance($upload->contentAssessment());
            $upload->storeContentAssessment($assessment);

            return [
                'assessment' => $assessment,
                'message' => $this->customerFacingMessage($assessment),
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

            $assessment = PhotoContentAssessment::fromModelOutput($expected, $output, $accepted)
                ->preservingCustomerAcceptance($upload->contentAssessment());
            $upload->storeContentAssessment($assessment);

            return [
                'assessment' => $assessment,
                'message' => $this->customerFacingMessage($assessment),
            ];
        } catch (Throwable $exception) {
            Log::warning('AI follow-up photo subject check failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
                'exception' => $exception::class,
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            $assessment = PhotoContentAssessment::notAssessed($expected);
            $upload->storeContentAssessment($assessment);

            return ['assessment' => $assessment, 'message' => null];
        }
    }

    private function customerFacingMessage(PhotoContentAssessment $assessment): ?string
    {
        if ($assessment->status() === PhotoContentAssessment::STATUS_NOT_ASSESSED) {
            return null;
        }

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
}
