<?php

declare(strict_types=1);

use App\Domains\Intake\Support\RoomLabelResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-116 review ronde 3: use_type_source + conservatieve name_source-backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('airco_rooms')) {
            return;
        }

        if (! Schema::hasColumn('airco_rooms', 'use_type_source')) {
            Schema::table('airco_rooms', function (Blueprint $table): void {
                $table->string('use_type_source', 32)->nullable()->after('use_type');
            });
        }

        if (Schema::hasColumn('airco_rooms', 'use_type_source')) {
            DB::table('airco_rooms')
                ->where('source_type', 'installer')
                ->whereNotNull('use_type')
                ->whereNull('use_type_source')
                ->update(['use_type_source' => 'installer']);
        }

        // Niet-placeholder namen zonder name_source → installer (conservatief: placeholders blijven null).
        if (Schema::hasColumn('airco_rooms', 'name_source')) {
            $rows = DB::table('airco_rooms')
                ->whereNull('name_source')
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->get(['id', 'name']);

            foreach ($rows as $row) {
                $name = is_string($row->name) ? trim($row->name) : '';
                if ($name === '' || RoomLabelResolver::isGeneratedPlaceholder($name)) {
                    continue;
                }

                DB::table('airco_rooms')
                    ->where('id', $row->id)
                    ->whereNull('name_source')
                    ->update(['name_source' => 'installer']);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('airco_rooms') && Schema::hasColumn('airco_rooms', 'use_type_source')) {
            Schema::table('airco_rooms', function (Blueprint $table): void {
                $table->dropColumn('use_type_source');
            });
        }
    }
};
