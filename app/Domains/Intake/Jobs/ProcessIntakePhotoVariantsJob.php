<?php

declare(strict_types=1);

namespace App\Domains\Intake\Jobs;

use App\Domains\AI\Actions\AssessPhotoUsability;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Services\PhotoAssessmentLifecycle;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\PhotoUploadNormalizer;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Heavy photo decode/resize/thumbnail outside the Livewire web request (BL-143).
 * Sync upload only stores the source bytes; this job writes dossier/analysis variants,
 * runs local usability, then dispatches AI assessment when needed.
 */
final class ProcessIntakePhotoVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE = AssessUploadedPhotoJob::QUEUE;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 20, 60];

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $uploadId,
        public readonly ?int $clientOriginalWidth = null,
        public readonly ?int $clientOriginalHeight = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return 'process-intake-photo-variants:'.$this->uploadId;
    }

    public function handle(
        PhotoUploadNormalizer $normalizer,
        AssessPhotoUsability $assessUsability,
        PhotoAssessmentLifecycle $lifecycle,
    ): void {
        $upload = IntakeUpload::query()->with('intake')->find($this->uploadId);

        if (! $upload instanceof IntakeUpload) {
            return;
        }

        $timings = is_array($upload->processing_timings) ? $upload->processing_timings : [];
        if (($timings['variants_ready'] ?? false) === true) {
            return;
        }

        if ($this->tryReuseExistingVariants($upload, $lifecycle, $timings)) {
            return;
        }

        $disk = (string) $upload->disk;
        $sourcePath = (string) $upload->path;
        $tempSource = null;
        $normalized = null;
        $newPath = null;
        $newAnalysisPath = null;

        try {
            $tempSource = $this->materializeSource(
                $disk,
                $sourcePath,
                (string) $upload->original_filename,
                (string) $upload->mime_type,
            );
            $file = new UploadedFile(
                $tempSource,
                (string) $upload->original_filename,
                (string) $upload->mime_type,
                null,
                true,
            );

            $preprocessStarted = microtime(true);
            $normalized = $normalizer->normalize($file);
            $preprocessMs = (int) round((microtime(true) - $preprocessStarted) * 1000);

            $directory = dirname($sourcePath);
            $basename = Str::ulid()->toBase32();
            $newPath = $directory.'/'.$basename.'.'.$normalized->dossierExtension;
            $newAnalysisPath = $directory.'/analysis/'.$basename.'.'.$normalized->analysisExtension;

            if (! Storage::disk($disk)->put($newPath, File::get($normalized->dossierAbsolutePath))
                || ! Storage::disk($disk)->put($newAnalysisPath, File::get($normalized->analysisAbsolutePath))) {
                $this->cleanupPath($disk, $newPath);
                $this->cleanupPath($disk, $newAnalysisPath);
                throw new \RuntimeException('Variant storage failed for upload '.$upload->id);
            }

            $originalWidth = $normalized->originalWidth;
            $originalHeight = $normalized->originalHeight;
            if ($this->clientOriginalWidth !== null && $this->clientOriginalHeight !== null
                && $this->clientOriginalWidth > 0 && $this->clientOriginalHeight > 0) {
                $originalWidth = $this->clientOriginalWidth;
                $originalHeight = $this->clientOriginalHeight;
            }

            $oldPath = (string) $upload->path;
            $oldAnalysis = $upload->analysis_path;

            $upload->forceFill([
                'path' => $newPath,
                'analysis_path' => $newAnalysisPath,
                'mime_type' => $normalized->dossierMime,
                'size_bytes' => $normalized->dossierSizeBytes,
                // Keep source checksum for duplicate detection / reuse (BL-143).
                // Dossier bytes change after re-encode; track that hash in timings.
                'analysis_mime_type' => $normalized->analysisMime,
                'analysis_size_bytes' => $normalized->analysisSizeBytes,
                'analysis_checksum' => $normalized->analysisChecksum,
                'processing_timings' => array_merge($timings, [
                    'variants_pending' => false,
                    'variants_ready' => true,
                    'preprocess_ms' => $preprocessMs,
                    'dossier_checksum' => $normalized->dossierChecksum,
                    'dossier_width' => $normalized->dossierWidth,
                    'dossier_height' => $normalized->dossierHeight,
                    'analysis_width' => $normalized->analysisWidth,
                    'analysis_height' => $normalized->analysisHeight,
                    'original_width' => $originalWidth,
                    'original_height' => $originalHeight,
                    'variants_processed_at' => now()->toIso8601String(),
                ]),
                'assessment_status' => PhotoAssessmentStatus::Pending,
                'assessment_queued_at' => now(),
            ])->save();

            if ($oldPath !== '' && $oldPath !== $newPath) {
                $this->cleanupPath($disk, $oldPath);
            }
            if (is_string($oldAnalysis) && $oldAnalysis !== '' && $oldAnalysis !== $newAnalysisPath) {
                $this->cleanupPath($disk, $oldAnalysis);
            }

            $correlationId = $this->correlationId
                ?? (is_string($timings['correlation_id'] ?? null) ? $timings['correlation_id'] : null);

            $verdict = $assessUsability->handle($upload->fresh() ?? $upload, $correlationId);
            $fresh = $upload->fresh() ?? $upload;

            if ($lifecycle->isTerminal($fresh)) {
                return;
            }

            if ($verdict === PhotoUsabilityVerdict::TooSmall) {
                $lifecycle->markHeuristicRejected($fresh);

                return;
            }

            // AssessUploadedPhotoJob marks assessed when the question has no AI profile.
            $lifecycle->dispatch($fresh, $correlationId);
        } catch (Throwable $exception) {
            Log::warning('ProcessIntakePhotoVariantsJob failed', [
                'upload_id' => $this->uploadId,
                'message' => $exception->getMessage(),
            ]);

            if ($newPath !== null) {
                $this->cleanupPath($disk, $newPath);
            }
            if ($newAnalysisPath !== null) {
                $this->cleanupPath($disk, $newAnalysisPath);
            }

            $upload->forceFill([
                'processing_timings' => array_merge($timings, [
                    'variants_pending' => false,
                    'variants_ready' => false,
                    'variants_failed' => true,
                    'variants_error' => Str::limit($exception->getMessage(), 180),
                ]),
            ])->save();

            AssessPhotoUsability::persistFallbackVerdict($upload);
            $lifecycle->markNotAssessed($upload->fresh() ?? $upload);

            throw $exception;
        } finally {
            if ($normalized !== null) {
                foreach ($normalized->cleanupPaths as $cleanupPath) {
                    @unlink($cleanupPath);
                }
            }
            if (is_string($tempSource) && is_file($tempSource)) {
                @unlink($tempSource);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $upload = IntakeUpload::query()->find($this->uploadId);
        if (! $upload instanceof IntakeUpload) {
            return;
        }

        $timings = is_array($upload->processing_timings) ? $upload->processing_timings : [];
        if (($timings['variants_ready'] ?? false) === true) {
            return;
        }

        AssessPhotoUsability::persistFallbackVerdict($upload);
        app(PhotoAssessmentLifecycle::class)->ensureTerminal($upload);

        Log::warning('ProcessIntakePhotoVariantsJob exhausted retries', [
            'upload_id' => $this->uploadId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }

    /**
     * Same checksum as an already-processed twin: copy dossier/analysis variants
     * without Imagick decode and reuse the prior assessment (staging upload 208).
     *
     * @param  array<string, mixed>  $timings
     */
    private function tryReuseExistingVariants(
        IntakeUpload $upload,
        PhotoAssessmentLifecycle $lifecycle,
        array $timings,
    ): bool {
        $checksum = $upload->checksum;
        if (! is_string($checksum) || $checksum === '') {
            return false;
        }

        $source = IntakeUpload::query()
            ->where('intake_id', $upload->intake_id)
            ->where('checksum', $checksum)
            ->where('id', '!=', $upload->id)
            ->whereNotNull('analysis_path')
            ->orderByDesc('id')
            ->get()
            ->first(static function (IntakeUpload $candidate): bool {
                $candidateTimings = is_array($candidate->processing_timings) ? $candidate->processing_timings : [];

                return ($candidateTimings['variants_ready'] ?? false) === true
                    && is_string($candidate->analysis_path)
                    && $candidate->analysis_path !== '';
            });

        if (! $source instanceof IntakeUpload) {
            return false;
        }

        $disk = (string) $upload->disk;
        $directory = dirname((string) $upload->path);
        $extension = pathinfo((string) $source->path, PATHINFO_EXTENSION) ?: 'jpg';
        $analysisExtension = pathinfo((string) $source->analysis_path, PATHINFO_EXTENSION) ?: 'jpg';
        $basename = Str::ulid()->toBase32();
        $newPath = $directory.'/'.$basename.'.'.$extension;
        $newAnalysisPath = $directory.'/analysis/'.$basename.'.'.$analysisExtension;

        try {
            $dossierBytes = Storage::disk((string) $source->disk)->get((string) $source->path);
            $analysisBytes = Storage::disk((string) $source->disk)->get((string) $source->analysis_path);
            if (! is_string($dossierBytes) || $dossierBytes === ''
                || ! is_string($analysisBytes) || $analysisBytes === '') {
                return false;
            }

            if (! Storage::disk($disk)->put($newPath, $dossierBytes)
                || ! Storage::disk($disk)->put($newAnalysisPath, $analysisBytes)) {
                $this->cleanupPath($disk, $newPath);
                $this->cleanupPath($disk, $newAnalysisPath);

                return false;
            }
        } catch (Throwable) {
            $this->cleanupPath($disk, $newPath);
            $this->cleanupPath($disk, $newAnalysisPath);

            return false;
        }

        $sourceTimings = is_array($source->processing_timings) ? $source->processing_timings : [];
        $oldPath = (string) $upload->path;
        $oldAnalysis = $upload->analysis_path;

        $upload->forceFill([
            'path' => $newPath,
            'analysis_path' => $newAnalysisPath,
            'mime_type' => $source->mime_type,
            'size_bytes' => $source->size_bytes,
            'analysis_mime_type' => $source->analysis_mime_type,
            'analysis_size_bytes' => $source->analysis_size_bytes,
            'analysis_checksum' => $source->analysis_checksum,
            'usability_verdict' => $source->usability_verdict,
            'processing_timings' => array_merge($timings, [
                'variants_pending' => false,
                'variants_ready' => true,
                'preprocess_ms' => 0,
                'variants_reused_from_upload_id' => $source->id,
                'dossier_checksum' => $sourceTimings['dossier_checksum'] ?? null,
                'dossier_width' => $sourceTimings['dossier_width'] ?? null,
                'dossier_height' => $sourceTimings['dossier_height'] ?? null,
                'analysis_width' => $sourceTimings['analysis_width'] ?? null,
                'analysis_height' => $sourceTimings['analysis_height'] ?? null,
                'original_width' => $this->clientOriginalWidth
                    ?? $sourceTimings['original_width']
                    ?? null,
                'original_height' => $this->clientOriginalHeight
                    ?? $sourceTimings['original_height']
                    ?? null,
                'variants_processed_at' => now()->toIso8601String(),
            ]),
        ])->save();

        if ($oldPath !== '' && $oldPath !== $newPath) {
            $this->cleanupPath($disk, $oldPath);
        }
        if (is_string($oldAnalysis) && $oldAnalysis !== '' && $oldAnalysis !== $newAnalysisPath) {
            $this->cleanupPath($disk, $oldAnalysis);
        }

        $expected = PhotoSubject::expectedForPhotoQuestion($upload->question_key) ?? PhotoSubject::Other;
        if (! $lifecycle->tryReuseFromChecksum($upload->fresh() ?? $upload, $expected)) {
            // Variants reused; assessment may still need a queue pass.
            $lifecycle->dispatch($upload->fresh() ?? $upload, $this->correlationId);
        }

        return true;
    }

    private function materializeSource(string $disk, string $path, string $filename, string $mime): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if ($extension === '') {
            $extension = match ($mime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/heic', 'image/heif' => 'heic',
                default => 'jpg',
            };
        }

        $temp = sys_get_temp_dir().'/intake-src-'.Str::ulid()->toBase32().'.'.$extension;
        $bytes = Storage::disk($disk)->get($path);
        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('Source photo missing for variant processing.');
        }
        File::put($temp, $bytes);

        return $temp;
    }

    private function cleanupPath(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
            // Async cleanup below.
        }

        DeleteStoredMediaJob::dispatch($disk, $path);
    }
}
