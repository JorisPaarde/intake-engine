<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Enums\QuestionType;

/**
 * On-screen customer drain observation for condensate route cards (N26/N38).
 * Not written into AI model input — rendering of a structured customer answer only.
 *
 * Single source for “Volgens de klant …” and the compact Antwoorden-van-de-klant block.
 */
final class CondensateCustomerDrainNote
{
    public function present(Intake $intake): ?string
    {
        $answer = $this->customerAnswer($intake);
        if (! $answer instanceof IntakeAnswer) {
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

    /**
     * Klant-eigen drain_location (prefill gewist / geen AI-voorzet).
     * Accepteert null of lege section_instance_key (N38: lege string miste de note).
     */
    public function customerAnswer(Intake $intake): ?IntakeAnswer
    {
        $intake->loadMissing(['answers', 'templateVersion.sections.questions.options']);

        $candidates = $intake->answers
            ->filter(static function (IntakeAnswer $row): bool {
                if ($row->question_key !== 'drain_location') {
                    return false;
                }

                $instance = $row->section_instance_key;

                return $instance === null || $instance === '';
            })
            ->filter(static function (IntakeAnswer $row): bool {
                // Alleen echte klantkeuze — geen foto-/tekstvoorzet.
                return $row->prefill_source === null || $row->prefill_source === '';
            })
            ->sortBy(static fn (IntakeAnswer $row): int => $row->section_instance_key === null ? 0 : 1)
            ->values();

        $answer = $candidates->first();

        return $answer instanceof IntakeAnswer ? $answer : null;
    }

    /**
     * Optielabel voor compacte antwoordenlijst (zelfde mapping als present()).
     */
    public function customerChoiceLabel(Intake $intake): ?string
    {
        $answer = $this->customerAnswer($intake);
        if (! $answer instanceof IntakeAnswer) {
            return null;
        }

        $value = is_array($answer->value) ? ($answer->value['value'] ?? null) : null;
        if (! is_string($value) || $value === '' || $value === 'unknown') {
            return null;
        }

        return $this->optionLabel($intake, $value);
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
