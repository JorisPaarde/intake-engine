<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores SHA-256 hashes of superseded customer access tokens so a replaced
 * link can return a friendly 410 instead of a bare 404. No plaintext tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_replaced_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_id')->constrained('intakes')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->timestamp('replaced_at');

            $table->unique('token_hash');
            $table->index(['intake_id', 'replaced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_replaced_access_tokens');
    }
};
