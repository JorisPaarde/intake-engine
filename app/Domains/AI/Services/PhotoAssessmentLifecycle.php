<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;

/**
 * Single writer for intake_uploads.assessment_status.
 * Every customer photo upload must reach exactly one terminal status.
 */
final class PhotoAssessmentLifecycle
{
    public function markPending(IntakeUpload $upload): void
    {
        $upload->forceFill([
            'assessment_status' => PhotoAssessmentStatus::Pending,
            'assessment_source_upload_id' => null,
            'assessment_queued_at' => now(),
        ])->save();
    }

    public function markAssessed(IntakeUpload $upload): void
    {
        $upload->forceFill([
            'assessment_status' => PhotoAssessmentStatus::Assessed,
            'assessment_queued_at' => null,
        ])->save();
    }

    public function markHeuristicRejected(IntakeUpload $upload): void
    {
        $upload->forceFill([
            'assessment_status' => PhotoAssessmentStatus::HeuristicRejected,
            'assessment_source_upload_id' => null,
            'assessment_queued_at' => null,
        ])->save();
    }

    public function markNotAssessed(IntakeUpload $upload, ?PhotoSubject $expected = null): void
    {
        $fresh = $upload->fresh() ?? $upload;
        $existing = $fresh->contentAssessment();

        if ($existing === null || $existing->needsReassessment()) {
            $subject = $expected
                ?? $existing?->expectedSubject()
                ?? PhotoSubject::expectedForPhotoQuestion($fresh->question_key)
                ?? PhotoSubject::Other;

            $fresh->storeContentAssessment(
                PhotoContentAssessment::notAssessed($subject),
                PhotoAssessmentStatus::NotAssessed,
            );

            return;
        }

        $fresh->forceFill([
            'assessment_status' => PhotoAssessmentStatus::NotAssessed,
            'assessment_queued_at' => null,
        ])->save();
    }

    public function markReused(IntakeUpload $upload, IntakeUpload $source): void
    {
        $assessment = $source->contentAssessment();

        if (! $assessment instanceof PhotoContentAssessment) {
            $this->markNotAssessed($upload);

            return;
        }

        $upload->storeContentAssessment($assessment, PhotoAssessmentStatus::Reused);
        $upload->forceFill([
            'assessment_source_upload_id' => $source->id,
            'assessment_queued_at' => null,
        ])->save();
    }

    public function syncFromUsability(IntakeUpload $upload, PhotoUsabilityVerdict $verdict): bool
    {
        // Alleen te klein: vision heeft te weinig pixels → terminaal zonder AI-wacht.
        // Te donker blijft pending: AI kan het onderwerp vaak nog herkennen.
        if ($verdict === PhotoUsabilityVerdict::TooSmall) {
            $this->markHeuristicRejected($upload);

            return false;
        }

        if (! $this->isTerminal($upload->fresh() ?? $upload)) {
            $this->markPending($upload);
        }

        return true;
    }

    public function isTerminal(IntakeUpload $upload): bool
    {
        $status = $upload->assessment_status;

        return $status instanceof PhotoAssessmentStatus && $status->isTerminal();
    }

    public function needsQueuedAi(IntakeUpload $upload): bool
    {
        $fresh = $upload->fresh() ?? $upload;

        if ($this->isTerminal($fresh)) {
            return false;
        }

        $verdict = $fresh->usability_verdict;
        if ($verdict === PhotoUsabilityVerdict::TooSmall) {
            return false;
        }

        return true;
    }

    /**
     * Dispatch AI job idempotently.
     * Sets assessment_attempts >= 1 as the pipeline marker so the watchdog
     * only requeues uploads the app itself dispatched (BL-134).
     */
    public function dispatch(IntakeUpload $upload, ?string $correlationId = null): void
    {
        $fresh = $upload->fresh() ?? $upload;

        if ($this->isTerminal($fresh) && ! $fresh->contentAssessment()?->needsReassessment()) {
            return;
        }

        if (! $this->needsQueuedAi($fresh)) {
            return;
        }

        $fresh->forceFill([
            'assessment_status' => PhotoAssessmentStatus::Pending,
            'assessment_queued_at' => now(),
            'assessment_source_upload_id' => null,
            // Pipeline marker: watchdog requires attempts >= 1 (never revive bare backfill).
            'assessment_attempts' => max(1, (int) $fresh->assessment_attempts),
        ])->save();

        AssessUploadedPhotoJob::dispatch($fresh->id, $correlationId);
    }

    /**
     * Copy a prior assessment when the same bytes were already assessed for the same subject.
     */
    public function tryReuseFromChecksum(IntakeUpload $upload, PhotoSubject $expected): bool
    {
        $checksum = $upload->checksum;
        if (! is_string($checksum) || $checksum === '') {
            return false;
        }

        $source = IntakeUpload::query()
            ->where('intake_id', $upload->intake_id)
            ->where('checksum', $checksum)
            ->where('id', '!=', $upload->id)
            ->where('assessment_status', PhotoAssessmentStatus::Assessed)
            ->whereNotNull('content_assessment')
            ->orderByDesc('id')
            ->get()
            ->first(function (IntakeUpload $candidate) use ($expected): bool {
                $assessment = $candidate->contentAssessment();

                return $assessment instanceof PhotoContentAssessment
                    && $assessment->status() !== PhotoContentAssessment::STATUS_NOT_ASSESSED
                    && $assessment->expectedSubject() === $expected;
            });

        if (! $source instanceof IntakeUpload) {
            return false;
        }

        $this->markReused($upload, $source);

        return true;
    }

    public function ensureTerminal(IntakeUpload $upload, ?PhotoSubject $expected = null): void
    {
        $fresh = $upload->fresh() ?? $upload;

        if ($this->isTerminal($fresh)) {
            return;
        }

        $assessment = $fresh->contentAssessment();
        if ($assessment instanceof PhotoContentAssessment) {
            $fresh->storeContentAssessment($assessment);

            return;
        }

        $verdict = $fresh->usability_verdict;
        if ($verdict instanceof PhotoUsabilityVerdict && ! $verdict->isUsable()) {
            $this->markHeuristicRejected($fresh);

            return;
        }

        $this->markNotAssessed($fresh, $expected);
    }
}
