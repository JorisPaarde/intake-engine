<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table): void {
                if (! Schema::hasColumn('companies', 'embed_public_key')) {
                    $table->string('embed_public_key', 64)->nullable()->unique()->after('on_primary_color');
                }

                if (! Schema::hasColumn('companies', 'embed_allowed_origins')) {
                    $table->json('embed_allowed_origins')->nullable()->after('embed_public_key');
                }

                if (! Schema::hasColumn('companies', 'embed_enabled')) {
                    $table->boolean('embed_enabled')->default(false)->after('embed_allowed_origins');
                }

                if (! Schema::hasColumn('companies', 'embed_daily_limit')) {
                    $table->unsignedSmallInteger('embed_daily_limit')->default(20)->after('embed_enabled');
                }
            });
        }

        if (Schema::hasTable('intakes') && ! Schema::hasColumn('intakes', 'source')) {
            Schema::table('intakes', function (Blueprint $table): void {
                $table->string('source', 32)->default('installer')->after('created_by');
                // Geen company_id in deze index: latere rollback van de companies-migratie
                // moet company_id kunnen droppen zonder gestrande indexes van nieuwere migraties.
                $table->index(['source', 'created_at']);
            });

            DB::table('intakes')->whereNull('source')->orWhere('source', '')->update([
                'source' => 'installer',
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('intakes') && Schema::hasColumn('intakes', 'source')) {
            Schema::table('intakes', function (Blueprint $table): void {
                $table->dropIndex(['source', 'created_at']);
                $table->dropColumn('source');
            });
        }

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('companies', 'embed_public_key') ? 'embed_public_key' : null,
                    Schema::hasColumn('companies', 'embed_allowed_origins') ? 'embed_allowed_origins' : null,
                    Schema::hasColumn('companies', 'embed_enabled') ? 'embed_enabled' : null,
                    Schema::hasColumn('companies', 'embed_daily_limit') ? 'embed_daily_limit' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
