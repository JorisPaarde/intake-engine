<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Leidt aanvraagintent af buiten de webrequest (notities, create, adresretry).
 * Uniek per intake zodat snelle opeenvolgende notities één run delen.
 * Optioneel daarna dossiersynthese — zelfde Uniqueness/overlap via SynthesizeSurveyDossierJob.
 */
final class DeriveIntentFromRequestJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $intakeId,
        public readonly bool $allowExternal = true,
        public readonly bool $chainDossierSynthesis = false,
    ) {}

    public function uniqueId(): string
    {
        return 'derive-intent:'.$this->intakeId;
    }

    public function handle(DeriveIntentFromRequest $derive): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null || $intake->status === IntakeStatus::Cancelled) {
            return;
        }

        $derive->handle($intake, $this->allowExternal);

        if (! $this->chainDossierSynthesis) {
            return;
        }

        $fresh = $intake->fresh() ?? $intake;
        if ($fresh->aircoInstallationOptions()->exists()) {
            SynthesizeSurveyDossierJob::dispatch($fresh->id);
        }
    }
}
