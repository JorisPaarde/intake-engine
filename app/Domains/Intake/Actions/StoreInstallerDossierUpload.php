<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\InstallerSurveyProgress;
use App\Domains\Intake\Services\PhotoUploadNormalizer;
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

        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 5120);

        if ($file->getSize() !== false && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto is te groot. Maximaal '.($maxKilobytes / 1024).' MB.',
            ]);
        }

        $normalized = $this->normalizer->normalize($file);
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
            if (isset($disk, $path)) {
                $this->delete($disk, $path);
            }

            if (isset($disk, $analysisPath)) {
                $this->delete($disk, $analysisPath);
            }

            throw $exception;
        } finally {
            foreach ($normalized->cleanupPaths as $cleanupPath) {
                @unlink($cleanupPath);
            }
        }

        return $upload;
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
