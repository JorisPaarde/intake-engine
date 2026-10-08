<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-147 (UX #16.4): a follow-up photo removed by the customer stays soft-deleted
 * (“prullenbak”) until it is purged: media + evidence links wiped, activity logged.
 * purged_at marks that purge so the hourly cleanup never repeats it. Rows that were
 * already soft-deleted before this column existed were purged right away (old flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_uploads', 'purged_at')) {
            Schema::table('intake_uploads', function (Blueprint $table): void {
                $table->timestamp('purged_at')->nullable()->after('deleted_at');
            });
        }

        DB::table('intake_uploads')
            ->whereNotNull('deleted_at')
            ->whereNull('purged_at')
            ->update(['purged_at' => DB::raw('deleted_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('intake_uploads', 'purged_at')) {
            Schema::table('intake_uploads', function (Blueprint $table): void {
                $table->dropColumn('purged_at');
            });
        }
    }
};
