<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $table): void {
                $table->id();
                $table->uuid('trace_id')->unique();
                $table->uuid('correlation_id')->nullable()->index();
                $table->uuid('parent_trace_id')->nullable()->index();
                $table->foreignId('intake_id')->constrained('intakes')->cascadeOnDelete();
                $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
                $table->unsignedBigInteger('upload_id')->nullable()->index();
                $table->string('subject_type', 80)->nullable();
                $table->string('subject_id', 120)->nullable();
                $table->string('call_type', 40);
                $table->string('status', 20)->default('pending');
                $table->string('provider', 40)->nullable();
                $table->string('model', 120)->nullable();
                $table->json('model_parameters')->nullable();
                $table->string('prompt_version', 120)->nullable();
                $table->boolean('fallback_used')->default(false);
                $table->unsignedSmallInteger('retry_count')->default(0);
                $table->json('request_snapshot')->nullable();
                $table->json('photo_refs')->nullable();
                $table->mediumText('raw_response')->nullable();
                $table->string('finish_reason', 80)->nullable();
                $table->json('parsed_response')->nullable();
                $table->json('validation_errors')->nullable();
                $table->json('normalizations')->nullable();
                $table->json('field_outcomes')->nullable();
                $table->json('dossier_before')->nullable();
                $table->json('dossier_after')->nullable();
                $table->json('remaining_questions_before')->nullable();
                $table->json('remaining_questions_after')->nullable();
                $table->unsignedInteger('network_upload_ms')->nullable();
                $table->unsignedInteger('persist_ms')->nullable();
                $table->unsignedInteger('preprocess_ms')->nullable();
                $table->unsignedInteger('provider_ms')->nullable();
                $table->unsignedInteger('process_ms')->nullable();
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('total_tokens')->nullable();
                $table->unsignedInteger('estimated_cost_cents')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['intake_id', 'call_type']);
                $table->index(['intake_id', 'started_at']);
            });
        }

        if (! Schema::hasTable('ai_trace_steps')) {
            Schema::create('ai_trace_steps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ai_trace_id')->constrained('ai_traces')->cascadeOnDelete();
                $table->string('step_key', 80);
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->json('payload')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->index(['ai_trace_id', 'sequence']);
                $table->index(['ai_trace_id', 'step_key']);
            });
        }

        if (! Schema::hasColumn('intake_uploads', 'processing_timings')) {
            Schema::table('intake_uploads', function (Blueprint $table): void {
                $table->json('processing_timings')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_trace_steps');
        Schema::dropIfExists('ai_traces');

        if (Schema::hasColumn('intake_uploads', 'processing_timings')) {
            Schema::table('intake_uploads', function (Blueprint $table): void {
                $table->dropColumn('processing_timings');
            });
        }
    }
};
