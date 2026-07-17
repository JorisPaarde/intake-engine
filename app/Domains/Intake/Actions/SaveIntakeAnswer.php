<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Services\AnswerValueReader;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveIntakeAnswer
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly ProgressCalculator $progressCalculator,
    ) {}

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function handle(
        Intake $intake,
        string $questionKey,
        ?string $sectionInstanceKey,
        ?array $value,
    ): IntakeAnswer {
        $question = $this->findQuestion($intake, $questionKey);

        if ($question->type === QuestionType::Photo) {
            throw ValidationException::withMessages([
                'value' => 'Foto-upload volgt in een latere stap.',
            ]);
        }

        $normalized = $this->normalizeValue($question->type, $value);

        if ($question->is_required && ! $this->answerValueReader->isFilled($normalized, $question->type)) {
            // Allow clearing optional; required empty saves are rejected on "next", but autosave of empty optional is ok.
            // For required fields, still persist partial drafts if user typed then cleared — progress will reflect.
        }

        return DB::transaction(function () use ($intake, $questionKey, $sectionInstanceKey, $normalized): IntakeAnswer {
            $query = IntakeAnswer::query()
                ->where('intake_id', $intake->id)
                ->where('question_key', $questionKey);

            if ($sectionInstanceKey === null) {
                $query->whereNull('section_instance_key');
            } else {
                $query->where('section_instance_key', $sectionInstanceKey);
            }

            $answer = $query->first();

            if ($answer === null) {
                $answer = IntakeAnswer::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => $questionKey,
                    'section_instance_key' => $sectionInstanceKey,
                    'value' => $normalized,
                    'answered_at' => now(),
                ]);
            } else {
                $answer->update([
                    'value' => $normalized,
                    'answered_at' => now(),
                ]);
            }

            $this->touchProgress($intake);

            return $answer;
        });
    }

    private function touchProgress(Intake $intake): void
    {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
        $progress = $this->progressCalculator->calculate($intake, $version);

        $updates = [
            'progress_percent' => $progress['percent'],
        ];

        if ($intake->status === IntakeStatus::Sent) {
            $updates['status'] = IntakeStatus::InProgress;
            $updates['started_at'] = $intake->started_at ?? now();
        }

        if ($intake->status === IntakeStatus::InProgress && $intake->started_at === null) {
            $updates['started_at'] = now();
        }

        $intake->update($updates);
    }

    private function findQuestion(Intake $intake, string $questionKey): IntakeQuestion
    {
        $intake->loadMissing(['templateVersion.sections.questions']);

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $question;
                }
            }
        }

        throw ValidationException::withMessages([
            'question_key' => 'Onbekende vraag.',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>
     */
    private function normalizeValue(QuestionType $type, ?array $value): array
    {
        $value ??= [];

        return match ($type) {
            QuestionType::ShortText, QuestionType::LongText => [
                'text' => isset($value['text']) ? trim((string) $value['text']) : '',
            ],
            QuestionType::Number => [
                'number' => $this->normalizeNumber($value['number'] ?? null),
            ],
            QuestionType::SingleChoice => [
                'value' => isset($value['value']) ? (string) $value['value'] : '',
            ],
            QuestionType::MultiChoice => [
                'values' => array_values(array_map('strval', is_array($value['values'] ?? null) ? $value['values'] : [])),
            ],
            QuestionType::Boolean => [
                'bool' => array_key_exists('bool', $value)
                    ? filter_var($value['bool'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
            ],
            QuestionType::Photo => [
                'upload_ids' => [],
            ],
        };
    }

    private function normalizeNumber(mixed $number): int|float|null
    {
        if ($number === null || $number === '') {
            return null;
        }

        if (! is_numeric($number)) {
            return null;
        }

        return str_contains((string) $number, '.') ? (float) $number : (int) $number;
    }
}
