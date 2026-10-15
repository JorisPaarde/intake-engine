<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Services\SummaryDossierContext;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SummarizeIntakeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $intakeId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->intakeId;
    }

    public function handle(SummarizeIntake $summarizeIntake): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null) {
            return;
        }

        if (! in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)) {
            Log::info('Skipping AI summary: intake not completed', ['intake_id' => $this->intakeId]);

            return;
        }

        $before = $this->storedHash($intake);
        $summarizeIntake->handle($intake);

        // N69: the dossier may have changed while this job ran (its unique lock is
        // released on start). Only after a successful run (hash stored) re-check once,
        // so a failing provider never loops.
        $fresh = $intake->fresh();
        if ($fresh !== null && $this->storedHash($fresh) !== $before) {
            app(SummaryDossierContext::class)->dispatchIfStale($fresh);
        }
    }

    private function storedHash(Intake $intake): ?string
    {
        $intake->loadMissing('report');
        $meta = $intake->report?->meta;
        $hash = is_array($meta) ? ($meta['ai_dossier_hash'] ?? null) : null;

        return is_string($hash) ? $hash : null;
    }
}
