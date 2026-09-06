<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airco_installation_options', function (Blueprint $table): void {
            if (! Schema::hasColumn('airco_installation_options', 'feasibility')) {
                $table->string('feasibility')->default('pending')->after('status');
            }
            if (! Schema::hasColumn('airco_installation_options', 'infeasibility_reason')) {
                $table->text('infeasibility_reason')->nullable()->after('feasibility');
            }
        });
    }

    public function down(): void
    {
        Schema::table('airco_installation_options', function (Blueprint $table): void {
            if (Schema::hasColumn('airco_installation_options', 'infeasibility_reason')) {
                $table->dropColumn('infeasibility_reason');
            }
            if (Schema::hasColumn('airco_installation_options', 'feasibility')) {
                $table->dropColumn('feasibility');
            }
        });
    }
};
