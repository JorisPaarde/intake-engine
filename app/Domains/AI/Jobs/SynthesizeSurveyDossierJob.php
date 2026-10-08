<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Support\DossierSynthesisEligibility;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Dossiersynthese: CompleteIntake/follow-up (preserve=false) of debounced na optie/notities
 * (preserve=true + delay). Uniek tot processing per intake+modus; WithoutOverlapping deelt
 * de uitvoeringsslot.
 *
 * Preserve (auto): Reviewed/AwaitingCustomer/Cancelled overslaan.
 * Replace (Complete*): alleen Cancelled — Reviewed na follow-up moet wél draaien (T1/#167).
 *
 * Overlap-releases tellen niet als exceptions: retryUntil + maxExceptions i.p.v. tries=2.
 */
final class SynthesizeSurveyDossierJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Alleen echte exceptions; overlap-release brandt dit niet op. */
    public int $maxExceptions = 2;

    public int $uniqueFor = 120;

    /** Vaste debounce-vertraging; geen aparte env-knop meer. */
    public const DELAY_SECONDS = 20;

    public function __construct(
        public readonly int $intakeId,
        public readonly bool $preserveProposedCustomerTasks = false,
    ) {}

    /**
     * Achtergrond na installatiekeuze / auto_after_notes: delay + preserve upsert.
     */
    public static function dispatchDebounced(int $intakeId): void
    {
        self::dispatch($intakeId, preserveProposedCustomerTasks: true)
            ->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function uniqueId(): string
    {
        // Preserve/debounce en full-replace (CompleteIntake) hebben aparte slots.
        return 'dossier-synthesis:'.$this->intakeId.':'
            .($this->preserveProposedCustomerTasks ? 'preserve' : 'replace');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(5);
    }

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

        if ($intake === null) {
            return;
        }

        if ($this->preserveProposedCustomerTasks) {
            if (! DossierSynthesisEligibility::allowsStatus($intake->status)) {
                return;
            }
        } elseif ($intake->status === IntakeStatus::Cancelled) {
            return;
        }

        $synthesize->handle(
            $intake,
            preserveProposedCustomerTasks: $this->preserveProposedCustomerTasks,
        );
    }
}
