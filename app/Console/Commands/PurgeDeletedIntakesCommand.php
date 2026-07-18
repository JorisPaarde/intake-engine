<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Models\Intake;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class PurgeDeletedIntakesCommand extends Command
{
    protected $signature = 'intakes:purge-deleted';

    protected $description = 'Hard-purge soft-deleted intakes na de bewaartermijn, inclusief mediabestanden';

    public function handle(HardDeleteIntake $hardDeleteIntake): int
    {
        $days = max(1, (int) config('intake.retention.soft_delete_days', 30));
        $cutoff = now()->subDays($days);

        $query = Intake::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff);

        $purged = 0;

        $query->chunkById(50, function (Collection $intakes) use ($hardDeleteIntake, &$purged): void {
            foreach ($intakes as $intake) {
                $hardDeleteIntake->handle($intake);
                $purged++;
            }
        });

        $this->info("Purged {$purged} soft-deleted intake(s).");

        return self::SUCCESS;
    }
}
