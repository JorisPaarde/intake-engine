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
 * Convert legacy pending rows to a terminal status that never triggers AI.
 * Never creates pending. Never touches assessment_status=NULL (stays NULL).
 * Never rewrites content_assessment JSON. Idempotent; down() leaves data alone.
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

        // Only pending rows — NULL assessment_status is left alone (72 legacy NULLs on prod).
        DB::table('intake_uploads')
            ->where('assessment_status', 'pending')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($closedStatuses, $hasAiRunsUploadId): void {
                foreach ($rows as $row) {
                    $intake = DB::table('intakes')->where('id', $row->intake_id)->first();
                    $intakeStatus = is_object($intake) ? (string) ($intake->status ?? '') : '';
                    $intakeMissingOrPurged = $intake === null;
                    $intakeClosed = in_array($intakeStatus, $closedStatuses, true);

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

                    $attempts = (int) ($row->assessment_attempts ?? 0);

                    // Closed intakes: seal any pending regardless of attempts.
                    // Otherwise only never-dispatched (attempts=0) legacy/backfill rows.
                    $shouldConvert = $intakeMissingOrPurged
                        || $intakeClosed
                        || (
                            $attempts === 0
                            && ($createdBeforeFeature || $looksLikeBackfill || ! $hasAiRun)
                        );

                    if (! $shouldConvert) {
                        continue;
                    }

                    // Preserve existing content_assessment: assessed if present & definitive,
                    // otherwise not_assessed. Never write pending. Never touch content_assessment.
                    $terminal = 'not_assessed';
                    if ($row->content_assessment !== null && $row->content_assessment !== '') {
                        $decoded = is_string($row->content_assessment)
                            ? json_decode($row->content_assessment, true)
                            : $row->content_assessment;
                        $contentStatus = is_array($decoded) ? ($decoded['status'] ?? null) : null;
                        if (is_string($contentStatus) && $contentStatus !== 'not_assessed') {
                            $terminal = 'assessed';
                        }
                    }

                    DB::table('intake_uploads')
                        ->where('id', $row->id)
                        ->where('assessment_status', 'pending')
                        ->update([
                            'assessment_status' => $terminal,
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
