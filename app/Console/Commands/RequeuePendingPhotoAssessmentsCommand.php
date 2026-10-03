<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Services\PhotoAssessmentLifecycle;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\PhotoAssessmentStatus;
use Illuminate\Console\Command;

/**
 * Herdispatched vastgelopen foto-assessments (pending langer dan ~3 min).
 * Idempotent; stopt na max assessment_attempts.
 */
final class RequeuePendingPhotoAssessmentsCommand extends Command
{
    protected $signature = 'photos:requeue-pending-assessments
                            {--minutes= : Override pending age in minutes}
                            {--max-attempts= : Override max attempts}';

    protected $description = 'Herqueue foto-uploads die te lang op assessment_status=pending staan';

    public function handle(PhotoAssessmentLifecycle $lifecycle): int
    {
        $minutes = $this->option('minutes');
        $maxAttemptsOption = $this->option('max-attempts');

        $afterSeconds = is_numeric($minutes)
            ? max(60, (int) $minutes * 60)
            : max(60, (int) config('ai.photo_assessment.watchdog_after_seconds', 180));

        $maxAttempts = is_numeric($maxAttemptsOption)
            ? max(1, (int) $maxAttemptsOption)
            : max(1, (int) config('ai.photo_assessment.watchdog_max_attempts', 3));

        $cutoff = now()->subSeconds($afterSeconds);
        $redispatched = 0;
        $exhausted = 0;

        IntakeUpload::query()
            ->where('assessment_status', PhotoAssessmentStatus::Pending)
            ->where(function ($query) use ($cutoff): void {
                $query->where('assessment_queued_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('assessment_queued_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->where('assessment_attempts', '<', $maxAttempts)
            ->where('question_key', '!=', 'installer_evidence')
            ->orderBy('id')
            ->chunkById(100, function ($uploads) use ($lifecycle, $maxAttempts, &$redispatched, &$exhausted): void {
                foreach ($uploads as $upload) {
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

        // Safety net: still-pending rows past absolute age after max cycles.
        IntakeUpload::query()
            ->where('assessment_status', PhotoAssessmentStatus::Pending)
            ->where('assessment_attempts', '>=', $maxAttempts)
            ->where(function ($query) use ($cutoff): void {
                $query->where('assessment_queued_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('assessment_queued_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->where('question_key', '!=', 'installer_evidence')
            ->orderBy('id')
            ->chunkById(100, function ($uploads) use ($lifecycle, &$exhausted): void {
                foreach ($uploads as $upload) {
                    $lifecycle->ensureTerminal($upload);
                    $exhausted++;
                }
            });

        $this->info("Redispatched {$redispatched} pending photo assessment(s); marked {$exhausted} as terminal after max attempts.");

        return self::SUCCESS;
    }
}
