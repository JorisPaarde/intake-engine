<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-138: provider_request_id on ai_runs + queued_at on ai_traces.
 * Resumable per-column (MySQL DDL may commit before a later step fails).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_runs') && ! Schema::hasColumn('ai_runs', 'provider_request_id')) {
            Schema::table('ai_runs', function (Blueprint $table): void {
                $table->string('provider_request_id', 120)->nullable()->after('prompt_version');
            });
        }

        if (Schema::hasTable('ai_runs')
            && Schema::hasColumn('ai_runs', 'provider_request_id')
            && ! $this->hasIndex('ai_runs', 'ai_runs_provider_request_id_index')) {
            Schema::table('ai_runs', function (Blueprint $table): void {
                $table->index('provider_request_id');
            });
        }

        if (Schema::hasTable('ai_traces') && ! Schema::hasColumn('ai_traces', 'queued_at')) {
            Schema::table('ai_traces', function (Blueprint $table): void {
                $table->timestamp('queued_at')->nullable()->after('queue_wait_ms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_runs') && Schema::hasColumn('ai_runs', 'provider_request_id')) {
            Schema::table('ai_runs', function (Blueprint $table): void {
                if ($this->hasIndex('ai_runs', 'ai_runs_provider_request_id_index')) {
                    $table->dropIndex('ai_runs_provider_request_id_index');
                }
                $table->dropColumn('provider_request_id');
            });
        }

        if (Schema::hasTable('ai_traces') && Schema::hasColumn('ai_traces', 'queued_at')) {
            Schema::table('ai_traces', function (Blueprint $table): void {
                $table->dropColumn('queued_at');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
