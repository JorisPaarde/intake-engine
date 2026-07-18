<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes an intake (and cascaded DB rows) plus media files on disk.
 */
final class HardDeleteIntake
{
    public function handle(Intake $intake): void
    {
        $intake->loadMissing('report');

        foreach ($intake->uploads()->withTrashed()->get() as $upload) {
            $this->deleteStorageFile($upload->disk, $upload->path);
        }

        $report = $intake->report;
        if ($report !== null && $report->hasPdf()) {
            $this->deleteStorageFile((string) $report->pdf_disk, (string) $report->pdf_path);
        }

        $intake->forceDelete();
    }

    private function deleteStorageFile(?string $disk, ?string $path): void
    {
        if ($disk === null || $disk === '' || $path === null || $path === '') {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            // Best-effort: DB-rij verdwijnt via forceDelete; orphan files zijn acceptabel.
        }
    }
}
