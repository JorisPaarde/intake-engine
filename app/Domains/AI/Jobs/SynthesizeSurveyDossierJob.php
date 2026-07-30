<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class SynthesizeSurveyDossierJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $intakeId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('dossier-synthesis:'.$this->intakeId))
            ->releaseAfter(5)
            ->expireAfter(300)];
    }

    public function handle(SynthesizeSurveyDossier $synthesize): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null || $intake->status === IntakeStatus::Cancelled) {
            return;
        }

        $synthesize->handle($intake);
    }
}
