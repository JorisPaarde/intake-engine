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
            $table->string('current_question_key')->nullable()->after('current_section_key');
            $table->string('current_section_instance_key')->nullable()->after('current_question_key');
        });
    }

    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table): void {
            $table->dropColumn(['current_question_key', 'current_section_instance_key']);
        });
    }
};
