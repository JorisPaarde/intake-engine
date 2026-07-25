<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            Schema::create('companies', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('slug')->unique();
                $table->string('name');
                $table->string('logo_disk')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('logo_original_filename')->nullable();
                $table->string('logo_mime_type')->nullable();
                $table->unsignedBigInteger('logo_size_bytes')->nullable();
                $table->string('primary_color', 7)->default('#0071E3');
                $table->string('accent_color', 7)->default('#005EC0');
                $table->string('on_primary_color', 7)->default('#FFFFFF');
                $table->timestamps();
            });

            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->restrictOnDelete();
            });

            Schema::table('intakes', function (Blueprint $table): void {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('intake_template_version_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->index(['company_id', 'status', 'created_at']);
            });

            $now = now();

            if (DB::table('users')->exists()) {
                $legacyCompanyId = DB::table('companies')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'slug' => 'bestaand-installatiebedrijf',
                    'name' => 'Installatiebedrijf',
                    'primary_color' => '#0071E3',
                    'accent_color' => '#005EC0',
                    'on_primary_color' => '#FFFFFF',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('users')->update([
                    'company_id' => $legacyCompanyId,
                    'updated_at' => $now,
                ]);

                DB::table('intakes')->update([
                    'company_id' => $legacyCompanyId,
                    'updated_at' => $now,
                ]);
            }

            if (DB::table('users')->whereNull('company_id')->exists()
                || DB::table('intakes')->whereNull('company_id')->exists()) {
                throw new RuntimeException('Company backfill is incomplete; refusing to enforce NOT NULL.');
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable(false)->change();
            });

            Schema::table('intakes', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable(false)->change();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            Schema::table('intakes', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
            });

            Schema::table('intakes', function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'status', 'created_at']);
                $table->dropColumn('company_id');
            });

            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('company_id');
            });

            Schema::dropIfExists('companies');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
