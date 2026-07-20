<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_external_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->string('fact_key');
            $table->string('label');
            $table->json('value');
            $table->string('source');
            $table->string('source_reference')->nullable();
            $table->text('source_url')->nullable();
            $table->string('confidence');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['intake_id', 'fact_key', 'source'], 'intake_external_facts_unique');
            $table->index(['intake_id', 'confidence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_external_facts');
    }
};
