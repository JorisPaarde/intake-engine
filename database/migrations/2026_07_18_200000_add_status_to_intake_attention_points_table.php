<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_attention_points', function (Blueprint $table): void {
            // BL-007: lifecycle of an AI-proposed point (proposed/accepted/dismissed).
            // Null for system/reviewer points, which stay authoritative.
            $table->string('status')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('intake_attention_points', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
