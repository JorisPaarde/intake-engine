<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\PublicDemoWorkspaceProvisioner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class PurgeDemoIntakesCommand extends Command
{
    protected $signature = 'intakes:purge-demos';

    protected $description = 'Hard-purge verlopen demo-intakes inclusief mediabestanden';

    public function handle(
        HardDeleteIntake $hardDeleteIntake,
        PublicDemoWorkspaceProvisioner $workspaceProvisioner,
    ): int {
        $cutoff = now()->subHours(max(1, (int) config('intake.demo.ttl_hours', 2)));

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

        $query->chunkById(50, function (Collection $intakes) use (
            $hardDeleteIntake,
            $workspaceProvisioner,
            &$purged,
        ): void {
            foreach ($intakes as $intake) {
                $userId = (int) $intake->created_by;
                $companyId = (int) $intake->company_id;
                $hardDeleteIntake->handle($intake);
                $workspaceProvisioner->cleanupIfOrphaned($userId, $companyId);
                $purged++;
            }
        });

        $this->info("Purged {$purged} demo intake(s).");

        return self::SUCCESS;
    }
}
