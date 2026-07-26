<?php

declare(strict_types=1);

namespace App\Domains\Intake\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

final class DeleteStoredMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        if (! Storage::disk($this->disk)->delete($this->path)) {
            throw new \RuntimeException('Privébestand kon niet worden verwijderd.');
        }
    }
}
