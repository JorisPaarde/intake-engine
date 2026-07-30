<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\PhotoUploadNormalizer;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StoreIntakeUpload
{
    public function __construct(
        private readonly ProgressCalculator $progressCalculator,
        private readonly PhotoUploadNormalizer $photoUploadNormalizer,
    ) {}

    public function handle(
        Intake $intake,
        string $questionKey,
        ?string $sectionInstanceKey,
        UploadedFile $file,
    ): IntakeUpload {
        $question = $this->findPhotoQuestion($intake, $questionKey);
        $maxFiles = (int) ($question->meta['max_files'] ?? config('intake.uploads.max_files_per_question', 5));
        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 5120);

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

        $normalized = $this->photoUploadNormalizer->normalize($file);

        try {
            $disk = (string) config('filesystems.media', 'local');
            $directory = $this->directory($intake, $questionKey, $sectionInstanceKey);
            $basename = Str::ulid()->toBase32();
            $path = $directory.'/'.$basename.'.'.$normalized->dossierExtension;
            $analysisPath = $directory.'/analysis/'.$basename.'.'.$normalized->analysisExtension;

            if (! Storage::disk($disk)->put($path, File::get($normalized->dossierAbsolutePath))
                || ! Storage::disk($disk)->put($analysisPath, File::get($normalized->analysisAbsolutePath))) {
                $this->cleanupFailedUpload($disk, $path);
                $this->cleanupFailedUpload($disk, $analysisPath);

                throw ValidationException::withMessages([
                    'photo' => 'Upload mislukt. Probeer het opnieuw.',
                ]);
            }

            return DB::transaction(function () use ($intake, $questionKey, $sectionInstanceKey, $disk, $path, $analysisPath, $normalized, $maxFiles): IntakeUpload {
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

                $upload = IntakeUpload::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => $questionKey,
                    'section_instance_key' => $sectionInstanceKey,
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
                    'sort_order' => $currentCount + 1,
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
                    ],
                    'created_at' => now(),
                ]);

                return $upload;
            });
        } catch (Throwable $exception) {
            if (isset($disk, $path)) {
                $this->cleanupFailedUpload($disk, $path);
            }

            if (isset($disk, $analysisPath)) {
                $this->cleanupFailedUpload($disk, $analysisPath);
            }

            throw $exception;
        } finally {
            foreach ($normalized->cleanupPaths as $cleanupPath) {
                @unlink($cleanupPath);
            }
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
}
