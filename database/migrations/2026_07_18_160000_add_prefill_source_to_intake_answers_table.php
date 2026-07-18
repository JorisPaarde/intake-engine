<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_answers', function (Blueprint $table): void {
            // BL-016: marks an answer the installer pre-filled at creation and the
            // applicant has not yet confirmed. Null once confirmed (or for normal answers).
            $table->string('prefill_source')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('intake_answers', function (Blueprint $table): void {
            $table->dropColumn('prefill_source');
        });
    }
};
