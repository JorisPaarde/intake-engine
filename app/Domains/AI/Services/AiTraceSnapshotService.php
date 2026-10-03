<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\AnswerValueReader;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Compact dossier / remaining-question snapshots for AI traces (no PII blobs).
 *
 * Dossier snapshots include redacted value summaries + changed_fields diffs.
 * Remaining-question snapshots include hidden/skipped questions with a reason
 * and the actual next customer step.
 */
final class AiTraceSnapshotService
{
    public function __construct(
        private readonly IntakeStepBuilder $stepBuilder,
        private readonly VisibilityResolver $visibilityResolver,
        private readonly AnswerValueReader $answerValueReader,
    ) {}

    /**
     * @return array{answers: list<array<string, mixed>>, answer_count: int, values: array<string, mixed>}
     */
    public function answers(Intake $intake): array
    {
        $values = [];
        $answers = $intake->answers()
            ->orderBy('id')
            ->get(['question_key', 'section_instance_key', 'prefill_source', 'value'])
            ->map(function (IntakeAnswer $answer) use (&$values): array {
                $composite = VisibilityResolver::compositeKey(
                    $answer->question_key,
                    $answer->section_instance_key,
                );
                $summary = $this->summarizeValue($answer->value);
                $values[$composite] = $summary;

                return [
                    'question_key' => $answer->question_key,
                    'section_instance_key' => $answer->section_instance_key,
                    'prefill_source' => $answer->prefill_source,
                    'value_keys' => is_array($answer->value) ? array_keys($answer->value) : [],
                    'has_value' => $answer->value !== null && $answer->value !== [],
                    'value' => $summary,
                ];
            })
            ->values()
            ->all();

        return [
            'answers' => $answers,
            'answer_count' => count($answers),
            'values' => $values,
        ];
    }

    /**
     * @param  array{answers: list<array<string, mixed>>, answer_count: int, values?: array<string, mixed>}  $before
     * @param  array{answers: list<array<string, mixed>>, answer_count: int, values?: array<string, mixed>}  $after
     * @return list<array{question_key: string, section_instance_key: string|null, change: string, before: mixed, after: mixed}>
     */
    public function changedFields(array $before, array $after): array
    {
        $beforeValues = $before['values'] ?? [];
        $afterValues = $after['values'] ?? [];
        $keys = array_values(array_unique([...array_keys($beforeValues), ...array_keys($afterValues)]));
        sort($keys);

        $changed = [];

        foreach ($keys as $composite) {
            $was = $beforeValues[$composite] ?? null;
            $is = $afterValues[$composite] ?? null;

            if ($was === $is) {
                continue;
            }

            [$questionKey, $sectionInstanceKey] = $this->splitComposite($composite);
            $change = match (true) {
                $was === null => 'added',
                $is === null => 'removed',
                default => 'updated',
            };

            $changed[] = [
                'question_key' => $questionKey,
                'section_instance_key' => $sectionInstanceKey,
                'change' => $change,
                'before' => $was,
                'after' => $is,
            ];
        }

        return $changed;
    }

    /**
     * @return array{
     *     questions: list<array<string, mixed>>,
     *     next_step: array<string, mixed>|null,
     *     visible_count: int,
     *     hidden_count: int
     * }
     */
    public function remainingQuestions(Intake $intake): array
    {
        try {
            $version = $intake->templateVersion()
                ->with(['sections.questions.options', 'sections.questions.rules'])
                ->first();

            if (! $version instanceof IntakeTemplateVersion) {
                return [
                    'questions' => [],
                    'next_step' => null,
                    'visible_count' => 0,
                    'hidden_count' => 0,
                ];
            }

            $steps = $this->stepBuilder->build($intake->fresh() ?? $intake, $version);
            $visibleKeys = [];
            foreach ($steps as $step) {
                $visibleKeys[VisibilityResolver::compositeKey($step['question_key'], $step['section_instance_key'])] = true;
            }

            $questions = $this->catalogQuestions($intake, $version, $visibleKeys);
            $visibleCount = count(array_filter($questions, static fn (array $row): bool => ($row['visible'] ?? false) === true));
            $hiddenCount = count($questions) - $visibleCount;
            $next = $steps[0] ?? null;

            return [
                'questions' => $questions,
                'next_step' => $next === null ? null : [
                    'question_key' => $next['question_key'],
                    'section_instance_key' => $next['section_instance_key'],
                    'section_key' => $next['section_key'],
                    'title' => $next['title'],
                ],
                'visible_count' => $visibleCount,
                'hidden_count' => $hiddenCount,
            ];
        } catch (Throwable) {
            return [
                'questions' => [],
                'next_step' => null,
                'visible_count' => 0,
                'hidden_count' => 0,
            ];
        }
    }

    /**
     * @param  array<string, true>  $visibleKeys
     * @return list<array<string, mixed>>
     */
    private function catalogQuestions(Intake $intake, IntakeTemplateVersion $version, array $visibleKeys): array
    {
        $intake->loadMissing('answers');
        $answers = [];
        $answerSources = [];
        foreach ($intake->answers as $answer) {
            $composite = VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key);
            $answers[$composite] = $answer->value;
            $answerSources[$composite] = $answer->prefill_source;
        }

        $questionTypes = [];
        $sectionsByQuestionKey = [];
        /** @var Collection<string, IntakeQuestion> $allQuestions */
        $allQuestions = collect();

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
                $allQuestions->put($question->key, $question);
            }
        }

        $rows = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $answers, $questionTypes);
                $count = max(1, $count);

                for ($i = 1; $i <= $count; $i++) {
                    $instanceKey = Str::singular($section->key).'-'.$i;
                    $this->appendCatalogRows(
                        $rows,
                        $section,
                        $instanceKey,
                        $allQuestions,
                        $answers,
                        $answerSources,
                        $questionTypes,
                        $sectionsByQuestionKey,
                        $visibleKeys,
                    );
                }

                continue;
            }

            $this->appendCatalogRows(
                $rows,
                $section,
                null,
                $allQuestions,
                $answers,
                $answerSources,
                $questionTypes,
                $sectionsByQuestionKey,
                $visibleKeys,
            );
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, IntakeQuestion>  $allQuestions
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, string|null>  $answerSources
     * @param  array<string, QuestionType>  $questionTypes
     * @param  array<string, IntakeSection>  $sectionsByQuestionKey
     * @param  array<string, true>  $visibleKeys
     */
    private function appendCatalogRows(
        array &$rows,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        Collection $allQuestions,
        array $answers,
        array $answerSources,
        array $questionTypes,
        array $sectionsByQuestionKey,
        array $visibleKeys,
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
            $answerSource = $answerSources[$composite] ?? null;
            $isVisibleInSteps = isset($visibleKeys[$composite]);

            $skipSources = $question->meta['skip_when_prefilled_by'] ?? null;
            $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];
            $prefilledSkipped = $answerSource === 'request_text'
                || ($answerSource !== null && in_array($answerSource, $skipSources, true));

            $audience = is_string($question->meta['audience'] ?? null)
                ? (string) $question->meta['audience']
                : null;
            $internal = ($question->meta['internal'] ?? false) === true
                || $audience === 'installer'
                || $audience === 'internal';

            $reason = match (true) {
                $isVisibleInSteps => 'visible',
                $prefilledSkipped => 'prefilled',
                $internal => 'intern',
                $state['visible'] !== true => 'niet_relevant',
                default => 'overgeslagen',
            };

            $rows[] = [
                'question_key' => $question->key,
                'section_instance_key' => $sectionInstanceKey,
                'section_key' => $section->key,
                'visible' => $isVisibleInSteps,
                'required' => $state['required'] === true,
                'reason' => $reason,
                'prefill_source' => $answerSource,
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
     * Compact value summary safe for traces (no long free-text / binary).
     */
    private function summarizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            if (is_string($value) && strlen($value) > 120) {
                return substr($value, 0, 117).'…';
            }

            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $out[$key] = ['keys' => array_keys($item), 'count' => count($item)];

                continue;
            }

            if (is_string($item) && strlen($item) > 80) {
                $out[$key] = substr($item, 0, 77).'…';

                continue;
            }

            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitComposite(string $composite): array
    {
        if (! str_contains($composite, '|')) {
            return [$composite, null];
        }

        [$questionKey, $sectionInstanceKey] = explode('|', $composite, 2);

        return [$questionKey, $sectionInstanceKey === '' ? null : $sectionInstanceKey];
    }
}
