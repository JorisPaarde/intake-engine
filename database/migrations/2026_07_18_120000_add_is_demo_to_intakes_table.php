<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intakes', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('progress_percent');
            $table->index(['is_demo', 'token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table): void {
            $table->dropIndex(['is_demo', 'token_expires_at']);
            $table->dropColumn('is_demo');
        });
    }
};
