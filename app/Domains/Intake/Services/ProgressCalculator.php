<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ProgressCalculator
{
    public function __construct(
        private readonly VisibilityResolver $visibilityResolver,
        private readonly AnswerValueReader $answerValueReader,
    ) {}

    /**
     * @return array{percent: int, missing_required: list<array{question_key: string, section_instance_key: string|null}>}
     */
    public function calculate(Intake $intake, IntakeTemplateVersion $version): array
    {
        $version->loadMissing(['sections.questions.rules']);

        /** @var Collection<int, IntakeSection> $sections */
        $sections = $version->sections;

        $questions = $sections
            ->flatMap(static fn (IntakeSection $section): Collection => $section->questions)
            ->values();

        $answers = $this->buildAnswerMap($intake);
        $questionTypes = $this->buildQuestionTypeMap($questions);
        $sectionsByQuestionKey = $this->buildSectionsByQuestionKey($sections);
        $targets = $this->buildTargets($sections, $answers, $questionTypes);
        $visibility = $this->visibilityResolver->resolve(
            $questions,
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
        );

        $totalVisible = 0;
        $answeredVisible = 0;
        $missingRequired = [];

        foreach ($targets as $target) {
            $question = $this->findQuestion($sections, $target['question_key']);

            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            $compositeKey = VisibilityResolver::compositeKey(
                $target['question_key'],
                $target['section_instance_key'],
            );
            $state = $visibility[$compositeKey] ?? ['visible' => false, 'required' => false];

            if (! $state['visible']) {
                continue;
            }

            $answerKey = VisibilityResolver::compositeKey(
                $target['question_key'],
                $target['section_instance_key'],
            );
            $answerValue = $answers[$answerKey] ?? null;
            $filled = $this->answerValueReader->isFilled($answerValue, $question->type);

            if ($state['required'] && ! $filled) {
                $missingRequired[] = [
                    'question_key' => $target['question_key'],
                    'section_instance_key' => $target['section_instance_key'],
                ];
            }

            if ($question->type === QuestionType::Photo) {
                continue;
            }

            $totalVisible++;
            if ($filled) {
                $answeredVisible++;
            }
        }

        $percent = $totalVisible === 0
            ? 100
            : (int) round(($answeredVisible / $totalVisible) * 100);

        return [
            'percent' => max(0, min(100, $percent)),
            'missing_required' => $missingRequired,
        ];
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private function buildAnswerMap(Intake $intake): array
    {
        $intake->loadMissing('answers');

        $answers = [];

        foreach ($intake->answers as $answer) {
            $key = VisibilityResolver::compositeKey(
                $answer->question_key,
                $answer->section_instance_key,
            );
            $answers[$key] = $answer->value;
        }

        return $answers;
    }

    /**
     * @param  Collection<int, IntakeQuestion>  $questions
     * @return array<string, QuestionType>
     */
    private function buildQuestionTypeMap(Collection $questions): array
    {
        $types = [];

        foreach ($questions as $question) {
            $types[$question->key] = $question->type;
        }

        return $types;
    }

    /**
     * @param  Collection<int, IntakeSection>  $sections
     * @return array<string, IntakeSection>
     */
    private function buildSectionsByQuestionKey(Collection $sections): array
    {
        $map = [];

        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                $map[$question->key] = $section;
                $question->setRelation('section', $section);
            }
        }

        return $map;
    }

    /**
     * @param  Collection<int, IntakeSection>  $sections
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     * @return list<array{question_key: string, section_instance_key: string|null}>
     */
    private function buildTargets(
        Collection $sections,
        array $answers,
        array $questionTypes,
    ): array {
        $targets = [];

        foreach ($sections as $section) {
            if ($section->is_repeatable) {
                $instanceCount = $this->repeatInstanceCount($section, $answers, $questionTypes);

                for ($index = 1; $index <= $instanceCount; $index++) {
                    $instanceKey = $this->sectionInstanceKey($section, $index);

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
    private function repeatInstanceCount(
        IntakeSection $section,
        array $answers,
        array $questionTypes,
    ): int {
        $countQuestionKey = $section->repeat_count_question_key;

        if ($countQuestionKey === null || $countQuestionKey === '') {
            return 0;
        }

        $type = $questionTypes[$countQuestionKey] ?? null;

        if ($type !== QuestionType::Number) {
            return 0;
        }

        $answerKey = VisibilityResolver::compositeKey($countQuestionKey, null);
        $value = $answers[$answerKey] ?? null;
        $number = $this->answerValueReader->readComparable($value, $type);

        if (! is_numeric($number)) {
            return 0;
        }

        return max(0, (int) $number);
    }

    private function sectionInstanceKey(IntakeSection $section, int $index): string
    {
        return Str::singular($section->key).'-'.$index;
    }

    /**
     * @param  Collection<int, IntakeSection>  $sections
     */
    private function findQuestion(Collection $sections, string $questionKey): ?IntakeQuestion
    {
        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $question;
                }
            }
        }

        return null;
    }
}
