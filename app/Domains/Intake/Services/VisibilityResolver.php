<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeQuestionRule;
use App\Domains\Intake\Models\IntakeSection;
use App\Enums\QuestionType;
use App\Enums\RuleEffect;
use App\Enums\RuleOperator;
use Illuminate\Support\Collection;

final class VisibilityResolver
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
    ) {}

    /**
     * @param  Collection<int, IntakeQuestion>  $questions
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     * @param  array<string, IntakeSection>  $sectionsByQuestionKey
     * @param  list<array{question_key: string, section_instance_key: string|null}>  $targets
     * @return array<string, array{visible: bool, required: bool}>
     */
    public function resolve(
        Collection $questions,
        array $answers,
        array $questionTypes,
        array $sectionsByQuestionKey,
        array $targets,
    ): array {
        $questionsByKey = $questions->keyBy('key');

        $resolved = [];

        foreach ($targets as $target) {
            $compositeKey = self::compositeKey($target['question_key'], $target['section_instance_key']);
            $question = $questionsByKey->get($target['question_key']);

            if (! $question instanceof IntakeQuestion) {
                $resolved[$compositeKey] = ['visible' => false, 'required' => false];

                continue;
            }

            $resolved[$compositeKey] = $this->resolveQuestion(
                $question,
                $target['section_instance_key'],
                $answers,
                $questionTypes,
                $sectionsByQuestionKey,
            );
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     * @param  array<string, IntakeSection>  $sectionsByQuestionKey
     * @return array{visible: bool, required: bool}
     */
    public function resolveQuestion(
        IntakeQuestion $question,
        ?string $sectionInstanceKey,
        array $answers,
        array $questionTypes,
        array $sectionsByQuestionKey,
    ): array {
        $showRules = $question->rules->filter(
            static fn (IntakeQuestionRule $rule): bool => $rule->effect === RuleEffect::Show,
        );

        $visible = $showRules->isEmpty() || $showRules->every(
            fn (IntakeQuestionRule $rule): bool => $this->evaluateRule(
                $rule,
                $question,
                $sectionInstanceKey,
                $answers,
                $questionTypes,
                $sectionsByQuestionKey,
            ),
        );

        if (! $visible) {
            return ['visible' => false, 'required' => false];
        }

        $requireRules = $question->rules->filter(
            static fn (IntakeQuestionRule $rule): bool => $rule->effect === RuleEffect::Require,
        );

        $conditionallyRequired = $requireRules->contains(
            fn (IntakeQuestionRule $rule): bool => $this->evaluateRule(
                $rule,
                $question,
                $sectionInstanceKey,
                $answers,
                $questionTypes,
                $sectionsByQuestionKey,
            ),
        );

        return [
            'visible' => true,
            'required' => $question->is_required || $conditionallyRequired,
        ];
    }

    public static function compositeKey(string $questionKey, ?string $sectionInstanceKey): string
    {
        if ($sectionInstanceKey === null || $sectionInstanceKey === '') {
            return $questionKey;
        }

        return $sectionInstanceKey.'__'.$questionKey;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     * @param  array<string, IntakeSection>  $sectionsByQuestionKey
     */
    private function evaluateRule(
        IntakeQuestionRule $rule,
        IntakeQuestion $targetQuestion,
        ?string $targetSectionInstanceKey,
        array $answers,
        array $questionTypes,
        array $sectionsByQuestionKey,
    ): bool {
        $sourceType = $questionTypes[$rule->source_question_key] ?? null;

        if (! $sourceType instanceof QuestionType) {
            return false;
        }

        $sourceInstanceKey = $this->sourceSectionInstanceKey(
            $targetQuestion,
            $targetSectionInstanceKey,
            $rule->source_question_key,
            $sectionsByQuestionKey,
        );

        $answerKey = self::compositeKey($rule->source_question_key, $sourceInstanceKey);
        $answerValue = $answers[$answerKey] ?? null;

        if ($rule->operator === RuleOperator::Filled) {
            return $this->answerValueReader->isFilled($answerValue, $sourceType);
        }

        $actual = $this->answerValueReader->readComparable($answerValue, $sourceType);
        $expected = $this->answerValueReader->readRuleComparable($rule->value, $sourceType);

        return $this->compare($rule->operator, $actual, $expected, $sourceType);
    }

    /**
     * @param  array<string, IntakeSection>  $sectionsByQuestionKey
     */
    private function sourceSectionInstanceKey(
        IntakeQuestion $targetQuestion,
        ?string $targetSectionInstanceKey,
        string $sourceQuestionKey,
        array $sectionsByQuestionKey,
    ): ?string {
        $targetSection = $targetQuestion->relationLoaded('section')
            ? $targetQuestion->section
            : ($sectionsByQuestionKey[$targetQuestion->key] ?? null);

        $sourceSection = $sectionsByQuestionKey[$sourceQuestionKey] ?? null;

        if (
            $targetSectionInstanceKey !== null
            && $sourceSection instanceof IntakeSection
            && $sourceSection->is_repeatable
            && $targetSection instanceof IntakeSection
            && $targetSection->id === $sourceSection->id
        ) {
            return $targetSectionInstanceKey;
        }

        return null;
    }

    private function compare(
        RuleOperator $operator,
        mixed $actual,
        mixed $expected,
        QuestionType $sourceType,
    ): bool {
        return match ($operator) {
            RuleOperator::Equals => $this->valuesEqual($actual, $expected, $sourceType),
            RuleOperator::NotEquals => ! $this->valuesEqual($actual, $expected, $sourceType),
            RuleOperator::In => $this->isIn($actual, $expected, $sourceType),
            RuleOperator::NotIn => ! $this->isIn($actual, $expected, $sourceType),
            RuleOperator::Gt => $this->compareNumbers($actual, $expected, static fn (float $a, float $b): bool => $a > $b),
            RuleOperator::Gte => $this->compareNumbers($actual, $expected, static fn (float $a, float $b): bool => $a >= $b),
            RuleOperator::Lt => $this->compareNumbers($actual, $expected, static fn (float $a, float $b): bool => $a < $b),
            RuleOperator::Lte => $this->compareNumbers($actual, $expected, static fn (float $a, float $b): bool => $a <= $b),
            RuleOperator::Filled => false,
        };
    }

    private function valuesEqual(mixed $actual, mixed $expected, QuestionType $sourceType): bool
    {
        if ($actual === null || $expected === null) {
            return false;
        }

        if ($sourceType === QuestionType::MultiChoice) {
            if (! is_array($actual) || ! is_array($expected)) {
                return false;
            }

            sort($actual);
            sort($expected);

            return $actual === $expected;
        }

        return $actual === $expected;
    }

    /**
     * @param  (callable(float, float): bool)  $compare
     */
    private function compareNumbers(mixed $actual, mixed $expected, callable $compare): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return $compare((float) $actual, (float) $expected);
    }

    private function isIn(mixed $actual, mixed $expected, QuestionType $sourceType): bool
    {
        $candidates = $this->normalizeInCandidates($expected);

        if ($candidates === []) {
            return false;
        }

        if ($sourceType === QuestionType::MultiChoice) {
            if (! is_array($actual)) {
                return false;
            }

            return array_intersect($actual, $candidates) !== [];
        }

        return in_array($actual, $candidates, true);
    }

    /**
     * @return list<mixed>
     */
    private function normalizeInCandidates(mixed $expected): array
    {
        if (is_array($expected) && array_is_list($expected)) {
            return $expected;
        }

        if (is_array($expected) && isset($expected['values']) && is_array($expected['values'])) {
            return array_values($expected['values']);
        }

        if (is_array($expected) && array_key_exists('value', $expected)) {
            return [$expected['value']];
        }

        if ($expected !== null && ! is_array($expected)) {
            return [$expected];
        }

        return [];
    }
}
