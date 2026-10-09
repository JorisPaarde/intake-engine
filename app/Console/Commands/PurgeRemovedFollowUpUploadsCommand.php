<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\DeleteFollowUpUpload;
use App\Domains\Intake\Actions\DeleteIntakeUpload;
use Illuminate\Console\Command;

/**
 * BL-147 (UX #16.4): a photo the customer removed stays in the bin when the
 * tab closes within the undo window. This hourly job purges what is older than
 * intake.follow_up.removed_upload_purge_minutes (media + evidence links, activity event).
 */
final class PurgeRemovedFollowUpUploadsCommand extends Command
{
    protected $signature = 'photos:purge-removed';

    protected $description = 'Wis door de klant weggehaalde foto’s die langer dan de bewaartijd in de prullenbak staan';

    public function handle(DeleteFollowUpUpload $deleteFollowUpUpload, DeleteIntakeUpload $deleteIntakeUpload): int
    {
        $minutes = max(1, (int) config('intake.follow_up.removed_upload_purge_minutes', 10));
        $cutoff = now()->subMinutes($minutes);
        $followUpPurged = $deleteFollowUpUpload->purgeRemovedBefore($cutoff);
        $wizardPurged = $deleteIntakeUpload->purgeRemovedBefore($cutoff);

        $this->info("Purged {$followUpPurged} removed follow-up upload(s).");
        $this->info("Purged {$wizardPurged} removed wizard upload(s).");

        return self::SUCCESS;
    }
}
