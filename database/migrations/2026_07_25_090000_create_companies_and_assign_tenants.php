<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
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

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'email']) as $user) {
            $baseSlug = Str::slug((string) ($user->name ?: Str::before((string) $user->email, '@')));
            $slug = $this->uniqueSlug($baseSlug !== '' ? $baseSlug : 'bedrijf', (int) $user->id);

            $companyId = DB::table('companies')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'slug' => $slug,
                'name' => (string) ($user->name ?: 'Installatiebedrijf'),
                'primary_color' => '#0071E3',
                'accent_color' => '#005EC0',
                'on_primary_color' => '#FFFFFF',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')
                ->where('id', $user->id)
                ->update(['company_id' => $companyId, 'updated_at' => $now]);
        }

        foreach (DB::table('intakes')->orderBy('id')->get(['id', 'created_by']) as $intake) {
            $companyId = DB::table('users')
                ->where('id', $intake->created_by)
                ->value('company_id');

            DB::table('intakes')
                ->where('id', $intake->id)
                ->update(['company_id' => $companyId, 'updated_at' => $now]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable(false)->change();
        });

        Schema::table('intakes', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status', 'created_at']);
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::dropIfExists('companies');
    }

    private function uniqueSlug(string $baseSlug, int $userId): string
    {
        $slug = Str::limit($baseSlug, 72, '').'-'.$userId;

        return Str::limit($slug, 96, '');
    }
};
