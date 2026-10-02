<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiTrace;
use Illuminate\Console\Command;

final class PurgeAiTracesCommand extends Command
{
    protected $signature = 'ai:purge-traces {--days= : Override retention days from config}';

    protected $description = 'Verwijder AI-traces ouder dan de configureerbare bewaartermijn';

    public function handle(): int
    {
        $days = $this->option('days');
        $retention = is_numeric($days)
            ? max(1, (int) $days)
            : max(1, (int) config('ai.tracing.retention_days', 30));

        $cutoff = now()->subDays($retention);

        $deleted = AiTrace::query()
            ->where('created_at', '<=', $cutoff)
            ->delete();

        $this->info("Purged {$deleted} AI-trace(s) older than {$retention} day(s).");

        return self::SUCCESS;
    }
}
