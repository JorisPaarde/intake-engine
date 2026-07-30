<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductInterest;
use Illuminate\Console\Command;

final class PurgeProductInterestsCommand extends Command
{
    protected $signature = 'product-interests:purge';

    protected $description = 'Verwijder verlopen interesse-inzendingen van de publieke landingspagina';

    public function handle(): int
    {
        $purged = ProductInterest::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Purged {$purged} product interest submission(s).");

        return self::SUCCESS;
    }
}
