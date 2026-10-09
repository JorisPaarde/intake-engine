<?php

declare(strict_types=1);

namespace App\Domains\Intake\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DeleteStoredMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
    ) {}

    /**
     * Delete right away; when that fails (false or exception), retry asynchronously via this job.
     */
    public static function deleteNowOrQueue(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
            // Retry asynchronously below.
        }

        self::dispatch($disk, $path);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $disk = Storage::disk($this->disk);

        // Idempotent: a prior sync cleanup or SoftDeletes race may already have removed the file.
        if (! $disk->exists($this->path)) {
            return;
        }

        if (! $disk->delete($this->path)) {
            throw new \RuntimeException('Privébestand kon niet worden verwijderd.');
        }
    }
}
