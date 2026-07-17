<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

/**
 * @phpstan-type IntakeStep array{
 *     key: string,
 *     section_key: string,
 *     section_instance_key: string|null,
 *     title: string,
 *     description: string|null,
 *     is_repeatable: bool
 * }
 */
final class IntakeStepBuilder
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
    ) {}

    /**
     * @return list<IntakeStep>
     */
    public function build(Intake $intake, IntakeTemplateVersion $version): array
    {
        $version->loadMissing(['sections.questions']);
        $intake->loadMissing('answers');

        $answers = [];
        foreach ($intake->answers as $answer) {
            $answers[VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key)] = $answer->value;
        }

        $questionTypes = [];
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
            }
        }

        $steps = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $answers, $questionTypes);

                for ($i = 1; $i <= $count; $i++) {
                    $instanceKey = Str::singular($section->key).'-'.$i;
                    $steps[] = [
                        'key' => $section->key.'::'.$instanceKey,
                        'section_key' => $section->key,
                        'section_instance_key' => $instanceKey,
                        'title' => $section->title.' '.$i,
                        'description' => $section->description,
                        'is_repeatable' => true,
                    ];
                }

                continue;
            }

            $steps[] = [
                'key' => $section->key,
                'section_key' => $section->key,
                'section_instance_key' => null,
                'title' => $section->title,
                'description' => $section->description,
                'is_repeatable' => false,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     */
    private function repeatCount(IntakeSection $section, array $answers, array $questionTypes): int
    {
        $key = $section->repeat_count_question_key;

        if ($key === null || $key === '') {
            return 0;
        }

        $type = $questionTypes[$key] ?? null;
        if ($type !== QuestionType::Number) {
            return 0;
        }

        $number = $this->answerValueReader->readComparable(
            $answers[VisibilityResolver::compositeKey($key, null)] ?? null,
            $type,
        );

        if (! is_numeric($number)) {
            return 0;
        }

        return max(0, min(8, (int) $number));
    }

    /**
     * @param  list<IntakeStep>  $steps
     */
    public function indexForSectionKey(array $steps, ?string $sectionKey, ?string $sectionInstanceKey = null): int
    {
        foreach ($steps as $index => $step) {
            if ($step['section_key'] !== $sectionKey) {
                continue;
            }

            if ($sectionInstanceKey === null || $step['section_instance_key'] === $sectionInstanceKey) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @return EloquentCollection<int, IntakeQuestion>
     */
    public function questionsForStep(IntakeTemplateVersion $version, string $sectionKey): EloquentCollection
    {
        $section = $version->sections->firstWhere('key', $sectionKey);

        if ($section === null) {
            return new EloquentCollection;
        }

        return $section->questions()
            ->with(['options', 'rules'])
            ->orderBy('sort_order')
            ->get();
    }
}
