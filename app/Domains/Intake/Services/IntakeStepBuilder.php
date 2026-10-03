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
 * Builds the customer wizard as one visible question per step (BL-018),
 * and a full question catalog with skip reasons for AI traces (BL-116).
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
 * @phpstan-type CatalogRow array{
 *     question_key: string,
 *     section_instance_key: string|null,
 *     section_key: string,
 *     title: string,
 *     visible: bool,
 *     required: bool,
 *     reason: 'visible'|'prefilled'|'niet_relevant'|'intern'|'overgeslagen',
 *     prefill_source: string|null,
 *     answered: bool
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
        $context = $this->buildContext($intake, $version, $liveAnswers);
        $steps = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $context['answers'], $context['questionTypes']);

                for ($i = 1; $i <= $count; $i++) {
                    $instanceKey = Str::singular($section->key).'-'.$i;
                    $this->appendVisibleQuestionSteps(
                        $steps,
                        $section,
                        $instanceKey,
                        $context,
                    );
                }

                continue;
            }

            $this->appendVisibleQuestionSteps(
                $steps,
                $section,
                null,
                $context,
            );
        }

        return $steps;
    }

    /**
     * Full question catalog for every section instance (visible + skipped) with reasons.
     *
     * @param  array<string, array<string, mixed>|null>  $liveAnswers
     * @return list<CatalogRow>
     */
    public function buildCatalog(Intake $intake, IntakeTemplateVersion $version, array $liveAnswers = []): array
    {
        $context = $this->buildContext($intake, $version, $liveAnswers);
        $rows = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $context['answers'], $context['questionTypes']);
                $count = max(1, $count);

                for ($i = 1; $i <= $count; $i++) {
                    $instanceKey = Str::singular($section->key).'-'.$i;
                    $this->appendCatalogRows($rows, $section, $instanceKey, $context);
                }

                continue;
            }

            $this->appendCatalogRows($rows, $section, null, $context);
        }

        return $rows;
    }

    /**
     * First catalog row that is visible and unanswered (wizard remaining work).
     *
     * @param  list<CatalogRow>  $catalog
     * @return CatalogRow|null
     */
    public function nextUnansweredVisible(array $catalog): ?array
    {
        foreach ($catalog as $row) {
            if ($row['visible'] === true && $row['answered'] !== true) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<CatalogRow>  $catalog
     */
    public function remainingUnansweredVisibleCount(array $catalog): int
    {
        $count = 0;
        foreach ($catalog as $row) {
            if ($row['visible'] === true && $row['answered'] !== true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $liveAnswers
     * @return array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }
     */
    private function buildContext(Intake $intake, IntakeTemplateVersion $version, array $liveAnswers = []): array
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

        return [
            'answers' => $answers,
            'answerSources' => $answerSources,
            'questionTypes' => $questionTypes,
            'sectionsByQuestionKey' => $sectionsByQuestionKey,
            'allQuestions' => $allQuestions,
        ];
    }

    /**
     * @param  list<IntakeStep>  $steps
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     */
    private function appendVisibleQuestionSteps(
        array &$steps,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        array $context,
    ): void {
        $questions = $section->questions->sortBy('sort_order')->values();
        $visibility = $this->resolveVisibilityForSection($questions, $sectionInstanceKey, $context);

        foreach ($questions as $question) {
            $presentation = $this->questionPresentation(
                $question,
                $sectionInstanceKey,
                $visibility,
                $context,
            );

            if ($presentation['reason'] !== 'visible') {
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
                'is_required' => $presentation['required'],
            ];
        }
    }

    /**
     * @param  list<CatalogRow>  $rows
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     */
    private function appendCatalogRows(
        array &$rows,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        array $context,
    ): void {
        $questions = $section->questions->sortBy('sort_order')->values();
        $visibility = $this->resolveVisibilityForSection($questions, $sectionInstanceKey, $context);

        foreach ($questions as $question) {
            $presentation = $this->questionPresentation(
                $question,
                $sectionInstanceKey,
                $visibility,
                $context,
            );

            $rows[] = [
                'question_key' => $question->key,
                'section_instance_key' => $sectionInstanceKey,
                'section_key' => $section->key,
                'title' => $question->label,
                'visible' => $presentation['visible'],
                'required' => $presentation['required'],
                'reason' => $presentation['reason'],
                'prefill_source' => $presentation['prefill_source'],
                'answered' => $presentation['answered'],
            ];
        }
    }

    /**
     * Shared visibility/skip reason for wizard steps and catalog rows.
     *
     * @param  Collection<int, IntakeQuestion>  $questions
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     * @return array<string, array{visible: bool, required: bool}>
     */
    private function resolveVisibilityForSection(
        Collection $questions,
        ?string $sectionInstanceKey,
        array $context,
    ): array {
        $targets = [];
        foreach ($questions as $question) {
            $targets[] = [
                'question_key' => $question->key,
                'section_instance_key' => $sectionInstanceKey,
            ];
        }

        return $this->visibilityResolver->resolve(
            $context['allQuestions']->values(),
            $context['answers'],
            $context['questionTypes'],
            $context['sectionsByQuestionKey'],
            $targets,
            customerMode: true,
        );
    }

    /**
     * @param  array<string, array{visible: bool, required: bool}>  $visibility
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     * @return array{
     *     visible: bool,
     *     required: bool,
     *     reason: 'visible'|'prefilled'|'niet_relevant'|'intern'|'overgeslagen',
     *     prefill_source: string|null,
     *     answered: bool
     * }
     */
    private function questionPresentation(
        IntakeQuestion $question,
        ?string $sectionInstanceKey,
        array $visibility,
        array $context,
    ): array {
        $composite = VisibilityResolver::compositeKey($question->key, $sectionInstanceKey);
        $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];
        $answerSource = $context['answerSources'][$composite] ?? null;
        $answerValue = $context['answers'][$composite] ?? null;
        $answered = $this->answerValueReader->isFilled(
            is_array($answerValue) ? $answerValue : null,
            $question->type,
        );

        $prefilledSkipped = $this->isPrefillSkipped($question, $answerSource);
        $internal = $this->isInternalQuestion($question);
        $ruleVisible = $state['visible'] === true;
        $wizardVisible = $ruleVisible && ! $prefilledSkipped;

        $reason = $this->catalogReason(
            wizardVisible: $wizardVisible,
            ruleVisible: $ruleVisible,
            prefilledSkipped: $prefilledSkipped,
            internal: $internal,
        );

        return [
            'visible' => $wizardVisible,
            'required' => $state['required'] === true,
            'reason' => $reason,
            'prefill_source' => $answerSource,
            'answered' => $answered,
        ];
    }

    /**
     * @return 'visible'|'prefilled'|'niet_relevant'|'intern'|'overgeslagen'
     */
    private function catalogReason(
        bool $wizardVisible,
        bool $ruleVisible,
        bool $prefilledSkipped,
        bool $internal,
    ): string {
        return match (true) {
            $wizardVisible => 'visible',
            $prefilledSkipped => 'prefilled',
            $internal => 'intern',
            ! $ruleVisible => 'niet_relevant',
            default => 'overgeslagen',
        };
    }

    private function isPrefillSkipped(IntakeQuestion $question, ?string $answerSource): bool
    {
        // Eén bron of een lijst: `building_type` kan zowel uit de BAG als uit een
        // geregistreerd energielabel komen, en beide mogen de vraag laten vervallen.
        $skipSources = $question->meta['skip_when_prefilled_by'] ?? null;
        $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];

        // Een lokale, evidente conclusie uit de openingszin is brondata van de
        // aanvrager zelf. Ook oudere gepinde templates kenden deze bronnaam nog niet.
        return $answerSource === 'request_text'
            || ($answerSource !== null && in_array($answerSource, $skipSources, true));
    }

    private function isInternalQuestion(IntakeQuestion $question): bool
    {
        $audience = is_string($question->meta['audience'] ?? null)
            ? (string) $question->meta['audience']
            : null;

        return ($question->meta['internal'] ?? false) === true
            || $audience === 'installer'
            || $audience === 'internal';
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
