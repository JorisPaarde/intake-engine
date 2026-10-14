<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\Intake\Models\Intake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class AssessFollowUpFuseboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $intakeId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('follow-up-fusebox:'.$this->intakeId))->releaseAfter(10)->expireAfter(180)];
    }

    public function handle(AssessFuseboxPhotos $assess): void
    {
        $intake = Intake::query()->find($this->intakeId);
        if ($intake !== null && AssessFuseboxPhotos::mayAnalyzeFollowUp($intake)) {
            try {
                $assess->handleFollowUp($intake);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Never stall the rest of the chain (summary, attention, synthesis). */
    public function failed(?Throwable $exception): void
    {
        $this->dispatchNextJobInChain();
    }
}
