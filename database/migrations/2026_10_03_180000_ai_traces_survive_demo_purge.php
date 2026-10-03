<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI-traces must survive demo intake hard-delete. intake_id becomes nullable
 * (nullOnDelete); denormalised intake_ref_id + is_demo keep the trace queryable
 * after purge. Retention is governed only by ai:purge-traces.
 *
 * ai_runs stay cascadeOnDelete: they are operational/idempotent apply-records
 * that are meaningless without the intake; durable diagnostics live in ai_traces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_traces')) {
            return;
        }

        Schema::table('ai_traces', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_traces', 'intake_ref_id')) {
                $table->unsignedBigInteger('intake_ref_id')->nullable()->after('intake_id');
            }
            if (! Schema::hasColumn('ai_traces', 'is_demo')) {
                $table->boolean('is_demo')->default(false)->after('intake_ref_id');
            }
            if (! Schema::hasColumn('ai_traces', 'request_id')) {
                $table->string('request_id', 80)->nullable()->after('correlation_id');
            }
        });

        $this->backfillDenormalisedColumns();

        Schema::table('ai_traces', function (Blueprint $table): void {
            if (! $this->hasIndex('ai_traces', 'ai_traces_intake_ref_id_index')) {
                $table->index('intake_ref_id');
            }
            if (! $this->hasIndex('ai_traces', 'ai_traces_is_demo_index')) {
                $table->index('is_demo');
            }
            if (! $this->hasIndex('ai_traces', 'ai_traces_request_id_index')) {
                $table->index('request_id');
            }
            if (! $this->hasIndex('ai_traces', 'ai_traces_intake_ref_id_started_at_index')) {
                $table->index(['intake_ref_id', 'started_at']);
            }
        });

        $this->replaceIntakeForeignKey();
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_traces')) {
            return;
        }

        DB::table('ai_traces')->whereNull('intake_id')->delete();

        $this->dropIntakeForeignKey();

        Schema::table('ai_traces', function (Blueprint $table): void {
            $table->unsignedBigInteger('intake_id')->nullable(false)->change();
            $table->foreign('intake_id')->references('id')->on('intakes')->cascadeOnDelete();
        });

        Schema::table('ai_traces', function (Blueprint $table): void {
            foreach (['request_id', 'is_demo', 'intake_ref_id'] as $column) {
                if (Schema::hasColumn('ai_traces', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillDenormalisedColumns(): void
    {
        if (! Schema::hasColumn('ai_traces', 'intake_ref_id') || ! Schema::hasTable('intakes')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('
                UPDATE ai_traces
                SET intake_ref_id = COALESCE(intake_ref_id, intake_id),
                    is_demo = COALESCE(
                        (SELECT intakes.is_demo FROM intakes WHERE intakes.id = ai_traces.intake_id),
                        0
                    )
                WHERE intake_id IS NOT NULL
            ');

            return;
        }

        DB::statement('
            UPDATE ai_traces
            LEFT JOIN intakes ON intakes.id = ai_traces.intake_id
            SET ai_traces.intake_ref_id = COALESCE(ai_traces.intake_ref_id, ai_traces.intake_id),
                ai_traces.is_demo = COALESCE(intakes.is_demo, 0)
            WHERE ai_traces.intake_id IS NOT NULL
        ');
    }

    private function replaceIntakeForeignKey(): void
    {
        $this->dropIntakeForeignKey();

        Schema::table('ai_traces', function (Blueprint $table): void {
            $table->unsignedBigInteger('intake_id')->nullable()->change();
        });

        Schema::table('ai_traces', function (Blueprint $table): void {
            $table->foreign('intake_id')->references('id')->on('intakes')->nullOnDelete();
        });
    }

    private function dropIntakeForeignKey(): void
    {
        try {
            Schema::table('ai_traces', function (Blueprint $table): void {
                $table->dropForeign(['intake_id']);
            });
        } catch (Throwable) {
            try {
                Schema::table('ai_traces', function (Blueprint $table): void {
                    $table->dropForeign('ai_traces_intake_id_foreign');
                });
            } catch (Throwable) {
                // Already unconstrained (e.g. partial prior run).
            }
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                if (($index->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'select 1 from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [$database, $table, $indexName],
        );

        return $rows !== [];
    }
};
