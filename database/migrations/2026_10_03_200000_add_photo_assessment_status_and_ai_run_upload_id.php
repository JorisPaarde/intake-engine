<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_uploads', 'assessment_status')) {
            Schema::table('intake_uploads', function (Blueprint $table): void {
                $table->string('assessment_status', 32)->nullable()->after('content_assessment');
                $table->unsignedBigInteger('assessment_source_upload_id')->nullable()->after('assessment_status');
                $table->unsignedTinyInteger('assessment_attempts')->default(0)->after('assessment_source_upload_id');
                $table->timestamp('assessment_queued_at')->nullable()->after('assessment_attempts');

                $table->index(['assessment_status', 'assessment_queued_at'], 'intake_uploads_assessment_pending_idx');
                $table->foreign('assessment_source_upload_id', 'intake_uploads_assessment_source_fk')
                    ->references('id')
                    ->on('intake_uploads')
                    ->nullOnDelete();
            });
        }

        // Backfill: known content_assessment → assessed/not_assessed; usability reject → heuristic_rejected.
        if (Schema::hasColumn('intake_uploads', 'assessment_status')) {
            DB::table('intake_uploads')
                ->whereNull('assessment_status')
                ->whereNotNull('content_assessment')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $raw = $row->content_assessment;
                        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                        $status = is_array($decoded) && ($decoded['status'] ?? null) === 'not_assessed'
                            ? 'not_assessed'
                            : 'assessed';

                        DB::table('intake_uploads')
                            ->where('id', $row->id)
                            ->update(['assessment_status' => $status]);
                    }
                });

            DB::table('intake_uploads')
                ->whereNull('assessment_status')
                ->whereIn('usability_verdict', ['too_small', 'too_dark'])
                ->update(['assessment_status' => 'heuristic_rejected']);

            DB::table('intake_uploads')
                ->whereNull('assessment_status')
                ->whereNotNull('usability_verdict')
                ->where('usability_verdict', 'ok')
                ->whereNull('content_assessment')
                ->where(function ($query): void {
                    $query->whereNull('question_key')
                        ->orWhere('question_key', 'installer_evidence')
                        ->orWhere('question_key', 'like', 'follow_up_%');
                })
                ->update(['assessment_status' => 'pending', 'assessment_queued_at' => now()]);

            // Wizard photos with usability but no content_assessment are stuck pending (watchdog).
            DB::table('intake_uploads')
                ->whereNull('assessment_status')
                ->where('usability_verdict', 'ok')
                ->whereNull('content_assessment')
                ->where('question_key', '!=', 'installer_evidence')
                ->update(['assessment_status' => 'pending', 'assessment_queued_at' => now()]);

            // Usability done, no AI needed historically → assessed.
            DB::table('intake_uploads')
                ->whereNull('assessment_status')
                ->whereNotNull('usability_verdict')
                ->update(['assessment_status' => 'assessed']);
        }

        if (! Schema::hasColumn('ai_runs', 'upload_id')) {
            Schema::table('ai_runs', function (Blueprint $table): void {
                $table->unsignedBigInteger('upload_id')->nullable()->after('intake_id');
                $table->index('upload_id', 'ai_runs_upload_id_idx');
                $table->foreign('upload_id', 'ai_runs_upload_id_fk')
                    ->references('id')
                    ->on('intake_uploads')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_runs', 'upload_id')) {
            Schema::table('ai_runs', function (Blueprint $table): void {
                $table->dropForeign('ai_runs_upload_id_fk');
                $table->dropIndex('ai_runs_upload_id_idx');
                $table->dropColumn('upload_id');
            });
        }

        if (Schema::hasColumn('intake_uploads', 'assessment_status')) {
            Schema::table('intake_uploads', function (Blueprint $table): void {
                $table->dropForeign('intake_uploads_assessment_source_fk');
                $table->dropIndex('intake_uploads_assessment_pending_idx');
                $table->dropColumn([
                    'assessment_status',
                    'assessment_source_upload_id',
                    'assessment_attempts',
                    'assessment_queued_at',
                ]);
            });
        }
    }
};
