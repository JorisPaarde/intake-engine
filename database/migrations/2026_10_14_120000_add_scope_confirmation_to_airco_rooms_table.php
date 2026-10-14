<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * N68: de installateur kan per aangevraagde ruimte bevestigen dat de bestaande
 * installatie blijft (buiten scope van het gekozen voorstel). Gestructureerd, met audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('airco_rooms', 'scope_status')) {
            Schema::table('airco_rooms', function (Blueprint $table): void {
                $table->string('scope_status', 40)->nullable();
            });
        }
        if (! Schema::hasColumn('airco_rooms', 'scope_confirmed_by')) {
            Schema::table('airco_rooms', function (Blueprint $table): void {
                $table->unsignedBigInteger('scope_confirmed_by')->nullable();
            });
        }
        if (! Schema::hasColumn('airco_rooms', 'scope_confirmed_at')) {
            Schema::table('airco_rooms', function (Blueprint $table): void {
                $table->timestamp('scope_confirmed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['scope_confirmed_at', 'scope_confirmed_by', 'scope_status'] as $column) {
            if (Schema::hasColumn('airco_rooms', $column)) {
                Schema::table('airco_rooms', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
