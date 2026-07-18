<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;

/**
 * Deterministic prefill for repeatable sections (BL-016).
 *
 * When a question is flagged `meta.prefill_from_previous` and the current repeatable
 * instance has no answer yet, this offers the nearest previous instance's answer as a
 * *voorzet* (suggestion) — never a stored answer. The caller shows it labelled and the
 * applicant confirms it by advancing. No LLM, no side effects.
 */
final class IntakePrefillResolver
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
    ) {}

    /**
     * @return array{value: array<string, mixed>, source_label: string}|null
     */
    public function suggestionFor(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
        ?string $sectionInstanceKey,
    ): ?array {
        if ($sectionInstanceKey === null || $sectionInstanceKey === '') {
            return null;
        }

        [$question, $section] = $this->locate($version, $questionKey);

        if ($question === null || $section === null || ! $section->is_repeatable) {
            return null;
        }

        $meta = $question->meta ?? [];

        if (($meta['prefill_from_previous'] ?? false) !== true) {
            return null;
        }

        $position = strrpos($sectionInstanceKey, '-');

        if ($position === false) {
            return null;
        }

        $prefix = substr($sectionInstanceKey, 0, $position);
        $index = (int) substr($sectionInstanceKey, $position + 1);

        if ($index <= 1) {
            return null;
        }

        $intake->loadMissing('answers');

        // Only suggest when the target instance is still empty — never override a real answer.
        $current = $this->findAnswer($intake, $questionKey, $sectionInstanceKey);
        if ($current !== null && $this->answerValueReader->isFilled($current->value, $question->type)) {
            return null;
        }

        // Nearest previous instance with a filled answer wins.
        for ($i = $index - 1; $i >= 1; $i--) {
            $previous = $this->findAnswer($intake, $questionKey, $prefix.'-'.$i);

            if ($previous !== null && $this->answerValueReader->isFilled($previous->value, $question->type)) {
                return [
                    'value' => $previous->value ?? [],
                    'source_label' => $section->title.' '.$i,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{0: IntakeQuestion|null, 1: IntakeSection|null}
     */
    private function locate(IntakeTemplateVersion $version, string $questionKey): array
    {
        $version->loadMissing(['sections.questions']);

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return [$question, $section];
                }
            }
        }

        return [null, null];
    }

    private function findAnswer(Intake $intake, string $questionKey, string $sectionInstanceKey): ?IntakeAnswer
    {
        foreach ($intake->answers as $answer) {
            if ($answer->question_key === $questionKey && $answer->section_instance_key === $sectionInstanceKey) {
                return $answer;
            }
        }

        return null;
    }
}
