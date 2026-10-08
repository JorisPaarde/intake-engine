<?php

declare(strict_types=1);

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\Intake;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeStatus;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Leidt aanvraagintent af buiten de webrequest (notities, create, adresretry).
 * Uniek tot processing start: notitie tijdens een lopende run → nieuwe job daarna.
 * Optionele notes→synthese alleen via config ai.dossier_synthesis.auto_after_notes (default false).
 */
final class DeriveIntentFromRequestJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public int $uniqueFor = 120;

    public const PLACEHOLDER_PROVIDER = 'queue';

    public const PLACEHOLDER_MODEL = 'pending';

    public function __construct(
        public readonly int $intakeId,
        public readonly bool $allowExternal = true,
    ) {}

    public function uniqueId(): string
    {
        return 'derive-intent:'.$this->intakeId;
    }

    /**
     * Markeer prefill als pending vóór de queue-pickup (wizard-wacht).
     */
    public static function markPending(Intake $intake): void
    {
        if (self::hasRecentPending($intake->id)) {
            return;
        }

        AiRun::query()->create([
            'intake_id' => $intake->id,
            'type' => AiRunType::RequestIntent,
            'provider' => self::PLACEHOLDER_PROVIDER,
            'model' => self::PLACEHOLDER_MODEL,
            'prompt_version' => self::PLACEHOLDER_MODEL,
            'input_hash' => 'queued:'.$intake->id.':'.now()->timestamp,
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);
    }

    public static function hasRecentPending(int $intakeId): bool
    {
        $windowSeconds = max(30, (int) config('ai.request_prefill.pending_window_seconds', 300));

        return AiRun::query()
            ->where('intake_id', $intakeId)
            ->where('type', AiRunType::RequestIntent)
            ->where('status', AiRunStatus::Pending)
            ->where(function ($query) use ($windowSeconds): void {
                $query->where('started_at', '>=', now()->subSeconds($windowSeconds))
                    ->orWhere(function ($inner) use ($windowSeconds): void {
                        $inner->whereNull('started_at')
                            ->where('created_at', '>=', now()->subSeconds($windowSeconds));
                    });
            })
            ->exists();
    }

    public function handle(DeriveIntentFromRequest $derive): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null || $intake->status === IntakeStatus::Cancelled) {
            return;
        }

        try {
            $derive->handle($intake, $this->allowExternal);
        } finally {
            $this->completePlaceholders($this->intakeId);
        }

        if (! (bool) config('ai.dossier_synthesis.auto_after_notes', false)) {
            return;
        }

        $fresh = $intake->fresh() ?? $intake;
        if ($fresh->aircoInstallationOptions()->exists()) {
            DebouncedSynthesizeSurveyDossierJob::dispatch($fresh->id);
        }
    }

    private function completePlaceholders(int $intakeId): void
    {
        AiRun::query()
            ->where('intake_id', $intakeId)
            ->where('type', AiRunType::RequestIntent)
            ->where('status', AiRunStatus::Pending)
            ->where('provider', self::PLACEHOLDER_PROVIDER)
            ->where('model', self::PLACEHOLDER_MODEL)
            ->update([
                'status' => AiRunStatus::Succeeded,
                'finished_at' => now(),
                'error_message' => null,
            ]);
    }
}
