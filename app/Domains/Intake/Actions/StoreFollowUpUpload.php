<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DocumentUploadNormalizer;
use App\Domains\Intake\Services\UploadMimeDetector;
use App\Enums\AiTraceCallType;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StoreFollowUpUpload
{
    public function __construct(
        private readonly DocumentUploadNormalizer $documentUploadNormalizer,
        private readonly UploadMimeDetector $mimeDetector,
        private readonly AiTraceRecorder $traceRecorder,
    ) {}

    public function handle(Intake $intake, IntakeFollowUpItem $item, UploadedFile $file): IntakeUpload
    {
        $item->loadMissing('round');

        if ($item->round->intake_id !== $intake->id
            || $item->round->status !== FollowUpRoundStatus::Open
            || ! in_array($item->type, [FollowUpItemType::Photo, FollowUpItemType::Document], true)
            || $intake->status !== IntakeStatus::AwaitingCustomer) {
            throw ValidationException::withMessages([
                'upload' => 'Deze uploadopdracht is niet meer beschikbaar.',
            ]);
        }

        $isPhoto = $item->type === FollowUpItemType::Photo;
        $maxFiles = $isPhoto
            ? (int) config('intake.follow_up.max_photos_per_item', 5)
            : (int) config('intake.follow_up.max_documents_per_item', 3);
        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 8192);
        $existingCount = $item->uploads()->count();
        $fileLabel = $isPhoto ? 'foto' : 'document';

        if ($existingCount >= $maxFiles) {
            throw ValidationException::withMessages([
                'upload' => "Je kunt maximaal {$maxFiles} {$fileLabel}s bij deze opdracht uploaden.",
            ]);
        }

        if ($file->getSize() !== false && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'upload' => 'Dit bestand is te groot. Maximaal '.($maxKilobytes / 1024).' MB.',
            ]);
        }

        if ($isPhoto) {
            return $this->storePhotoLightly($intake, $item, $file, $maxFiles, $fileLabel);
        }

        $preprocessStarted = microtime(true);
        $normalized = $this->documentUploadNormalizer->normalize($file);
        $preprocessMs = (int) round((microtime(true) - $preprocessStarted) * 1000);

        try {
            $persistStarted = microtime(true);
            $disk = (string) config('filesystems.media', 'local');
            $directory = 'intakes/'.$intake->uuid.'/follow-up/'.$item->round->round_number.'/'.$item->id;
            $basename = Str::ulid()->toBase32();

            $path = $directory.'/'.$basename.'.'.$normalized->extension;
            $absolutePath = $normalized->absolutePath;
            $mime = $normalized->mime;
            $sizeBytes = $normalized->sizeBytes;
            $checksum = $normalized->checksum;

            $duplicate = IntakeUpload::query()
                ->where('intake_id', $intake->id)
                ->where('intake_follow_up_item_id', $item->id)
                ->where('checksum', $checksum)
                ->first();

            if ($duplicate instanceof IntakeUpload) {
                return $duplicate;
            }

            if (! Storage::disk($disk)->put($path, File::get($absolutePath))) {
                $this->cleanupFailedUpload($disk, $path);

                throw ValidationException::withMessages([
                    'upload' => 'Upload mislukt. Probeer het opnieuw.',
                ]);
            }

            $upload = DB::transaction(function () use (
                $intake,
                $item,
                $disk,
                $path,
                $normalized,
                $mime,
                $sizeBytes,
                $checksum,
                $maxFiles,
                $fileLabel,
                $preprocessMs,
                $persistStarted,
            ): IntakeUpload {
                $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
                $lockedItem = IntakeFollowUpItem::query()->with('round')->lockForUpdate()->findOrFail($item->id);

                if ($lockedItem->round->intake_id !== $lockedIntake->id
                    || $lockedItem->round->status !== FollowUpRoundStatus::Open
                    || $lockedItem->type !== FollowUpItemType::Document
                    || $lockedIntake->status !== IntakeStatus::AwaitingCustomer) {
                    throw ValidationException::withMessages([
                        'upload' => 'Deze uploadopdracht is niet meer beschikbaar.',
                    ]);
                }

                $currentCount = $lockedItem->uploads()->count();

                if ($currentCount >= $maxFiles) {
                    throw ValidationException::withMessages([
                        'upload' => "Je kunt maximaal {$maxFiles} {$fileLabel}s bij deze opdracht uploaden.",
                    ]);
                }

                $persistMs = (int) round((microtime(true) - $persistStarted) * 1000);
                $timings = [
                    'persist_ms' => $persistMs,
                    'preprocess_ms' => $preprocessMs,
                    'measured_at' => now()->toIso8601String(),
                ];

                $upload = IntakeUpload::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => 'follow_up_'.$item->id,
                    'section_instance_key' => null,
                    'intake_follow_up_item_id' => $item->id,
                    'disk' => $disk,
                    'path' => $path,
                    'analysis_path' => null,
                    'original_filename' => $normalized->originalFilename,
                    'mime_type' => $mime,
                    'size_bytes' => $sizeBytes,
                    'checksum' => $checksum,
                    'analysis_mime_type' => null,
                    'analysis_size_bytes' => null,
                    'analysis_checksum' => null,
                    'sort_order' => $currentCount + 1,
                    'processing_timings' => $timings,
                    'assessment_status' => null,
                    'assessment_queued_at' => null,
                    'assessment_attempts' => 0,
                ]);

                $lockedItem->update(['answered_at' => now()]);

                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'customer',
                    'actor_id' => null,
                    'event' => 'follow_up_upload_stored',
                    'properties' => [
                        'round_number' => $lockedItem->round->round_number,
                        'item_id' => $lockedItem->id,
                        'item_type' => $lockedItem->type->value,
                        'upload_id' => $upload->id,
                    ],
                    'created_at' => now(),
                ]);

                return $upload;
            });

            $this->recordUploadTrace($intake, $upload, $item);

            return $upload;
        } catch (Throwable $exception) {
            if (isset($disk, $path)) {
                $this->cleanupFailedUpload($disk, $path);
            }

            throw $exception;
        }
    }

    private function storePhotoLightly(
        Intake $intake,
        IntakeFollowUpItem $item,
        UploadedFile $file,
        int $maxFiles,
        string $fileLabel,
    ): IntakeUpload {
        $mime = strtolower(trim($this->mimeDetector->detect($file)));
        $mime = match ($mime) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            default => $mime,
        };

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'], true)) {
            throw ValidationException::withMessages([
                'upload' => 'Alleen JPEG, PNG, WebP of HEIC/HEIF-foto’s zijn toegestaan.',
            ]);
        }

        $persistStarted = microtime(true);
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'upload' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        $bytes = (string) file_get_contents($realPath);
        if ($bytes === '') {
            throw ValidationException::withMessages([
                'upload' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        $checksum = hash('sha256', $bytes);
        $duplicate = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('intake_follow_up_item_id', $item->id)
            ->where('checksum', $checksum)
            ->first();

        if ($duplicate instanceof IntakeUpload) {
            return $duplicate;
        }

        $disk = (string) config('filesystems.media', 'local');
        $directory = 'intakes/'.$intake->uuid.'/follow-up/'.$item->round->round_number.'/'.$item->id;
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic', 'image/heif' => 'heic',
            default => 'jpg',
        };
        $basename = Str::ulid()->toBase32();
        $path = $directory.'/'.$basename.'.'.$extension;

        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw ValidationException::withMessages([
                'upload' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        try {
            $upload = DB::transaction(function () use (
                $intake,
                $item,
                $disk,
                $path,
                $mime,
                $bytes,
                $checksum,
                $file,
                $extension,
                $maxFiles,
                $fileLabel,
                $persistStarted,
            ): IntakeUpload {
                $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
                $lockedItem = IntakeFollowUpItem::query()->with('round')->lockForUpdate()->findOrFail($item->id);

                if ($lockedItem->round->intake_id !== $lockedIntake->id
                    || $lockedItem->round->status !== FollowUpRoundStatus::Open
                    || $lockedItem->type !== FollowUpItemType::Photo
                    || $lockedIntake->status !== IntakeStatus::AwaitingCustomer) {
                    throw ValidationException::withMessages([
                        'upload' => 'Deze uploadopdracht is niet meer beschikbaar.',
                    ]);
                }

                $currentCount = $lockedItem->uploads()->count();
                if ($currentCount >= $maxFiles) {
                    throw ValidationException::withMessages([
                        'upload' => "Je kunt maximaal {$maxFiles} {$fileLabel}s bij deze opdracht uploaden.",
                    ]);
                }

                $persistMs = (int) round((microtime(true) - $persistStarted) * 1000);
                $original = (string) $file->getClientOriginalName();
                $base = Str::slug((string) pathinfo($original, PATHINFO_FILENAME));
                if ($base === '') {
                    $base = 'foto';
                }

                $upload = IntakeUpload::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => 'follow_up_'.$item->id,
                    'section_instance_key' => null,
                    'intake_follow_up_item_id' => $item->id,
                    'disk' => $disk,
                    'path' => $path,
                    'analysis_path' => null,
                    'original_filename' => Str::limit($base, 80, '').'.'.$extension,
                    'mime_type' => $mime,
                    'size_bytes' => strlen($bytes),
                    'checksum' => $checksum,
                    'sort_order' => $currentCount + 1,
                    'processing_timings' => [
                        'persist_ms' => $persistMs,
                        'preprocess_ms' => 0,
                        'variants_pending' => true,
                        'variants_ready' => false,
                        'measured_at' => now()->toIso8601String(),
                    ],
                    'assessment_status' => PhotoAssessmentStatus::Pending,
                    'assessment_queued_at' => now(),
                    'assessment_attempts' => 0,
                ]);

                $lockedItem->update(['answered_at' => now()]);

                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'customer',
                    'actor_id' => null,
                    'event' => 'follow_up_upload_stored',
                    'properties' => [
                        'round_number' => $lockedItem->round->round_number,
                        'item_id' => $lockedItem->id,
                        'item_type' => $lockedItem->type->value,
                        'upload_id' => $upload->id,
                        'variants_pending' => true,
                    ],
                    'created_at' => now(),
                ]);

                return $upload;
            });

            ProcessIntakePhotoVariantsJob::dispatch($upload->id);

            // Sync queue runs the job immediately; reload attrs in place so
            // wasRecentlyCreated stays true for the wizard duplicate check.
            $upload->refresh();

            return $upload;
        } catch (Throwable $exception) {
            $this->cleanupFailedUpload($disk, $path);

            throw $exception;
        }
    }

    private function recordUploadTrace(Intake $intake, IntakeUpload $upload, IntakeFollowUpItem $item): void
    {
        // Alleen documenten: foto-AI schrijft zelf één volledige follow_up_photo_subject-trace gekoppeld aan ai_run.
        if ($item->type === FollowUpItemType::Photo) {
            return;
        }

        $trace = $this->traceRecorder->start($intake, AiTraceCallType::TextExtraction, [
            'upload_id' => $upload->id,
            'subject_type' => 'follow_up_item',
            'subject_id' => (string) $item->id,
        ]);
        $trace->linkUpload($upload);
        $trace->recordRequest(
            systemAndUser: [
                'system' => 'follow_up_upload_persist',
                'user' => ['follow_up_item_id' => $item->id, 'upload_id' => $upload->id],
            ],
        );
        $trace->succeed();
    }

    private function cleanupFailedUpload(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
            // Retry asynchronously below.
        }

        DeleteStoredMediaJob::dispatch($disk, $path);
    }
}
