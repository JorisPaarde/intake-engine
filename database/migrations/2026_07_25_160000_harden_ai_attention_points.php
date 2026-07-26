<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pre-v3 AI proposals have no validated confidence/evidence and must never
        // become authoritative. A later automatic analysis can recreate them safely.
        DB::table('intake_attention_points')
            ->where('source', 'ai')
            ->where('status', 'proposed')
            ->delete();

        $duplicates = DB::table('intake_attention_points')
            ->select(['intake_id', 'source', 'code'])
            ->whereNotNull('code')
            ->groupBy(['intake_id', 'source', 'code'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('intake_attention_points')
                ->where('intake_id', $duplicate->intake_id)
                ->where('source', $duplicate->source)
                ->where('code', $duplicate->code)
                ->orderByRaw("CASE WHEN status IN ('accepted', 'dismissed') THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->get(['id']);

            DB::table('intake_attention_points')
                ->whereIn('id', $rows->skip(1)->pluck('id'))
                ->delete();
        }

        Schema::table('intake_attention_points', function (Blueprint $table): void {
            $table->string('ai_confidence', 20)->nullable()->after('status');
            $table->json('evidence')->nullable()->after('ai_confidence');
            $table->unique(['intake_id', 'source', 'code'], 'intake_attention_unique');
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex('intake_attention_points_intake_id_foreign')) {
            Schema::table('intake_attention_points', function (Blueprint $table): void {
                // MySQL may use the composite unique index for the existing foreign key.
                // Restore its original supporting index before dropping that unique index.
                $table->index('intake_id', 'intake_attention_points_intake_id_foreign');
            });
        }

        Schema::table('intake_attention_points', function (Blueprint $table): void {
            $table->dropUnique('intake_attention_unique');
            $table->dropColumn(['ai_confidence', 'evidence']);
        });
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('intake_attention_points') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
