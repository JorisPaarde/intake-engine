<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\ProgressCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

        DB::transaction(function () use ($intake, $upload): void {
            $disk = $upload->disk;
            $path = $upload->path;
            $questionKey = $upload->question_key;
            $sectionInstanceKey = $upload->section_instance_key;

            $upload->delete();

            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }

            $this->syncAnswerUploadIds($intake, $questionKey, $sectionInstanceKey);
            $this->touchProgress($intake);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'customer',
                'actor_id' => null,
                'event' => 'upload_deleted',
                'properties' => [
                    'upload_id' => $upload->id,
                    'question_key' => $questionKey,
                ],
                'created_at' => now(),
            ]);
        });
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
