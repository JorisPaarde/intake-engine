<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-129: finer provider cost, OpenRouter response id, queue wait/attempt on ai_traces.
 * Resumable per-column (MySQL DDL may commit before a later step fails).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_traces')) {
            return;
        }

        Schema::table('ai_traces', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_traces', 'provider_response_id')) {
                $table->string('provider_response_id', 120)->nullable()->after('request_id');
            }
        });

        if (Schema::hasColumn('ai_traces', 'provider_response_id')
            && ! $this->hasIndex('ai_traces', 'ai_traces_provider_response_id_index')) {
            Schema::table('ai_traces', function (Blueprint $table): void {
                $table->index('provider_response_id');
            });
        }

        Schema::table('ai_traces', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_traces', 'queue_wait_ms')) {
                $table->unsignedInteger('queue_wait_ms')->nullable()->after('network_upload_ms');
            }
            if (! Schema::hasColumn('ai_traces', 'attempt')) {
                $table->unsignedSmallInteger('attempt')->nullable()->after('retry_count');
            }
            if (! Schema::hasColumn('ai_traces', 'estimated_cost')) {
                $table->decimal('estimated_cost', 20, 12)->nullable()->after('estimated_cost_cents');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_traces')) {
            return;
        }

        Schema::table('ai_traces', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_traces', 'estimated_cost')) {
                $table->dropColumn('estimated_cost');
            }
            if (Schema::hasColumn('ai_traces', 'attempt')) {
                $table->dropColumn('attempt');
            }
            if (Schema::hasColumn('ai_traces', 'queue_wait_ms')) {
                $table->dropColumn('queue_wait_ms');
            }
            if (Schema::hasColumn('ai_traces', 'provider_response_id')) {
                if ($this->hasIndex('ai_traces', 'ai_traces_provider_response_id_index')) {
                    $table->dropIndex('ai_traces_provider_response_id_index');
                }
                $table->dropColumn('provider_response_id');
            }
        });
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
