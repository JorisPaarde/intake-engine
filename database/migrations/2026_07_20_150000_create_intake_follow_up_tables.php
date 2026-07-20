<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_follow_up_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('round_number');
            $table->string('status');
            $table->timestamp('sent_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['intake_id', 'round_number']);
            $table->index(['intake_id', 'status']);
        });

        Schema::create('intake_follow_up_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_follow_up_round_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('prompt');
            $table->text('response_text')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['intake_follow_up_round_id', 'type']);
        });

        Schema::table('intake_uploads', function (Blueprint $table): void {
            $table->foreignId('intake_follow_up_item_id')
                ->nullable()
                ->after('section_instance_key')
                ->constrained('intake_follow_up_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('intake_uploads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('intake_follow_up_item_id');
        });

        Schema::dropIfExists('intake_follow_up_items');
        Schema::dropIfExists('intake_follow_up_rounds');
    }
};
