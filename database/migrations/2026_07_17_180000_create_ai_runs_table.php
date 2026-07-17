<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('prompt_version');
            $table->string('input_hash', 64);
            $table->json('output')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['intake_id', 'type']);
            $table->index(['intake_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
