<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\PhotoUsabilityHeuristic;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local, non-blocking photo-usability assessment (BL-007). Runs a deterministic GD
 * heuristic, records a `photo_quality` AiRun for audit, and stores the verdict on the
 * upload. Soft-fail: any error persists a non-blocking fallback verdict ({@see fallbackVerdict()})
 * so recovery never loops forever, and never breaks the customer flow.
 * Traced as photo_analysis (BL-116 / P2 timings).
 */
final class AssessPhotoUsability
{
    public function __construct(
        private readonly PhotoUsabilityHeuristic $heuristic,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTracePhotoRefBuilder $photoRefs,
    ) {}

    /**
     * Fallback when assessment or persistence fails. No dedicated "unknown" case exists;
     * Ok is usable → does not block the customer or progress.
     */
    public static function fallbackVerdict(): PhotoUsabilityVerdict
    {
        return PhotoUsabilityVerdict::Ok;
    }

    /**
     * Persist the soft-fail verdict without events. Never throws to the caller.
     */
    public static function persistFallbackVerdict(IntakeUpload $upload): PhotoUsabilityVerdict
    {
        $verdict = self::fallbackVerdict();

        try {
            $upload->updateQuietly(['usability_verdict' => $verdict]);
        } catch (\Throwable) {
            // Persistence failure must never block the customer.
        }

        return $verdict;
    }

    public function handle(IntakeUpload $upload, ?string $correlationId = null): PhotoUsabilityVerdict
    {
        $intake = Intake::query()->find($upload->intake_id);
        $run = AiRun::query()->create([
            'intake_id' => $upload->intake_id,
            'type' => AiRunType::PhotoQuality,
            'provider' => 'heuristic',
            'model' => 'photo-heuristic-v1',
            'prompt_version' => 'photo-heuristic-v1',
            'input_hash' => hash('sha256', 'upload:'.$upload->id),
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        $trace = $intake instanceof Intake
            ? $this->traceRecorder->start($intake, AiTraceCallType::PhotoAssess, array_filter([
                'ai_run_id' => $run->id,
                'upload_id' => $upload->id,
                'subject_type' => 'upload',
                'subject_id' => (string) $upload->id,
                'provider' => 'heuristic',
                'prompt_version' => 'photo-heuristic-v1',
                'correlation_id' => $correlationId,
            ], static fn (mixed $value): bool => $value !== null))
            : null;

        if ($trace !== null) {
            $trace->linkUpload($upload);
            $trace->linkAiRun($run);
        }

        $processStarted = microtime(true);

        try {
            $bytes = Storage::disk((string) $upload->disk)->get((string) $upload->path);

            if ($bytes === null) {
                throw new \RuntimeException('Uploadbestand niet gevonden voor beoordeling.');
            }

            $timings = is_array($upload->processing_timings) ? $upload->processing_timings : [];
            $originalWidth = $this->positiveIntOrNull($timings['original_width'] ?? null);
            $originalHeight = $this->positiveIntOrNull($timings['original_height'] ?? null);

            $verdict = $this->heuristic->assess($bytes, $originalWidth, $originalHeight);
            $processMs = (int) round((microtime(true) - $processStarted) * 1000);

            $upload->update(['usability_verdict' => $verdict]);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'output' => ['upload_id' => $upload->id, 'verdict' => $verdict->value],
                'finished_at' => now(),
            ]);

            if ($trace !== null) {
                $photoRefs = $trace->isNoop()
                    ? []
                    : [$this->photoRefs->fromUpload($upload, 'usability')];

                $trace->recordRequest(
                    systemAndUser: [
                        'system' => 'local photo usability heuristic',
                        'user' => ['upload_id' => $upload->id, 'path_ref' => 'intake_upload:'.$upload->id],
                    ],
                    photoRefs: $photoRefs,
                    promptVersion: 'photo-heuristic-v1',
                    modelParameters: ['engine' => 'gd'],
                );
                $trace->recordParsed(['verdict' => $verdict->value]);
                $trace->step('usability', ['verdict' => $verdict->value], durationMs: $processMs);
                $trace->stopProcessTimer();
                $trace->succeed();
            }

            return $verdict;
        } catch (\Throwable $e) {
            Log::warning('AI photo usability failed', [
                'intake_id' => $upload->intake_id,
                'upload_id' => $upload->id,
                'message' => $e->getMessage(),
            ]);

            $verdict = self::persistFallbackVerdict($upload);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($e->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            $trace?->fail($e->getMessage(), $e);

            return $verdict;
        }
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        if (is_float($value) && $value > 0 && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }
}
