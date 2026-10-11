<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Enums\QuestionType;

/**
 * On-screen customer drain observation for condensate route cards (N26 r1).
 * Not written into AI model input — rendering of a structured customer answer only.
 */
final class CondensateCustomerDrainNote
{
    public function present(Intake $intake): ?string
    {
        $intake->loadMissing(['answers', 'templateVersion.sections.questions.options']);

        $answer = $intake->answers->first(
            static fn (IntakeAnswer $row): bool => $row->question_key === 'drain_location'
                && $row->section_instance_key === null,
        );

        if (! $answer instanceof IntakeAnswer || $answer->prefill_source !== null) {
            return null;
        }

        $value = is_array($answer->value) ? ($answer->value['value'] ?? null) : null;
        if (! is_string($value) || $value === '' || $value === 'unknown') {
            return null;
        }

        $label = $this->optionLabel($intake, $value);
        if ($label === null) {
            return null;
        }

        $display = mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);

        return 'Volgens de klant: '.$display.'. Nog niet gecontroleerd.';
    }

    private function optionLabel(Intake $intake, string $value): ?string
    {
        $question = $intake->templateVersion?->sections
            ->flatMap(static fn ($section) => $section->questions)
            ->first(static fn (IntakeQuestion $question): bool => $question->key === 'drain_location');

        if (! $question instanceof IntakeQuestion || $question->type !== QuestionType::SingleChoice) {
            return null;
        }

        $option = $question->options->firstWhere('value', $value);

        return is_string($option?->label) && $option->label !== ''
            ? $option->label
            : null;
    }
}
