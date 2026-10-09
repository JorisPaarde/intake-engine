<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\Intake;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Permanently removes an intake (and cascaded DB rows) plus media files on disk.
 * Also removes the intake's private upload directory tree (`intakes/{uuid}/`)
 * so demo purge does not leave empty folders behind.
 */
final class HardDeleteIntake
{
    public function handle(Intake $intake): void
    {
        $intake->loadMissing(['report', 'externalFacts']);
        $files = [];
        $directoryDisk = (string) config('filesystems.media', 'local');
        $directoryPath = 'intakes/'.$intake->uuid;

        foreach ($intake->uploads()->withTrashed()->get() as $upload) {
            $files[] = [$upload->disk, $upload->path];

            if ($upload->analysis_path !== null) {
                $files[] = [$upload->disk, $upload->analysis_path];
            }
        }

        foreach ($intake->externalFacts as $fact) {
            $disk = $fact->value['media_disk'] ?? null;
            $path = $fact->value['media_path'] ?? null;
            $files[] = [
                is_string($disk) ? $disk : null,
                is_string($path) ? $path : null,
            ];
        }

        $report = $intake->report;
        if ($report !== null && $report->hasPdf()) {
            $files[] = [(string) $report->pdf_disk, (string) $report->pdf_path];
        }

        $intake->forceDelete();

        foreach ($files as [$disk, $path]) {
            $this->deleteStorageFile($disk, $path);
        }

        $this->deleteStorageDirectory($directoryDisk, $directoryPath);
    }

    private function deleteStorageFile(?string $disk, ?string $path): void
    {
        if ($disk === null || $disk === '' || $path === null || $path === '') {
            return;
        }

        DeleteStoredMediaJob::deleteNowOrQueue($disk, $path);
    }

    private function deleteStorageDirectory(string $disk, string $directory): void
    {
        if ($directory === '' || $directory === '/' || ! str_starts_with($directory, 'intakes/')) {
            return;
        }

        try {
            if (Storage::disk($disk)->directoryExists($directory)) {
                Storage::disk($disk)->deleteDirectory($directory);
            }
        } catch (Throwable) {
            // Best-effort: individual files already queued above.
        }
    }
}
