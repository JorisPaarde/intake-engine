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

final class GenerateIntakeReportHtml
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly VisibilityResolver $visibilityResolver,
    ) {}

    /**
     * @param  list<array{code: string, label: string}>  $attentionPoints
     */
    public function handle(
        Intake $intake,
        IntakeTemplateVersion $version,
        array $attentionPoints = [],
    ): string {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules', 'template']);
        $intake->loadMissing(['answers', 'uploads']);

        $sections = $this->buildReportSections($intake, $version);

        return view('reports.intake-html', [
            'intake' => $intake,
            'version' => $version,
            'sections' => $sections,
            'attentionPoints' => $attentionPoints,
            'generatedAt' => now(),
        ])->render();
    }

    /**
     * @return list<array{title: string, instance_label: string|null, questions: list<array{label: string, display: string, is_photo: bool}>}>
     */
    private function buildReportSections(Intake $intake, IntakeTemplateVersion $version): array
    {
        /** @var Collection<int, IntakeSection> $sections */
        $sections = $version->sections;
        $questions = $sections->flatMap(static fn (IntakeSection $section): Collection => $section->questions)->values();
        $answers = $this->buildAnswerMap($intake);
        $questionTypes = [];
        $sectionsByQuestionKey = [];

        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
            }
        }

        $targets = $this->buildTargets($sections, $answers, $questionTypes);
        $visibility = $this->visibilityResolver->resolve(
            $questions,
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
        );

        $reportSections = [];

        foreach ($targets as $target) {
            $question = $this->findQuestion($sections, $target['question_key']);

            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            $composite = VisibilityResolver::compositeKey(
                $target['question_key'],
                $target['section_instance_key'],
            );
            $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];

            if (! $state['visible']) {
                continue;
            }

            $section = $sectionsByQuestionKey[$question->key] ?? null;

            if ($section === null) {
                continue;
            }

            $sectionTitle = $section->title;
            $instanceLabel = $target['section_instance_key'];
            $bucketKey = $section->key.'|'.($instanceLabel ?? '');

            if (! isset($reportSections[$bucketKey])) {
                $reportSections[$bucketKey] = [
                    'title' => $sectionTitle,
                    'instance_label' => $instanceLabel,
                    'questions' => [],
                ];
            }

            $value = $answers[$composite] ?? null;
            $reportSections[$bucketKey]['questions'][] = [
                'label' => $question->label,
                'display' => $this->formatValue($question, $value),
                'is_photo' => $question->type === QuestionType::Photo,
            ];
        }

        return array_values($reportSections);
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private function buildAnswerMap(Intake $intake): array
    {
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
    private function buildTargets(Collection $sections, array $answers, array $questionTypes): array
    {
        $targets = [];

        foreach ($sections as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatInstanceCount($section, $answers, $questionTypes);

                for ($index = 1; $index <= $count; $index++) {
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

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function formatValue(IntakeQuestion $question, ?array $value): string
    {
        if (! $this->answerValueReader->isFilled($value, $question->type)) {
            return '—';
        }

        return match ($question->type) {
            QuestionType::ShortText, QuestionType::LongText => (string) ($value['text'] ?? ''),
            QuestionType::Number => (string) ($value['number'] ?? ''),
            QuestionType::SingleChoice => $this->optionLabel($question, (string) ($value['value'] ?? '')),
            QuestionType::MultiChoice => implode(', ', array_map(
                fn (mixed $v): string => $this->optionLabel($question, (string) $v),
                is_array($value['values'] ?? null) ? array_values($value['values']) : [],
            )),
            QuestionType::Boolean => ($value['bool'] ?? false) ? 'Ja' : 'Nee',
            QuestionType::Photo => count($value['upload_ids'] ?? []).' foto(s)',
        };
    }

    private function optionLabel(IntakeQuestion $question, string $optionValue): string
    {
        $question->loadMissing('options');

        foreach ($question->options as $option) {
            if ($option->value === $optionValue) {
                return $option->label;
            }
        }

        return $optionValue;
    }
}
