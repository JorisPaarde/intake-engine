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

/**
 * Builds the customer wizard as one visible question per step (BL-018).
 *
 * @phpstan-type IntakeStep array{
 *     key: string,
 *     section_key: string,
 *     section_instance_key: string|null,
 *     question_key: string,
 *     title: string,
 *     section_title: string,
 *     description: string|null,
 *     help_text: string|null,
 *     is_repeatable: bool,
 *     is_required: bool
 * }
 */
final class IntakeStepBuilder
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly VisibilityResolver $visibilityResolver,
    ) {}

    /**
     * @param  array<string, array<string, mixed>|null>  $liveAnswers  optional in-memory form answers for live visibility
     * @return list<IntakeStep>
     */
    public function build(Intake $intake, IntakeTemplateVersion $version, array $liveAnswers = []): array
    {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules']);
        $intake->loadMissing('answers');

        $answers = [];
        $answerSources = [];
        foreach ($intake->answers as $answer) {
            $composite = VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key);
            $answers[$composite] = $answer->value;
            $answerSources[$composite] = $answer->prefill_source;
        }

        foreach ($liveAnswers as $key => $value) {
            if (is_array($value)) {
                $answers[$key] = $value;
            }
        }

        $questionTypes = [];
        $sectionsByQuestionKey = [];
        $allQuestions = collect();

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
                $allQuestions->put($question->key, $question);
            }
        }

        $steps = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $answers, $questionTypes);

                for ($i = 1; $i <= $count; $i++) {
                    $instanceKey = Str::singular($section->key).'-'.$i;
                    $this->appendVisibleQuestionSteps(
                        $steps,
                        $section,
                        $instanceKey,
                        $allQuestions,
                        $answers,
                        $answerSources,
                        $questionTypes,
                        $sectionsByQuestionKey,
                    );
                }

                continue;
            }

            $this->appendVisibleQuestionSteps(
                $steps,
                $section,
                null,
                $allQuestions,
                $answers,
                $answerSources,
                $questionTypes,
                $sectionsByQuestionKey,
            );
        }

        return $steps;
    }

    /**
     * @param  list<IntakeStep>  $steps
     * @param  Collection<string, IntakeQuestion>  $allQuestions
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, string|null>  $answerSources
     * @param  array<string, QuestionType>  $questionTypes
     * @param  array<string, IntakeSection>  $sectionsByQuestionKey
     */
    private function appendVisibleQuestionSteps(
        array &$steps,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        Collection $allQuestions,
        array $answers,
        array $answerSources,
        array $questionTypes,
        array $sectionsByQuestionKey,
    ): void {
        $questions = $section->questions->sortBy('sort_order')->values();

        $targets = [];
        foreach ($questions as $question) {
            $targets[] = [
                'question_key' => $question->key,
                'section_instance_key' => $sectionInstanceKey,
            ];
        }

        $visibility = $this->visibilityResolver->resolve(
            $allQuestions->values(),
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
        );

        foreach ($questions as $question) {
            $composite = VisibilityResolver::compositeKey($question->key, $sectionInstanceKey);
            $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];

            if ($state['visible'] !== true) {
                continue;
            }

            $skipSource = $question->meta['skip_when_prefilled_by'] ?? null;

            if (is_string($skipSource) && ($answerSources[$composite] ?? null) === $skipSource) {
                continue;
            }

            $instanceSuffix = $sectionInstanceKey === null ? '' : '::'.$sectionInstanceKey;
            $sectionTitle = $sectionInstanceKey === null
                ? $section->title
                : $section->title.' '.Str::afterLast($sectionInstanceKey, '-');

            $steps[] = [
                'key' => $section->key.$instanceSuffix.'::'.$question->key,
                'section_key' => $section->key,
                'section_instance_key' => $sectionInstanceKey,
                'question_key' => $question->key,
                'title' => $question->label,
                'section_title' => $sectionTitle,
                'description' => $section->description,
                'help_text' => $question->help_text,
                'is_repeatable' => $section->is_repeatable,
                'is_required' => $state['required'] === true,
            ];
        }
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
    public function indexForCursor(
        array $steps,
        ?string $sectionKey,
        ?string $questionKey,
        ?string $sectionInstanceKey = null,
    ): int {
        if ($questionKey !== null && $questionKey !== '') {
            foreach ($steps as $index => $step) {
                if ($step['question_key'] !== $questionKey) {
                    continue;
                }

                if ($sectionKey !== null && $step['section_key'] !== $sectionKey) {
                    continue;
                }

                if ($sectionInstanceKey !== null && $step['section_instance_key'] !== $sectionInstanceKey) {
                    continue;
                }

                return $index;
            }
        }

        if ($sectionKey !== null && $sectionKey !== '') {
            foreach ($steps as $index => $step) {
                if ($step['section_key'] === $sectionKey) {
                    return $index;
                }
            }
        }

        return 0;
    }

    /**
     * @param  list<IntakeStep>  $steps
     */
    public function indexForStepKey(array $steps, ?string $stepKey): ?int
    {
        if ($stepKey === null || $stepKey === '') {
            return null;
        }

        foreach ($steps as $index => $step) {
            if ($step['key'] === $stepKey) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @deprecated Use indexForCursor(); kept for callers that only know a section.
     *
     * @param  list<IntakeStep>  $steps
     */
    public function indexForSectionKey(array $steps, ?string $sectionKey, ?string $sectionInstanceKey = null): int
    {
        return $this->indexForCursor($steps, $sectionKey, null, $sectionInstanceKey);
    }

    public function questionForStep(IntakeTemplateVersion $version, string $sectionKey, string $questionKey): ?IntakeQuestion
    {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules']);

        $section = $version->sections->firstWhere('key', $sectionKey);

        if ($section === null) {
            return null;
        }

        $question = $section->questions->firstWhere('key', $questionKey);

        if (! $question instanceof IntakeQuestion) {
            return null;
        }

        $question->loadMissing(['options', 'rules']);
        $question->setRelation('section', $section);

        return $question;
    }
}
