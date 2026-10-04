<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\UploadMimeDetector;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores a customer photo quickly (source bytes only) and queues heavy variant
 * processing (BL-143). Keeps the Livewire update request light so shared-hosting
 * 503s during GD/Imagick decode are avoided.
 */
final class StoreIntakeUpload
{
    public function __construct(
        private readonly ProgressCalculator $progressCalculator,
        private readonly UploadMimeDetector $mimeDetector,
    ) {}

    public function handle(
        Intake $intake,
        string $questionKey,
        ?string $sectionInstanceKey,
        UploadedFile $file,
        ?int $clientOriginalWidth = null,
        ?int $clientOriginalHeight = null,
        ?string $correlationId = null,
    ): IntakeUpload {
        $question = $this->findPhotoQuestion($intake, $questionKey);
        $maxFiles = (int) ($question->meta['max_files'] ?? config('intake.uploads.max_files_per_question', 5));
        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 8192);

        $existingCount = $this->uploadsQuery($intake, $questionKey, $sectionInstanceKey)->count();

        if ($existingCount >= $maxFiles) {
            throw ValidationException::withMessages([
                'photo' => "Je kunt maximaal {$maxFiles} foto’s bij deze vraag uploaden.",
            ]);
        }

        if ($file->getSize() !== false && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto is te groot. Maximaal '.($maxKilobytes / 1024).' MB.',
            ]);
        }

        if (! in_array($intake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
            throw ValidationException::withMessages([
                'photo' => 'Deze opname kan niet meer worden gewijzigd.',
            ]);
        }

        $mime = $this->normalizeMime($this->mimeDetector->detect($file));
        if (! in_array($mime, $this->acceptedMimes(), true)) {
            throw ValidationException::withMessages([
                'photo' => 'Alleen JPEG, PNG, WebP of HEIC/HEIF-foto’s zijn toegestaan. Foto’s worden automatisch verkleind.',
            ]);
        }

        $persistStarted = microtime(true);
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'photo' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        $bytes = (string) file_get_contents($realPath);
        if ($bytes === '') {
            throw ValidationException::withMessages([
                'photo' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        $checksum = hash('sha256', $bytes);
        $sizeBytes = strlen($bytes);
        $extension = $this->extensionForMime($mime, (string) $file->getClientOriginalExtension());
        $originalFilename = $this->safeOriginalFilename($file, $extension);

        $duplicateQuery = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey)
            ->where('checksum', $checksum);

        if ($sectionInstanceKey === null) {
            $duplicateQuery->whereNull('section_instance_key');
        } else {
            $duplicateQuery->where('section_instance_key', $sectionInstanceKey);
        }

        $duplicate = $duplicateQuery->first();
        if ($duplicate instanceof IntakeUpload) {
            return $duplicate;
        }

        $disk = (string) config('filesystems.media', 'local');
        $directory = $this->directory($intake, $questionKey, $sectionInstanceKey);
        $basename = Str::ulid()->toBase32();
        $path = $directory.'/'.$basename.'.'.$extension;

        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw ValidationException::withMessages([
                'photo' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        try {
            // Persist the source row first; dispatch heavy variants only after commit so
            // database-queue workers never race an uncommitted upload (BL-143).
            $upload = DB::transaction(function () use (
                $intake,
                $questionKey,
                $sectionInstanceKey,
                $disk,
                $path,
                $originalFilename,
                $mime,
                $sizeBytes,
                $checksum,
                $maxFiles,
                $persistStarted,
                $clientOriginalWidth,
                $clientOriginalHeight,
                $correlationId,
            ): IntakeUpload {
                $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedIntake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
                    throw ValidationException::withMessages([
                        'photo' => 'Deze opname kan niet meer worden gewijzigd.',
                    ]);
                }

                $currentCount = $this->uploadsQuery($intake, $questionKey, $sectionInstanceKey)->count();

                if ($currentCount >= $maxFiles) {
                    throw ValidationException::withMessages([
                        'photo' => "Je kunt maximaal {$maxFiles} foto’s bij deze vraag uploaden.",
                    ]);
                }

                $persistMs = (int) round((microtime(true) - $persistStarted) * 1000);
                $timings = [
                    'persist_ms' => $persistMs,
                    'preprocess_ms' => 0,
                    'variants_pending' => true,
                    'variants_ready' => false,
                    'original_width' => $clientOriginalWidth,
                    'original_height' => $clientOriginalHeight,
                    'measured_at' => now()->toIso8601String(),
                ];
                if (is_string($correlationId) && $correlationId !== '') {
                    $timings['correlation_id'] = $correlationId;
                }

                $upload = IntakeUpload::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => $questionKey,
                    'section_instance_key' => $sectionInstanceKey,
                    'disk' => $disk,
                    'path' => $path,
                    'analysis_path' => null,
                    'original_filename' => $originalFilename,
                    'mime_type' => $mime,
                    'size_bytes' => $sizeBytes,
                    'checksum' => $checksum,
                    'analysis_mime_type' => null,
                    'analysis_size_bytes' => null,
                    'analysis_checksum' => null,
                    'sort_order' => $currentCount + 1,
                    'processing_timings' => $timings,
                    'assessment_status' => PhotoAssessmentStatus::Pending,
                    'assessment_queued_at' => now(),
                    'assessment_attempts' => 0,
                ]);

                $this->syncAnswerUploadIds($intake, $questionKey, $sectionInstanceKey);
                $this->touchProgress($intake);

                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'customer',
                    'actor_id' => null,
                    'event' => 'upload_stored',
                    'properties' => [
                        'upload_id' => $upload->id,
                        'question_key' => $questionKey,
                        'variants_pending' => true,
                    ],
                    'created_at' => now(),
                ]);

                return $upload;
            });

            ProcessIntakePhotoVariantsJob::dispatch(
                $upload->id,
                $clientOriginalWidth,
                $clientOriginalHeight,
                $correlationId,
            );

            // Sync queue runs the job immediately; reload attrs in place so
            // wasRecentlyCreated stays true for the wizard duplicate check.
            $upload->refresh();

            return $upload;
        } catch (Throwable $exception) {
            $this->cleanupFailedUpload($disk, $path);

            throw $exception;
        }
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

    private function findPhotoQuestion(Intake $intake, string $questionKey): IntakeQuestion
    {
        $intake->loadMissing(['templateVersion.sections.questions']);

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey && $question->type === QuestionType::Photo) {
                    return $question;
                }
            }
        }

        throw ValidationException::withMessages([
            'photo' => 'Onbekende foto-vraag.',
        ]);
    }

    /**
     * @return Builder<IntakeUpload>
     */
    private function uploadsQuery(Intake $intake, string $questionKey, ?string $sectionInstanceKey)
    {
        $query = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey);

        if ($sectionInstanceKey === null) {
            $query->whereNull('section_instance_key');
        } else {
            $query->where('section_instance_key', $sectionInstanceKey);
        }

        return $query;
    }

    private function directory(Intake $intake, string $questionKey, ?string $sectionInstanceKey): string
    {
        $parts = ['intakes', $intake->uuid, $questionKey];

        if ($sectionInstanceKey !== null && $sectionInstanceKey !== '') {
            $parts[] = $sectionInstanceKey;
        }

        return implode('/', $parts);
    }

    private function syncAnswerUploadIds(Intake $intake, string $questionKey, ?string $sectionInstanceKey): void
    {
        $ids = $this->uploadsQuery($intake, $questionKey, $sectionInstanceKey)
            ->orderBy('sort_order')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $query = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey);

        if ($sectionInstanceKey === null) {
            $query->whereNull('section_instance_key');
        } else {
            $query->where('section_instance_key', $sectionInstanceKey);
        }

        $answer = $query->first();

        if ($answer === null) {
            IntakeAnswer::query()->create([
                'intake_id' => $intake->id,
                'question_key' => $questionKey,
                'section_instance_key' => $sectionInstanceKey,
                'value' => ['upload_ids' => $ids],
                'answered_at' => now(),
            ]);

            return;
        }

        $answer->update([
            'value' => ['upload_ids' => $ids],
            'answered_at' => now(),
        ]);
    }

    private function touchProgress(Intake $intake): void
    {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
        $progress = $this->progressCalculator->calculate($intake, $version);

        $updates = [
            'progress_percent' => $progress['percent'],
        ];

        if ($intake->status === IntakeStatus::Sent) {
            $updates['status'] = IntakeStatus::InProgress;
            $updates['started_at'] = $intake->started_at ?? now();
        }

        $intake->update($updates);
    }

    /**
     * @return list<string>
     */
    private function acceptedMimes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match ($mime) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            default => $mime,
        };
    }

    private function extensionForMime(string $mime, string $clientExtension): string
    {
        $client = strtolower($clientExtension);

        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic', 'image/heif' => in_array($client, ['heic', 'heif'], true) ? $client : 'heic',
            default => 'jpg',
        };
    }

    private function safeOriginalFilename(UploadedFile $file, string $extension): string
    {
        $name = (string) $file->getClientOriginalName();
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = Str::slug($base);
        if ($base === '') {
            $base = 'foto';
        }

        return Str::limit($base, 80, '').'.'.$extension;
    }
}
