<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Models\AiTraceStep;
use Illuminate\Console\Command;

final class PurgeAiTracesCommand extends Command
{
    private const CHUNK = 1000;

    protected $signature = 'ai:purge-traces {--days= : Override retention days from config}';

    protected $description = 'Verwijder AI-traces ouder dan de configureerbare bewaartermijn';

    public function handle(): int
    {
        $days = $this->option('days');
        $retention = is_numeric($days)
            ? max(1, (int) $days)
            : max(1, (int) config('ai.tracing.retention_days', 30));

        $cutoff = now()->subDays($retention);
        $deleted = 0;

        do {
            $ids = AiTrace::query()
                ->where('created_at', '<=', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            AiTraceStep::query()
                ->whereIn('ai_trace_id', $ids)
                ->orderBy('id')
                ->chunkById(self::CHUNK, function ($steps): void {
                    AiTraceStep::query()
                        ->whereIn('id', $steps->pluck('id')->all())
                        ->delete();
                });

            $deleted += AiTrace::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === self::CHUNK);

        $this->info("Purged {$deleted} AI-trace(s) older than {$retention} day(s).");

        return self::SUCCESS;
    }
}
