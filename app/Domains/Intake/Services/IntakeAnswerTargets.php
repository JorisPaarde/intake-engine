<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeSection;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Answer map and question targets (incl. repeated section instances) shared by
 * {@see ProgressCalculator} and {@see GenerateIntakeReportHtml}, so the report covers
 * exactly the targets that progress counts.
 */
final class IntakeAnswerTargets
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
    ) {}

    /**
     * Answers keyed by composite key; photo questions map to their current upload ids.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function answerMap(Intake $intake): array
    {
        $intake->loadMissing(['answers', 'uploads']);

        $answers = [];

        foreach ($intake->answers as $answer) {
            $key = VisibilityResolver::compositeKey(
                $answer->question_key,
                $answer->section_instance_key,
            );
            $answers[$key] = $answer->value;
        }

        $uploadIdsByKey = [];

        foreach ($intake->uploads as $upload) {
            $key = VisibilityResolver::compositeKey(
                $upload->question_key,
                $upload->section_instance_key,
            );
            $uploadIdsByKey[$key][] = $upload->id;
        }

        foreach ($uploadIdsByKey as $key => $ids) {
            $answers[$key] = ['upload_ids' => $ids];
        }

        return $answers;
    }

    /**
     * @param  Collection<int, IntakeSection>  $sections
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     * @return list<array{question_key: string, section_instance_key: string|null}>
     */
    public function targets(Collection $sections, array $answers, array $questionTypes): array
    {
        $targets = [];

        foreach ($sections as $section) {
            if ($section->is_repeatable) {
                $instanceCount = $this->repeatInstanceCount($section, $answers, $questionTypes);

                for ($index = 1; $index <= $instanceCount; $index++) {
                    $instanceKey = Str::singular($section->key).'-'.$index;

                    foreach ($section->questions as $question) {
                        $targets[] = [
                            'question_key' => $question->key,
                            'section_instance_key' => $instanceKey,
                        ];
                    }
                }

                continue;
            }

            foreach ($section->questions as $question) {
                $targets[] = [
                    'question_key' => $question->key,
                    'section_instance_key' => null,
                ];
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     */
    private function repeatInstanceCount(IntakeSection $section, array $answers, array $questionTypes): int
    {
        $countQuestionKey = $section->repeat_count_question_key;

        if ($countQuestionKey === null || $countQuestionKey === '') {
            return 0;
        }

        $type = $questionTypes[$countQuestionKey] ?? null;

        if ($type !== QuestionType::Number) {
            return 0;
        }

        $answerKey = VisibilityResolver::compositeKey($countQuestionKey, null);
        $number = $this->answerValueReader->readComparable($answers[$answerKey] ?? null, $type);

        if (! is_numeric($number)) {
            return 0;
        }

        return max(0, (int) $number);
    }
}
