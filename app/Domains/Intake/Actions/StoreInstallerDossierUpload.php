<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\InstallerSurveyProgress;
use App\Domains\Intake\Services\PhotoUploadNormalizer;
use App\Domains\Intake\Support\PhotoUploadLimits;
use App\Enums\AiTraceCallType;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StoreInstallerDossierUpload
{
    public function __construct(
        private readonly PhotoUploadNormalizer $normalizer,
        private readonly DossierManager $dossierManager,
        private readonly InstallerSurveyProgress $surveyProgress,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTracePhotoRefBuilder $photoRefs,
    ) {}

    public function handle(
        Intake $intake,
        User $installer,
        DossierSubject $subject,
        UploadedFile $file,
    ): IntakeUpload {
        if ($installer->company_id !== $intake->company_id
            || $subject->intake_id !== $intake->id
            || $subject->company_id !== $intake->company_id
            || $intake->status === IntakeStatus::Cancelled) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto kan niet aan dit dossier worden toegevoegd.',
            ]);
        }

        PhotoUploadLimits::assertUploadedFileAcceptable($file);

        $preprocessStarted = microtime(true);
        $normalized = $this->normalizer->normalize($file);
        $preprocessMs = (int) round((microtime(true) - $preprocessStarted) * 1000);
        $persistStarted = microtime(true);
        $disk = (string) config('filesystems.media', 'local');
        $basename = Str::ulid()->toBase32();
        $directory = 'intakes/'.$intake->uuid.'/installer/'.$subject->id;
        $path = $directory.'/'.$basename.'.'.$normalized->dossierExtension;
        $analysisPath = $directory.'/analysis/'.$basename.'.'.$normalized->analysisExtension;

        try {
            if (! Storage::disk($disk)->put($path, File::get($normalized->dossierAbsolutePath))
                || ! Storage::disk($disk)->put($analysisPath, File::get($normalized->analysisAbsolutePath))) {
                $this->delete($disk, $path);
                $this->delete($disk, $analysisPath);

                throw ValidationException::withMessages([
                    'photo' => 'Upload mislukt. Probeer het opnieuw.',
                ]);
            }

            $upload = DB::transaction(function () use (
                $intake,
                $installer,
                $subject,
                $disk,
                $path,
                $analysisPath,
                $normalized,
                $preprocessMs,
                $persistStarted,
            ): IntakeUpload {
                $locked = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === IntakeStatus::Cancelled) {
                    throw ValidationException::withMessages([
                        'photo' => 'Deze opname kan niet meer worden gewijzigd.',
                    ]);
                }

                $sortOrder = (int) $locked->uploads()
                    ->where('question_key', 'installer_evidence')
                    ->where('section_instance_key', 'subject-'.$subject->id)
                    ->max('sort_order') + 1;
                $persistMs = (int) round((microtime(true) - $persistStarted) * 1000);
                $timings = [
                    'persist_ms' => $persistMs,
                    'preprocess_ms' => $preprocessMs,
                    'dossier_width' => $normalized->dossierWidth,
                    'dossier_height' => $normalized->dossierHeight,
                    'analysis_width' => $normalized->analysisWidth,
                    'analysis_height' => $normalized->analysisHeight,
                    'original_width' => $normalized->originalWidth,
                    'original_height' => $normalized->originalHeight,
                    'measured_at' => now()->toIso8601String(),
                ];

                $upload = IntakeUpload::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => 'installer_evidence',
                    'section_instance_key' => 'subject-'.$subject->id,
                    'disk' => $disk,
                    'path' => $path,
                    'analysis_path' => $analysisPath,
                    'original_filename' => $normalized->originalFilename,
                    'mime_type' => $normalized->dossierMime,
                    'size_bytes' => $normalized->dossierSizeBytes,
                    'checksum' => $normalized->dossierChecksum,
                    'analysis_mime_type' => $normalized->analysisMime,
                    'analysis_size_bytes' => $normalized->analysisSizeBytes,
                    'analysis_checksum' => $normalized->analysisChecksum,
                    'sort_order' => $sortOrder,
                    'processing_timings' => $timings,
                ]);

                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'user',
                    'actor_id' => $installer->id,
                    'event' => 'installer_evidence_uploaded',
                    'properties' => [
                        'upload_id' => $upload->id,
                        'subject_key' => $subject->key,
                    ],
                    'created_at' => now(),
                ]);

                $this->dossierManager->linkEvidence(
                    $locked,
                    $subject,
                    'intake_upload',
                    $upload->id,
                );
                $this->surveyProgress->markStarted($locked);

                return $upload;
            }, 3);
        } catch (Throwable $exception) {
            $this->delete($disk, $path);
            $this->delete($disk, $analysisPath);

            throw $exception;
        } finally {
            foreach ($normalized->cleanupPaths as $cleanupPath) {
                @unlink($cleanupPath);
            }
        }

        $this->recordUploadTrace($intake, $upload, $subject);

        return $upload;
    }

    private function recordUploadTrace(Intake $intake, IntakeUpload $upload, DossierSubject $subject): void
    {
        $trace = $this->traceRecorder->start($intake, AiTraceCallType::PhotoAssess, [
            'upload_id' => $upload->id,
            'subject_type' => 'dossier_subject',
            'subject_id' => (string) $subject->id,
        ]);
        $trace->linkUpload($upload);
        $trace->recordRequest(
            systemAndUser: [
                'system' => 'installer_dossier_upload_persist',
                'user' => ['subject_key' => $subject->key, 'upload_id' => $upload->id],
            ],
            photoRefs: [$this->photoRefs->fromUpload($upload, 'installer_evidence')],
        );
        $trace->succeed();
    }

    private function delete(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
            // Retry asynchronously.
        }

        DeleteStoredMediaJob::dispatch($disk, $path);
    }
}
