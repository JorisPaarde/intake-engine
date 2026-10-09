<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\InternalCustomerQuestions;
use App\Domains\Intake\Support\MustAcceptQuestions;
use App\Domains\Intake\Support\OutdoorPhotoReuse;
use App\Domains\Intake\Support\PhotoContentSatisfaction;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\TechnicalDecisionKeys;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;

/**
 * Klantvoortgang over afgeronde klanttaken (BL-022 + klanttest P2).
 *
 * Interne AI-velden en door prefill overgeslagen stappen tellen niet mee. Het
 * percentage volgt afgeronde verplichte klanttaken — niet de huidige positie.
 */
final class ProgressCalculator
{
    public function __construct(
        private readonly VisibilityResolver $visibilityResolver,
        private readonly AnswerValueReader $answerValueReader,
        private readonly IntakeAnswerTargets $answerTargets,
    ) {}

    /**
     * @return array{
     *     percent: int,
     *     answered_required: int,
     *     total_required: int,
     *     missing_required: list<array{question_key: string, section_instance_key: string|null, label: string|null}>,
     *     task_keys: list<string>
     * }
     */
    public function calculate(Intake $intake, IntakeTemplateVersion $version): array
    {
        $version->loadMissing(['sections.questions.rules']);

        /** @var Collection<int, IntakeSection> $sections */
        $sections = $version->sections;

        $questions = $sections
            ->flatMap(static fn (IntakeSection $section): Collection => $section->questions)
            ->values();

        $answers = $this->answerTargets->answerMap($intake);
        $answerSources = $this->buildAnswerSourceMap($intake);
        $questionTypes = $this->buildQuestionTypeMap($questions);
        $sectionsByQuestionKey = $this->buildSectionsByQuestionKey($sections);
        $targets = $this->answerTargets->targets($sections, $answers, $questionTypes);
        $visibility = $this->visibilityResolver->resolve(
            $questions,
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
            customerMode: true,
        );

        // Percentage over verplichte zichtbare klanttaken, zodat 100% ≈ klantdeel af.
        $totalRequired = 0;
        $answeredRequired = 0;
        $missingRequired = [];
        $taskKeys = [];

        foreach ($targets as $target) {
            $question = $version->findQuestion($target['question_key']);

            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            if (InternalCustomerQuestions::hidesFromCustomer($question)
                || TechnicalDecisionKeys::hidesFromCustomer($question)) {
                continue;
            }

            $compositeKey = VisibilityResolver::compositeKey(
                $target['question_key'],
                $target['section_instance_key'],
            );
            $state = $visibility[$compositeKey] ?? ['visible' => false, 'required' => false];

            if (! $state['visible'] || ! $state['required']) {
                continue;
            }

            $answerSource = $answerSources[$compositeKey] ?? null;

            $skipSources = $question->meta['skip_when_prefilled_by'] ?? null;
            $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];

            if (PrefillSources::shouldSkipPrefill($answerSource, $skipSources)) {
                continue;
            }

            $answerValue = $answers[$compositeKey] ?? null;
            $filled = $this->answerValueReader->isFilled($answerValue, $question->type);

            if ($filled && MustAcceptQuestions::requiresAcceptance($question)) {
                $filled = MustAcceptQuestions::isAccepted($answerValue, $answerSource);
            }

            if ($filled && $question->type === QuestionType::Photo) {
                $filled = PhotoContentSatisfaction::isSatisfied(
                    $intake,
                    $target['question_key'],
                    $target['section_instance_key'],
                );
            }

            // Gevel-/tuinfoto’s dekken “rondom het huis” (optioneel/overslaan).
            if (! $filled
                && $question->type === QuestionType::Photo
                && $target['question_key'] === OutdoorPhotoReuse::TARGET_KEY
                && OutdoorPhotoReuse::hasUsableOutdoorContext($intake)) {
                $filled = true;
            }

            $totalRequired++;
            $taskKeys[] = $compositeKey;

            if ($filled) {
                $answeredRequired++;
            } else {
                $missingRequired[] = [
                    'question_key' => $target['question_key'],
                    'section_instance_key' => $target['section_instance_key'],
                    'label' => $question->label,
                ];
            }
        }

        // Geen openstaande klanttaken → 100% (klaar om af te ronden). Lege set
        // tijdens opbouw telt niet als “af”; CompletenessChecker blijft de poort.
        $percent = $totalRequired === 0
            ? 100
            : (int) round(($answeredRequired / $totalRequired) * 100);

        return [
            'percent' => max(0, min(100, $percent)),
            'answered_required' => $answeredRequired,
            'total_required' => $totalRequired,
            'missing_required' => $missingRequired,
            'task_keys' => $taskKeys,
        ];
    }

    /**
     * Diff nieuwe verplichte klanttaken t.o.v. een eerdere task_keys-lijst.
     *
     * @param  list<string>  $previousTaskKeys
     * @param  array{
     *     percent: int,
     *     answered_required: int,
     *     total_required: int,
     *     missing_required: list<array{question_key: string, section_instance_key: string|null, label: string|null}>,
     *     task_keys: list<string>
     * }  $progress
     * @return list<string>
     */
    public function newTaskLabels(array $previousTaskKeys, array $progress): array
    {
        $previous = array_fill_keys($previousTaskKeys, true);
        $labels = [];

        foreach ($progress['missing_required'] as $item) {
            $key = VisibilityResolver::compositeKey(
                $item['question_key'],
                $item['section_instance_key'],
            );

            if (isset($previous[$key])) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return array<string, string|null>
     */
    private function buildAnswerSourceMap(Intake $intake): array
    {
        $intake->loadMissing('answers');

        $sources = [];

        foreach ($intake->answers as $answer) {
            $key = VisibilityResolver::compositeKey(
                $answer->question_key,
                $answer->section_instance_key,
            );
            $sources[$key] = $answer->prefill_source;
        }

        return $sources;
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
}
