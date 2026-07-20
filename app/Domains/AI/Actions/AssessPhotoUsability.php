<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\PhotoUsabilityHeuristic;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local, non-blocking photo-usability assessment (BL-007). Runs a deterministic GD
 * heuristic, records a `photo_quality` AiRun for audit, and stores the verdict on the
 * upload. Soft-fail: any error leaves the upload unflagged and never breaks the flow.
 */
final class AssessPhotoUsability
{
    public function __construct(
        private readonly PhotoUsabilityHeuristic $heuristic,
    ) {}

    public function handle(IntakeUpload $upload): PhotoUsabilityVerdict
    {
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

        try {
            $bytes = Storage::disk((string) $upload->disk)->get((string) $upload->path);

            if ($bytes === null) {
                throw new \RuntimeException('Uploadbestand niet gevonden voor beoordeling.');
            }

            $verdict = $this->heuristic->assess($bytes);

            $upload->update(['usability_verdict' => $verdict]);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'output' => ['upload_id' => $upload->id, 'verdict' => $verdict->value],
                'finished_at' => now(),
            ]);

            return $verdict;
        } catch (\Throwable $e) {
            Log::warning('AI photo usability failed', [
                'intake_id' => $upload->intake_id,
                'upload_id' => $upload->id,
                'message' => $e->getMessage(),
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($e->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            return PhotoUsabilityVerdict::Ok;
        }
    }
}
