<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-147: optional company website for the customer thank-you button.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'website')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->string('website')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'website')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('website');
            });
        }
    }
};
