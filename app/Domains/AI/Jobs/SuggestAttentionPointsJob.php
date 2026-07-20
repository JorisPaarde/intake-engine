<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SuggestAttentionPointsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $intakeId,
    ) {}

    public function handle(SuggestAttentionPoints $suggestAttentionPoints): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null) {
            return;
        }

        if (! in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)) {
            Log::info('Skipping AI attention points: intake not completed', ['intake_id' => $this->intakeId]);

            return;
        }

        $suggestAttentionPoints->handle($intake);
    }
}
