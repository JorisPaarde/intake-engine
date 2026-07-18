<?php

declare(strict_types=1);

namespace App\Domains\Intake\Jobs;

use App\Domains\Intake\Actions\GenerateIntakePdf;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class GenerateIntakePdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $intakeId,
    ) {}

    public function handle(GenerateIntakePdf $generateIntakePdf): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null) {
            return;
        }

        if ($intake->is_demo) {
            return;
        }

        if (! in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)) {
            Log::info('Skipping PDF generation: intake not completed', ['intake_id' => $this->intakeId]);

            return;
        }

        $generateIntakePdf->handle($intake);
    }
}
