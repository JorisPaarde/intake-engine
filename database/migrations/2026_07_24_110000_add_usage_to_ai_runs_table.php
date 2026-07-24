<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedSmallInteger('image_count')->default(0);
            $table->unsignedInteger('estimated_cost_cents')->nullable();

            $table->index(['provider', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'status', 'started_at']);
            $table->dropColumn([
                'input_tokens',
                'output_tokens',
                'total_tokens',
                'image_count',
                'estimated_cost_cents',
            ]);
        });
    }
};
