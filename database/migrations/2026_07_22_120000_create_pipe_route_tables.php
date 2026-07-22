<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipe_route_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            // Overkoepelende zekerheid van de synthese (0..1); null zolang niet samengevat.
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('proposed_route')->nullable();
            $table->json('alternative_route')->nullable();
            $table->json('uncertainties')->nullable();
            $table->json('missing_checks')->nullable();
            $table->text('next_photo_instruction')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['intake_id', 'status']);
        });

        Schema::create('pipe_route_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipe_route_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intake_upload_id')->nullable()->constrained('intake_uploads')->nullOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            // Vrije rol van dit segment binnen de route (bv. binnenwand, gevel, obstakel).
            $table->string('label')->nullable();
            $table->boolean('photo_usable')->nullable();
            $table->boolean('route_possible')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            // Volledige gestructureerde analyse-JSON van dit segment.
            $table->json('analysis')->nullable();
            $table->timestamps();

            $table->index(['pipe_route_session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipe_route_segments');
        Schema::dropIfExists('pipe_route_sessions');
    }
};
