<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\Intake;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeSource;

/**
 * Embed-opnames starten zonder request-prefill (BL-152). Bij de eerste echte
 * klantstap of foto dispatchen we precies één keer (BL-153). Idempotent via
 * bestaande RequestIntent-ai_runs (inclusief queue-placeholder).
 *
 * Niet-embed-opnames: no-op — die krijgen prefill nog steeds bij create.
 */
final class DispatchEmbedRequestPrefillIfNeeded
{
    public function handle(Intake $intake): bool
    {
        if ($intake->source !== IntakeSource::Embed) {
            return false;
        }

        if ($this->alreadyAttempted($intake->id)) {
            return false;
        }

        // skipIfCustomerStarted=false: we dispatchen juist ná/bij klantstart.
        // De create-async-wacht gebruikt true zodat late jobs geen fills meer schrijven.
        if ((bool) config('ai.request_prefill.sync_on_create', false)) {
            DeriveIntentFromRequestJob::markPending($intake);
            app(DeriveIntentFromRequest::class)->handle(
                $intake,
                allowExternal: true,
                skipIfCustomerStarted: false,
            );
            $this->finalizeSyncPlaceholders($intake->id);

            return true;
        }

        DeriveIntentFromRequestJob::markPending($intake);
        DeriveIntentFromRequestJob::dispatch(
            $intake->id,
            allowExternal: true,
            skipIfCustomerStarted: false,
        );

        return true;
    }

    private function alreadyAttempted(int $intakeId): bool
    {
        return AiRun::query()
            ->where('intake_id', $intakeId)
            ->where('type', AiRunType::RequestIntent)
            ->exists();
    }

    private function finalizeSyncPlaceholders(int $intakeId): void
    {
        // markPending + synchrone derive: placeholder afronden als derive geen eigen run schreef.
        AiRun::query()
            ->where('intake_id', $intakeId)
            ->where('type', AiRunType::RequestIntent)
            ->where('status', AiRunStatus::Pending)
            ->where('provider', DeriveIntentFromRequestJob::PLACEHOLDER_PROVIDER)
            ->where('model', DeriveIntentFromRequestJob::PLACEHOLDER_MODEL)
            ->update([
                'status' => AiRunStatus::Succeeded,
                'finished_at' => now(),
                'error_message' => null,
            ]);
    }
}
