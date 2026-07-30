<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_interests', function (Blueprint $table): void {
            $table->id();
            $table->string('company_name', 120);
            $table->string('contact_name', 120);
            $table->string('email', 254);
            $table->string('phone', 40)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('notification_queued_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_interests');
    }
};
