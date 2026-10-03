<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fractional AI budget storage: 1 cent = 10_000 microcents (same precision as decimal(10,4) cents).
 * Keeps estimated_cost_cents as a whole-cent ceiling for display/compat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_runs')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_runs', 'estimated_cost_microcents')) {
                $table->unsignedBigInteger('estimated_cost_microcents')->nullable()->after('estimated_cost_cents');
            }
        });

        if (Schema::hasColumn('ai_runs', 'estimated_cost_microcents')
            && Schema::hasColumn('ai_runs', 'estimated_cost_cents')) {
            DB::table('ai_runs')
                ->whereNotNull('estimated_cost_cents')
                ->whereNull('estimated_cost_microcents')
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('ai_runs')
                            ->where('id', $row->id)
                            ->update([
                                'estimated_cost_microcents' => ((int) $row->estimated_cost_cents) * 10_000,
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_runs') || ! Schema::hasColumn('ai_runs', 'estimated_cost_microcents')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropColumn('estimated_cost_microcents');
        });
    }
};
