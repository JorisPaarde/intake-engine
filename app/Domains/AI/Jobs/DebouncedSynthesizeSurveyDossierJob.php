<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Debounced workspace-synthese na installatiekeuze (niet de CompleteIntake-keten).
 * Uniek tot processing start: snelle opeenvolgende option-wijzigingen → één run.
 * preserveProposedCustomerTasks=true zodat send-by-id van Proposed taken blijft werken.
 * Slaat terminal/klantfase-statussen over (Completed/Reviewed/AwaitingCustomer/Cancelled).
 */
final class DebouncedSynthesizeSurveyDossierJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 120;

    /** @var list<IntakeStatus> */
    private const SKIP_STATUSES = [
        IntakeStatus::Completed,
        IntakeStatus::Reviewed,
        IntakeStatus::AwaitingCustomer,
        IntakeStatus::Cancelled,
    ];

    public function __construct(
        public readonly int $intakeId,
    ) {
        $delay = max(0, (int) config('ai.dossier_synthesis.delay_seconds', 20));
        if ($delay > 0) {
            $this->delay(now()->addSeconds($delay));
        }
    }

    public function uniqueId(): string
    {
        return 'debounced-dossier-synthesis:'.$this->intakeId;
    }

    public function handle(SynthesizeSurveyDossier $synthesize): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null || in_array($intake->status, self::SKIP_STATUSES, true)) {
            return;
        }

        $synthesize->handle($intake, preserveProposedCustomerTasks: true);
    }
}
