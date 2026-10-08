<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\DeleteFollowUpUpload;
use Illuminate\Console\Command;

/**
 * BL-147 (UX #16.4): a follow-up photo the customer removed stays in the bin when the
 * tab closes within the undo window. This hourly job purges what is older than
 * intake.follow_up.removed_upload_purge_minutes (media + evidence links, activity event).
 */
final class PurgeRemovedFollowUpUploadsCommand extends Command
{
    protected $signature = 'photos:purge-removed';

    protected $description = 'Wis door de klant weggehaalde aanvulfoto’s die langer dan de bewaartijd in de prullenbak staan';

    public function handle(DeleteFollowUpUpload $deleteFollowUpUpload): int
    {
        $minutes = max(1, (int) config('intake.follow_up.removed_upload_purge_minutes', 10));
        $purged = $deleteFollowUpUpload->purgeRemovedBefore(now()->subMinutes($minutes));

        $this->info("Purged {$purged} removed follow-up upload(s).");

        return self::SUCCESS;
    }
}
