<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SummarizeIntakeJob implements ShouldBeUnique, ShouldQueue
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

        $summarizeIntake->handle($intake);
    }
}
