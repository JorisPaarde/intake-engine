<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hotfix (BL-134): the BL-127 backfill marked historical uploads as
 * assessment_status=pending (+ assessment_queued_at=now), which the watchdog
 * then re-dispatched to AssessUploadedPhotoJob — including submitted intakes.
 *
 * Convert legacy pending rows (attempts=0, never pipeline-dispatched) to a
 * terminal status that never triggers AI. Idempotent; down() leaves data alone.
 *
 * Criteria (any match under pending + attempts=0):
 * - no linked ai_run for this upload, OR
 * - intake already submitted/closed/purged, OR
 * - upload created before the assessment_status feature deploy, OR
 * - backfill signature (created_at << assessment_queued_at)
 */
return new class extends Migration
{
    /**
     * Feature deploy cutoff: uploads created before the assessment_status column
     * existed (migration 2026_10_03_200000) are treated as pre-feature legacy.
     */
    private const FEATURE_CUTOFF = '2026-10-03 12:00:00';

    public function up(): void
    {
        if (! Schema::hasColumn('intake_uploads', 'assessment_status')) {
            return;
        }

        $closedStatuses = ['completed', 'reviewed', 'cancelled'];
        $hasAiRunsUploadId = Schema::hasColumn('ai_runs', 'upload_id');

        DB::table('intake_uploads')
            ->where('assessment_status', 'pending')
            ->where('assessment_attempts', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($closedStatuses, $hasAiRunsUploadId): void {
                foreach ($rows as $row) {
                    $intake = DB::table('intakes')->where('id', $row->intake_id)->first();
                    $intakeStatus = is_object($intake) ? (string) ($intake->status ?? '') : '';
                    $intakeMissingOrPurged = $intake === null;

                    $hasAiRun = false;
                    if ($hasAiRunsUploadId) {
                        $hasAiRun = DB::table('ai_runs')
                            ->where('upload_id', $row->id)
                            ->exists();
                    }

                    $createdBeforeFeature = $row->created_at !== null
                        && (string) $row->created_at < self::FEATURE_CUTOFF;

                    // Backfill stamped assessment_queued_at=now() on old uploads.
                    $looksLikeBackfill = $row->assessment_queued_at !== null
                        && $row->created_at !== null
                        && strtotime((string) $row->created_at) < (strtotime((string) $row->assessment_queued_at) - 3600);

                    $shouldConvert = $intakeMissingOrPurged
                        || in_array($intakeStatus, $closedStatuses, true)
                        || $createdBeforeFeature
                        || $looksLikeBackfill
                        || ! $hasAiRun;

                    if (! $shouldConvert) {
                        continue;
                    }

                    DB::table('intake_uploads')
                        ->where('id', $row->id)
                        ->where('assessment_status', 'pending')
                        ->where('assessment_attempts', 0)
                        ->update([
                            'assessment_status' => 'not_assessed',
                            'assessment_queued_at' => null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data migration — leave converted rows terminal.
    }
};
