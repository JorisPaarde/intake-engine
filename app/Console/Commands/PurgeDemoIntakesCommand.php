<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

final class PurgeDemoIntakesCommand extends Command
{
    protected $signature = 'intakes:purge-demos';

    protected $description = 'Hard-purge verlopen demo-intakes inclusief mediabestanden';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) config('intake.demo.ttl_hours', 12)));

        $query = Intake::query()
            ->withTrashed()
            ->with('uploads')
            ->where('is_demo', true)
            ->where(function ($builder) use ($cutoff): void {
                $builder
                    ->where(function ($inner): void {
                        $inner->whereNotNull('token_expires_at')
                            ->where('token_expires_at', '<', now());
                    })
                    ->orWhere('created_at', '<', $cutoff);
            });

        $purged = 0;

        $query->chunkById(50, function (Collection $intakes) use (&$purged): void {
            foreach ($intakes as $intake) {
                foreach ($intake->uploads as $upload) {
                    $this->deleteUploadFile($upload);
                }

                $intake->forceDelete();
                $purged++;
            }
        });

        $this->info("Purged {$purged} demo intake(s).");

        return self::SUCCESS;
    }

    private function deleteUploadFile(IntakeUpload $upload): void
    {
        if ($upload->disk === '' || $upload->path === '') {
            return;
        }

        try {
            Storage::disk($upload->disk)->delete($upload->path);
        } catch (\Throwable) {
            // Best-effort: DB-rij verdwijnt via cascade/forceDelete; orphan files zijn acceptabel.
        }
    }
}
