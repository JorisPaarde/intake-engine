<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-142: confidence 0–100 + evidence + bron op intake_answers (prefill-feiten).
 * Hervatbaar per kolom (MySQL DDL kan eerder gecommit zijn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_answers', function (Blueprint $table): void {
            if (! Schema::hasColumn('intake_answers', 'fact_confidence')) {
                $table->unsignedTinyInteger('fact_confidence')->nullable()->after('fact_provenance');
            }
            if (! Schema::hasColumn('intake_answers', 'fact_evidence')) {
                $table->text('fact_evidence')->nullable()->after('fact_confidence');
            }
            if (! Schema::hasColumn('intake_answers', 'fact_source')) {
                $table->string('fact_source')->nullable()->after('fact_evidence');
            }
        });
    }

    public function down(): void
    {
        Schema::table('intake_answers', function (Blueprint $table): void {
            if (Schema::hasColumn('intake_answers', 'fact_source')) {
                $table->dropColumn('fact_source');
            }
            if (Schema::hasColumn('intake_answers', 'fact_evidence')) {
                $table->dropColumn('fact_evidence');
            }
            if (Schema::hasColumn('intake_answers', 'fact_confidence')) {
                $table->dropColumn('fact_confidence');
            }
        });
    }
};
