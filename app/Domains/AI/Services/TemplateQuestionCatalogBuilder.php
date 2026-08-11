<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\QuestionType;

/**
 * Exporteert de fillable vraagenset van een gepinde templateversie voor AI-prefill (ADR-0013).
 */
final class TemplateQuestionCatalogBuilder
{
    /**
     * @return array{
     *     template_key: string,
     *     template_version: int,
     *     sections: list<array{
     *         key: string,
     *         title: string,
     *         is_repeatable: bool,
     *         repeat_count_question_key: string|null,
     *         questions: list<array<string, mixed>>
     *     }>
     * }
     */
    public function build(Intake $intake): array
    {
        $version = $intake->templateVersion()
            ->with(['template', 'sections.questions.options'])
            ->firstOrFail();

        return $this->fromVersion($version);
    }

    /**
     * @return array{
     *     template_key: string,
     *     template_version: int,
     *     sections: list<array{
     *         key: string,
     *         title: string,
     *         is_repeatable: bool,
     *         repeat_count_question_key: string|null,
     *         questions: list<array<string, mixed>>
     *     }>
     * }
     */
    public function fromVersion(IntakeTemplateVersion $version): array
    {
        $sections = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            $questions = [];

            foreach ($section->questions->sortBy('sort_order') as $question) {
                if ($question->type === QuestionType::Photo) {
                    continue;
                }

                if ($question->key === 'request_reason') {
                    continue;
                }

                $entry = [
                    'key' => $question->key,
                    'type' => $question->type->value,
                    'label' => $question->label,
                    'help_text' => $question->help_text,
                    'is_required' => (bool) $question->is_required,
                    'meta' => is_array($question->meta) ? $question->meta : [],
                ];

                if (in_array($question->type, [QuestionType::SingleChoice, QuestionType::MultiChoice], true)) {
                    $entry['options'] = $question->options
                        ->sortBy('sort_order')
                        ->values()
                        ->map(static fn ($option): array => [
                            'value' => $option->value,
                            'label' => $option->label,
                        ])
                        ->all();
                }

                $questions[] = $entry;
            }

            if ($questions === []) {
                continue;
            }

            $sections[] = [
                'key' => $section->key,
                'title' => $section->title,
                'is_repeatable' => (bool) $section->is_repeatable,
                'repeat_count_question_key' => $section->repeat_count_question_key,
                'questions' => $questions,
            ];
        }

        return [
            'template_key' => (string) $version->template?->key,
            'template_version' => (int) $version->version,
            'sections' => $sections,
        ];
    }
}
