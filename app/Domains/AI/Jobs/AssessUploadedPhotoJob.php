<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Services\AiSkipRecorder;
use App\Domains\AI\Services\AiTraceRequestIdResolver;
use App\Domains\AI\Services\PhotoAssessmentLifecycle;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiTraceCallType;
use App\Enums\FollowUpItemType;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI foto-beoordeling buiten de webrequest (BL-121 / 503-fix).
 * Uniek per upload zodat dubbele klikken geen parallelle jobs starten.
 * Elke exit path zet een terminale assessment_status (geen stille skip).
 */
final class AssessUploadedPhotoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ai-photo';

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public int $timeout;

    public int $uniqueFor = 180;

    /** microtime(true) at construction / dispatch — used for queue_wait_ms. */
    public readonly float $dispatchedAt;

    public function __construct(
        public readonly int $uploadId,
        public readonly ?string $correlationId = null,
        ?float $dispatchedAt = null,
    ) {
        $this->onQueue(self::QUEUE);
        // Boven AI_TIMEOUT_SECONDS zodat de worker de soft-fail van de client afwacht.
        $this->timeout = max(45, (int) config('ai.timeout_seconds', 20) + 25);
        $this->dispatchedAt = $dispatchedAt ?? microtime(true);
    }

    public function uniqueId(): string
    {
        return 'assess-uploaded-photo:'.$this->uploadId;
    }

    public function handle(
        AssessFollowUpPhotoSubject $assessFollowUp,
        AssessFuseboxPhotos $assessFusebox,
        DerivePhotoAnswers $derivePhotoAnswers,
        PhotoAssessmentLifecycle $lifecycle,
        AiTraceRequestIdResolver $requestIdResolver,
        AiSkipRecorder $skipRecorder,
    ): void {
        $queueWaitMs = (int) max(0, round((microtime(true) - $this->dispatchedAt) * 1000));
        $attempt = max(1, $this->attempts());
        $requestIdResolver->rememberQueueMetrics($queueWaitMs, $attempt, $this->dispatchedAt);

        $upload = IntakeUpload::query()->with(['intake', 'followUpItem'])->find($this->uploadId);

        if (! $upload instanceof IntakeUpload) {
            return;
        }

        // Soft-deleted / missing intake: terminal only, never call AI.
        if ($upload->intake === null) {
            $lifecycle->ensureTerminal($upload);

            return;
        }

        // One correlation chain per upload via #136 AiTraceRequestIdResolver (not a second system).
        $correlationId = $requestIdResolver->resolveCorrelationIdForUpload($upload, $this->correlationId);

        if ($lifecycle->isTerminal($upload) && ! $upload->contentAssessment()?->needsReassessment()) {
            return;
        }

        // Submitted/closed intakes: never call AI, never overwrite content_assessment (BL-134).
        if ($upload->intake->status->isSubmittedOrClosed()) {
            Log::info('Skipping photo assessment on submitted/closed intake', [
                'upload_id' => $this->uploadId,
                'intake_id' => $upload->intake_id,
                'status' => $upload->intake->status->value,
            ]);
            $lifecycle->sealPreservingContent($upload);

            return;
        }

        try {
            if ($upload->intake_follow_up_item_id !== null) {
                $this->assessFollowUp($upload, $assessFollowUp, $lifecycle, $correlationId);

                return;
            }

            $this->assessWizardPhoto($upload, $assessFusebox, $derivePhotoAnswers, $lifecycle, $skipRecorder, $correlationId);
        } catch (Throwable $exception) {
            Log::warning('Queued photo assessment failed', [
                'upload_id' => $this->uploadId,
                'attempt' => $attempt,
                'queue_wait_ms' => $queueWaitMs,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $lifecycle->ensureTerminal($upload);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $upload = IntakeUpload::query()->find($this->uploadId);

        if (! $upload instanceof IntakeUpload) {
            return;
        }

        app(PhotoAssessmentLifecycle::class)->ensureTerminal($upload);

        Log::warning('Queued photo assessment exhausted retries', [
            'upload_id' => $this->uploadId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }

    private function assessFollowUp(
        IntakeUpload $upload,
        AssessFollowUpPhotoSubject $assessFollowUp,
        PhotoAssessmentLifecycle $lifecycle,
        string $correlationId,
    ): void {
        $item = $upload->followUpItem;

        if (! $item instanceof IntakeFollowUpItem || $item->type !== FollowUpItemType::Photo) {
            $lifecycle->markAssessed($upload);

            return;
        }

        $intake = $upload->intake;
        if ($intake === null) {
            $lifecycle->markNotAssessed($upload);

            return;
        }

        $expected = PhotoSubject::expectedFromDecisionArea(
            $this->followUpDecisionArea($item),
        ) ?? PhotoSubject::Other;

        if ($lifecycle->tryReuseFromChecksum($upload, $expected)) {
            return;
        }

        $upload = $upload->fresh() ?? $upload;
        $result = $assessFollowUp->handle($intake, $item, $upload, $correlationId);
        $assessment = $result['assessment'] ?? null;

        // Gebieden zonder subject-check: markeer als beoordeeld zodat de wizardpoll kan afronden.
        if ($assessment === null && ($upload->fresh()?->contentAssessment()) === null) {
            $upload->storeContentAssessment(PhotoContentAssessment::ok(PhotoSubject::Other));
        }

        $lifecycle->ensureTerminal($upload->fresh() ?? $upload, $expected);
    }

    private function assessWizardPhoto(
        IntakeUpload $upload,
        AssessFuseboxPhotos $assessFusebox,
        DerivePhotoAnswers $derivePhotoAnswers,
        PhotoAssessmentLifecycle $lifecycle,
        AiSkipRecorder $skipRecorder,
        string $correlationId,
    ): void {
        $intake = $upload->intake;
        if ($intake === null) {
            $lifecycle->markNotAssessed($upload);

            return;
        }

        $profileName = $this->photoAnalysisProfileName($upload);

        if ($profileName === null) {
            $skipRecorder->record(
                $intake,
                $upload,
                AiTraceCallType::PhotoAssess,
                'geen beoordelingsprofiel',
                $correlationId,
            );
            $lifecycle->markAssessed($upload);

            return;
        }

        $expected = PhotoSubject::expectedForPhotoQuestion($upload->question_key, $profileName)
            ?? PhotoSubject::Other;

        if ($lifecycle->tryReuseFromChecksum($upload, $expected)) {
            return;
        }

        if ($profileName === 'fusebox') {
            // Fusebox action assesses all pending fusebox uploads; each upload gets its
            // own correlation via AiTraceRequestIdResolver::resolveCorrelationIdForUpload.
            $assessFusebox->handle($intake, correlationId: $correlationId);
            $lifecycle->ensureTerminal($upload->fresh() ?? $upload, $expected);

            return;
        }

        $profile = PhotoDerivationProfile::find($profileName);

        if (! $profile instanceof PhotoDerivationProfile) {
            $skipRecorder->record(
                $intake,
                $upload,
                AiTraceCallType::PhotoDerive,
                'geen beoordelingsprofiel',
                $correlationId,
            );
            $upload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
            $lifecycle->ensureTerminal($upload->fresh() ?? $upload, $expected);

            return;
        }

        $derivePhotoAnswers->handle(
            $intake,
            $upload->question_key,
            $upload->section_instance_key,
            $profile,
            correlationId: $correlationId,
        );

        $lifecycle->ensureTerminal($upload->fresh() ?? $upload, $expected);
    }

    private function photoAnalysisProfileName(IntakeUpload $upload): ?string
    {
        $intake = $upload->intake;
        if ($intake === null) {
            return null;
        }

        $version = $intake->templateVersion()
            ->with(['sections.questions'])
            ->first();

        if ($version === null) {
            return null;
        }

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key !== $upload->question_key) {
                    continue;
                }

                $profileName = $question->meta['photo_analysis'] ?? null;

                return is_string($profileName) && $profileName !== '' ? $profileName : null;
            }
        }

        return null;
    }

    private function followUpDecisionArea(IntakeFollowUpItem $item): ?string
    {
        $key = ContributionTask::query()
            ->where('intake_follow_up_item_id', $item->id)
            ->value('decision_area_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
