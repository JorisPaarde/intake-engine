<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Models\Intake;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class PurgeDemoIntakesCommand extends Command
{
    protected $signature = 'intakes:purge-demos';

    protected $description = 'Hard-purge verlopen demo-intakes inclusief mediabestanden';

    public function handle(HardDeleteIntake $hardDeleteIntake): int
    {
        $cutoff = now()->subHours(max(1, (int) config('intake.demo.ttl_hours', 12)));

        $query = Intake::query()
            ->withTrashed()
            ->where('is_demo', true)
            ->where(function ($builder) use ($cutoff): void {
                $builder
                    ->where(function ($inner): void {
                        $inner->whereNotNull('token_expires_at')
                            ->where('token_expires_at', '<', now());
                    })
                    ->orWhere('created_at', '<', $cutoff);
            });

        $purged = 0;

        $query->chunkById(50, function (Collection $intakes) use ($hardDeleteIntake, &$purged): void {
            foreach ($intakes as $intake) {
                $hardDeleteIntake->handle($intake);
                $purged++;
            }
        });

        $this->info("Purged {$purged} demo intake(s).");

        return self::SUCCESS;
    }
}
