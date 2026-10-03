<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('intake_uploads', 'content_assessment')) {
            return;
        }

        Schema::table('intake_uploads', function (Blueprint $table): void {
            $table->json('content_assessment')->nullable()->after('usability_verdict');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('intake_uploads', 'content_assessment')) {
            return;
        }

        Schema::table('intake_uploads', function (Blueprint $table): void {
            $table->dropColumn('content_assessment');
        });
    }
};
