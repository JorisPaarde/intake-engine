<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use Illuminate\Support\Str;

/**
 * Persist a diagnostic ai_run + ai_trace when an AI action is intentionally skipped
 * (no provider call), e.g. upload without photo_analysis profile.
 */
final class AiSkipRecorder
{
    public function __construct(
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTraceRequestIdResolver $requestIdResolver,
    ) {}

    public function record(
        Intake $intake,
        IntakeUpload $upload,
        AiTraceCallType $callType,
        string $reason,
        ?string $correlationId = null,
        AiRunType $runType = AiRunType::PhotoAssessment,
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): AiRun {
        $correlationId = $this->requestIdResolver->resolveCorrelationIdForUpload($upload, $correlationId);
        $reason = Str::limit(trim($reason), 1000, '');
        if ($reason === '') {
            $reason = 'geen beoordelingsprofiel';
        }

        $inputHash = hash('sha256', (string) json_encode([
            'skip' => true,
            'reason' => $reason,
            'upload_id' => $upload->id,
            'call_type' => $callType->value,
        ], JSON_THROW_ON_ERROR));

        $run = AiRun::query()->create([
            'intake_id' => $intake->id,
            'upload_id' => $upload->id,
            'type' => $runType,
            'provider' => 'none',
            'model' => null,
            'prompt_version' => 'skip',
            'provider_request_id' => null,
            'input_hash' => $inputHash,
            'output' => ['skip_reason' => $reason],
            'status' => AiRunStatus::Skipped,
            'error_message' => $reason,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $trace = $this->traceRecorder->start($intake, $callType, [
            'ai_run_id' => $run->id,
            'upload_id' => $upload->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'provider' => 'none',
            'prompt_version' => 'skip',
            'correlation_id' => $correlationId,
            'model_parameters' => [
                'skipped' => true,
                'skip_reason' => $reason,
            ],
        ]);
        $trace->linkUpload($upload);
        $trace->linkAiRun($run);
        $trace->recordRequest(
            systemAndUser: [
                'system' => null,
                'user' => [
                    'skipped' => true,
                    'reason' => $reason,
                    'upload_id' => $upload->id,
                    'question_key' => $upload->question_key,
                ],
            ],
            promptVersion: 'skip',
            modelParameters: [
                'skipped' => true,
                'skip_reason' => $reason,
            ],
        );
        $trace->step('skipped', [
            'reason' => $reason,
            'upload_id' => $upload->id,
        ]);
        $trace->skip($reason);

        return $run;
    }
}
