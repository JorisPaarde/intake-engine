<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * N80: system route points list every open route ("… (route #12); …"), which
 * exceeds VARCHAR(255) on MySQL for multi-room intakes and turned normal
 * installer saves into HTTP 500s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_attention_points', function (Blueprint $table) {
            $table->text('label')->change();
        });
    }

    public function down(): void
    {
        Schema::table('intake_attention_points', function (Blueprint $table) {
            $table->string('label')->change();
        });
    }
};
