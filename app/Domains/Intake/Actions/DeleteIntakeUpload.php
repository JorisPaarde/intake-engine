<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Enums\IntakeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeleteIntakeUpload
{
    public function __construct(
        private readonly ProgressCalculator $progressCalculator,
    ) {}

    public function handle(Intake $intake, IntakeUpload $upload): void
    {
        if ($upload->intake_id !== $intake->id) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto hoort niet bij deze opname.',
            ]);
        }

        [$disk, $path, $analysisPath] = DB::transaction(function () use ($intake, $upload): array {
            $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $lockedUpload = IntakeUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedIntake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)
                || $lockedUpload->intake_id !== $lockedIntake->id) {
                throw ValidationException::withMessages([
                    'photo' => 'Deze foto kan niet meer worden verwijderd.',
                ]);
            }

            $disk = $lockedUpload->disk;
            $path = $lockedUpload->path;
            $analysisPath = $lockedUpload->analysis_path;
            $questionKey = $lockedUpload->question_key;
            $sectionInstanceKey = $lockedUpload->section_instance_key;

            DossierEvidenceLink::query()
                ->where('intake_id', $lockedIntake->id)
                ->where('evidence_type', 'intake_upload')
                ->where('evidence_id', $lockedUpload->id)
                ->delete();
            $lockedUpload->delete();

            $this->syncAnswerUploadIds($intake, $questionKey, $sectionInstanceKey);
            $this->touchProgress($intake);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'customer',
                'actor_id' => null,
                'event' => 'upload_deleted',
                'properties' => [
                    'upload_id' => $lockedUpload->id,
                    'question_key' => $questionKey,
                ],
                'created_at' => now(),
            ]);

            return [$disk, $path, $analysisPath];
        }, 3);

        $this->deleteStoredMedia($disk, $path);

        if (is_string($analysisPath) && $analysisPath !== '') {
            $this->deleteStoredMedia($disk, $analysisPath);
        }
    }

    private function deleteStoredMedia(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
            // Retry asynchronously after the database mutation has committed.
        }

        DeleteStoredMediaJob::dispatch($disk, $path);
    }

    private function syncAnswerUploadIds(Intake $intake, string $questionKey, ?string $sectionInstanceKey): void
    {
        $query = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey);

        if ($sectionInstanceKey === null) {
            $query->whereNull('section_instance_key');
        } else {
            $query->where('section_instance_key', $sectionInstanceKey);
        }

        $ids = $query->orderBy('sort_order')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $answerQuery = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey);

        if ($sectionInstanceKey === null) {
            $answerQuery->whereNull('section_instance_key');
        } else {
            $answerQuery->where('section_instance_key', $sectionInstanceKey);
        }

        $answer = $answerQuery->first();

        if ($answer === null) {
            return;
        }

        $answer->update([
            'value' => ['upload_ids' => $ids],
            'answered_at' => now(),
        ]);
    }

    private function touchProgress(Intake $intake): void
    {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
        $progress = $this->progressCalculator->calculate($intake, $version);

        $intake->update([
            'progress_percent' => $progress['percent'],
        ]);
    }
}
