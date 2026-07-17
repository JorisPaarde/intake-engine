<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('intake_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status');
            $table->timestamp('published_at')->nullable();
            $table->text('change_notes')->nullable();
            $table->timestamps();

            $table->unique(['intake_template_id', 'version']);
            $table->index(['intake_template_id', 'status']);
        });

        Schema::create('intake_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_template_version_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_repeatable')->default(false);
            $table->string('repeat_count_question_key')->nullable();
            $table->timestamps();

            $table->unique(['intake_template_version_id', 'key']);
        });

        Schema::create('intake_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_section_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('type');
            $table->string('label');
            $table->text('help_text')->nullable();
            $table->text('photo_instructions')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('validation_rules')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['intake_section_id', 'key']);
            $table->index(['intake_section_id', 'sort_order']);
        });

        Schema::create('intake_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_question_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['intake_question_id', 'value']);
        });

        Schema::create('intake_question_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_question_id')->constrained()->cascadeOnDelete();
            $table->string('source_question_key');
            $table->string('operator');
            $table->json('value')->nullable();
            $table->string('effect');
            $table->timestamps();

            $table->index('intake_question_id');
        });

        Schema::create('intakes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('intake_template_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->string('address_line');
            $table->string('address_postal_code')->nullable();
            $table->string('address_city')->nullable();
            $table->string('access_token', 64)->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_revoked_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('current_section_key')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('completeness_snapshot')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_by');
            $table->index('customer_email');
            $table->index(['status', 'created_at']);
        });

        Schema::create('intake_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->string('question_key');
            $table->string('section_instance_key')->nullable();
            $table->json('value');
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['intake_id', 'question_key', 'section_instance_key'], 'intake_answers_unique_answer');
            $table->index('intake_id');
        });

        Schema::create('intake_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->string('question_key');
            $table->string('section_instance_key')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['intake_id', 'question_key']);
        });

        Schema::create('intake_attention_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('code')->nullable();
            $table->string('label');
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('intake_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('intake_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->boolean('site_visit_needed')->default(false);
            $table->boolean('enough_information')->default(false);
            $table->text('summary')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('generated_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete()->unique();
            $table->longText('html');
            $table->json('meta')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('intake_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['intake_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_activity_events');
        Schema::dropIfExists('generated_reports');
        Schema::dropIfExists('intake_reviews');
        Schema::dropIfExists('intake_notes');
        Schema::dropIfExists('intake_attention_points');
        Schema::dropIfExists('intake_uploads');
        Schema::dropIfExists('intake_answers');
        Schema::dropIfExists('intakes');
        Schema::dropIfExists('intake_question_rules');
        Schema::dropIfExists('intake_question_options');
        Schema::dropIfExists('intake_questions');
        Schema::dropIfExists('intake_sections');
        Schema::dropIfExists('intake_template_versions');
        Schema::dropIfExists('intake_templates');
    }
};
