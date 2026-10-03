<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-116 review: markeer of een kamernaam door de installateur is gezet.
 * syncRooms overschrijft name_source=installer nooit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('airco_rooms')) {
            return;
        }

        if (! Schema::hasColumn('airco_rooms', 'name_source')) {
            Schema::table('airco_rooms', function (Blueprint $table): void {
                $table->string('name_source', 32)->nullable()->after('name');
            });
        }

        // Manual/installer rooms keep their name forever.
        if (Schema::hasColumn('airco_rooms', 'name_source')) {
            DB::table('airco_rooms')
                ->where('source_type', 'installer')
                ->whereNull('name_source')
                ->update(['name_source' => 'installer']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('airco_rooms') && Schema::hasColumn('airco_rooms', 'name_source')) {
            Schema::table('airco_rooms', function (Blueprint $table): void {
                $table->dropColumn('name_source');
            });
        }
    }
};
