<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Services\PhotoAssessmentLifecycle;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Herdispatched vastgelopen foto-assessments (pending langer dan ~3 min).
 * Alleen pipeline-dispatched uploads (assessment_attempts >= 1), binnen
 * max-age, op intakes in de klantfase. Cap per run. Idempotent.
 */
final class RequeuePendingPhotoAssessmentsCommand extends Command
{
    protected $signature = 'photos:requeue-pending-assessments
                            {--minutes= : Override pending age in minutes}
                            {--max-attempts= : Override max attempts}
                            {--max-age-hours= : Override max upload age in hours}
                            {--max-per-run= : Override per-run dispatch cap}';

    protected $description = 'Herqueue foto-uploads die te lang op assessment_status=pending staan';

    public function handle(PhotoAssessmentLifecycle $lifecycle): int
    {
        $minutes = $this->option('minutes');
        $maxAttemptsOption = $this->option('max-attempts');
        $maxAgeOption = $this->option('max-age-hours');
        $maxPerRunOption = $this->option('max-per-run');

        $afterSeconds = is_numeric($minutes)
            ? max(60, (int) $minutes * 60)
            : max(60, (int) config('ai.photo_assessment.watchdog_after_seconds', 180));

        $maxAttempts = is_numeric($maxAttemptsOption)
            ? max(1, (int) $maxAttemptsOption)
            : max(1, (int) config('ai.photo_assessment.watchdog_max_attempts', 3));

        $maxAgeHours = is_numeric($maxAgeOption)
            ? max(1, (int) $maxAgeOption)
            : max(1, (int) config('ai.photo_assessment.watchdog_max_age_hours', 24));

        $maxPerRun = is_numeric($maxPerRunOption)
            ? max(1, (int) $maxPerRunOption)
            : max(1, (int) config('ai.photo_assessment.watchdog_max_per_run', 20));

        $cutoff = now()->subSeconds($afterSeconds);
        $maxAgeCutoff = now()->subHours($maxAgeHours);
        $customerStatuses = array_map(
            static fn (IntakeStatus $status): string => $status->value,
            array_values(array_filter(
                IntakeStatus::cases(),
                static fn (IntakeStatus $status): bool => $status->isCustomerAccessible(),
            )),
        );

        $redispatched = 0;
        $exhausted = 0;
        $skippedLegacy = 0;
        $skippedAge = 0;
        $skippedIntake = 0;
        $skippedCap = 0;

        IntakeUpload::query()
            ->with('intake')
            ->where('assessment_status', PhotoAssessmentStatus::Pending)
            ->where(function ($query) use ($cutoff): void {
                $query->where('assessment_queued_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('assessment_queued_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->where('question_key', '!=', 'installer_evidence')
            ->orderBy('id')
            ->chunkById(100, function ($uploads) use (
                $lifecycle,
                $maxAttempts,
                $maxAgeCutoff,
                $customerStatuses,
                $maxPerRun,
                &$redispatched,
                &$exhausted,
                &$skippedLegacy,
                &$skippedAge,
                &$skippedIntake,
                &$skippedCap,
            ): void {
                foreach ($uploads as $upload) {
                    // (a) Only pipeline-dispatched uploads (lifecycle::dispatch sets attempts >= 1).
                    if ((int) $upload->assessment_attempts < 1) {
                        $skippedLegacy++;

                        continue;
                    }

                    // (b) Age window — never revive ancient / backfilled rows.
                    if ($upload->created_at !== null && $upload->created_at->lt($maxAgeCutoff)) {
                        $skippedAge++;

                        continue;
                    }

                    // (c) Customer phase only — never touch submitted/closed/purged uploads.
                    $intake = $upload->intake;
                    if ($intake === null || ! in_array($intake->status->value, $customerStatuses, true)) {
                        $skippedIntake++;

                        continue;
                    }

                    if ((int) $upload->assessment_attempts >= $maxAttempts) {
                        $lifecycle->ensureTerminal($upload);
                        $exhausted++;

                        continue;
                    }

                    if ($redispatched >= $maxPerRun) {
                        $skippedCap++;

                        continue;
                    }

                    $nextAttempts = (int) $upload->assessment_attempts + 1;
                    $upload->forceFill(['assessment_attempts' => $nextAttempts])->save();

                    if ($nextAttempts > $maxAttempts) {
                        $lifecycle->ensureTerminal($upload);
                        $exhausted++;

                        continue;
                    }

                    $lifecycle->dispatch($upload);
                    $redispatched++;
                }
            });

        $message = sprintf(
            'Redispatched %d pending photo assessment(s); marked %d terminal after max attempts; skipped legacy=%d age=%d intake=%d cap=%d.',
            $redispatched,
            $exhausted,
            $skippedLegacy,
            $skippedAge,
            $skippedIntake,
            $skippedCap,
        );

        $this->info($message);
        Log::info('photos:requeue-pending-assessments', [
            'redispatched' => $redispatched,
            'exhausted' => $exhausted,
            'skipped_legacy' => $skippedLegacy,
            'skipped_age' => $skippedAge,
            'skipped_intake' => $skippedIntake,
            'skipped_cap' => $skippedCap,
            'max_per_run' => $maxPerRun,
            'max_age_hours' => $maxAgeHours,
        ]);

        return self::SUCCESS;
    }
}
