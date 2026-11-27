<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BL-172: na versturen de catalogus-prefill nog één keer draaien met de wensentekst
 * (vrij typen/dicteren), vóór samenvatting, aandachtspunten en dossiersynthese.
 *
 * Faalt zacht: een AI-fout mag de rest van de keten nooit tegenhouden.
 */
final class PrefillFromCustomerWishesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $intakeId,
    ) {}

    public function handle(PrefillAnswersFromKnownContext $prefill): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null || $intake->status !== IntakeStatus::Completed || $intake->reviewed_at !== null) {
            return;
        }

        try {
            $prefill->handle($intake, afterCustomerSubmit: true);
        } catch (Throwable $exception) {
            Log::warning('request_prefill.customer_wishes_failed', [
                'intake_id' => $this->intakeId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
