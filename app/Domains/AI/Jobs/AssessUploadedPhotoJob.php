<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpItemType;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI foto-beoordeling buiten de webrequest (BL-121 / 503-fix).
 * Uniek per upload zodat dubbele klikken geen parallelle jobs starten.
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

    public function __construct(
        public readonly int $uploadId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue(self::QUEUE);
        // Boven AI_TIMEOUT_SECONDS zodat de worker de soft-fail van de client afwacht.
        $this->timeout = max(45, (int) config('ai.timeout_seconds', 20) + 25);
    }

    public function uniqueId(): string
    {
        return 'assess-uploaded-photo:'.$this->uploadId;
    }

    public function handle(
        AssessFollowUpPhotoSubject $assessFollowUp,
        AssessFuseboxPhotos $assessFusebox,
        DerivePhotoAnswers $derivePhotoAnswers,
    ): void {
        $upload = IntakeUpload::query()->with(['intake', 'followUpItem'])->find($this->uploadId);

        if (! $upload instanceof IntakeUpload || $upload->intake === null) {
            return;
        }

        try {
            if ($upload->intake_follow_up_item_id !== null) {
                $this->assessFollowUp($upload, $assessFollowUp);

                return;
            }

            $this->assessWizardPhoto($upload, $assessFusebox, $derivePhotoAnswers);
        } catch (Throwable $exception) {
            Log::warning('Queued photo assessment failed', [
                'upload_id' => $this->uploadId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->persistNotAssessed($upload);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $upload = IntakeUpload::query()->find($this->uploadId);

        if (! $upload instanceof IntakeUpload) {
            return;
        }

        $this->persistNotAssessed($upload);

        Log::warning('Queued photo assessment exhausted retries', [
            'upload_id' => $this->uploadId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }

    private function assessFollowUp(IntakeUpload $upload, AssessFollowUpPhotoSubject $assessFollowUp): void
    {
        $item = $upload->followUpItem;

        if (! $item instanceof IntakeFollowUpItem || $item->type !== FollowUpItemType::Photo) {
            return;
        }

        $intake = $upload->intake;
        if ($intake === null) {
            return;
        }

        $result = $assessFollowUp->handle($intake, $item, $upload->fresh() ?? $upload);
        $assessment = $result['assessment'] ?? null;

        // Gebieden zonder subject-check: markeer als beoordeeld zodat de wizardpoll kan afronden.
        if ($assessment === null && ($upload->fresh()?->contentAssessment()) === null) {
            $upload->storeContentAssessment(PhotoContentAssessment::ok(PhotoSubject::Other));
        }
    }

    private function assessWizardPhoto(
        IntakeUpload $upload,
        AssessFuseboxPhotos $assessFusebox,
        DerivePhotoAnswers $derivePhotoAnswers,
    ): void {
        $intake = $upload->intake;
        if ($intake === null) {
            return;
        }

        $profileName = $this->photoAnalysisProfileName($upload);

        if ($profileName === null) {
            // Geen AI-profiel: usability was al sync; klaar zonder content_assessment.
            return;
        }

        if ($profileName === 'fusebox') {
            if ($upload->section_instance_key === null) {
                $assessFusebox->handle($intake, correlationId: $this->correlationId);
            }

            return;
        }

        $profile = PhotoDerivationProfile::find($profileName);

        if (! $profile instanceof PhotoDerivationProfile) {
            $expected = PhotoSubject::expectedForPhotoQuestion($upload->question_key, $profileName)
                ?? PhotoSubject::Other;
            $upload->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));

            return;
        }

        $derivePhotoAnswers->handle(
            $intake,
            $upload->question_key,
            $upload->section_instance_key,
            $profile,
            correlationId: $this->correlationId,
        );
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

    private function persistNotAssessed(IntakeUpload $upload): void
    {
        $fresh = $upload->fresh() ?? $upload;
        $existing = $fresh->contentAssessment();

        if ($existing instanceof PhotoContentAssessment
            && $existing->status() !== PhotoContentAssessment::STATUS_NOT_ASSESSED) {
            return;
        }

        $expected = $existing?->expectedSubject()
            ?? PhotoSubject::expectedForPhotoQuestion($fresh->question_key)
            ?? PhotoSubject::Other;

        $fresh->storeContentAssessment(PhotoContentAssessment::notAssessed($expected));
    }
}
