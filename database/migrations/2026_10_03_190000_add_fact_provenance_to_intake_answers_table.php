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
            if (! Schema::hasColumn('intake_answers', 'fact_provenance')) {
                // BL-125: stated | inferred | unknown — letterlijk gezegd vs. AI-aanname.
                $table->string('fact_provenance')->nullable()->after('prefill_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('intake_answers', function (Blueprint $table): void {
            if (Schema::hasColumn('intake_answers', 'fact_provenance')) {
                $table->dropColumn('fact_provenance');
            }
        });
    }
};
