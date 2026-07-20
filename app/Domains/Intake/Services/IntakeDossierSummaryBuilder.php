<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;

final class IntakeDossierSummaryBuilder
{
    public function build(Intake $intake, IntakeTemplateVersion $version): string
    {
        $version->loadMissing('sections.questions.options');
        $intake->loadMissing('answers');

        $questions = [];

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                $questions[$question->key] = $question;
            }
        }

        $sentences = ['Airco-aanvraag voor '.$intake->fullAddress().'.'];
        $function = $this->choiceLabel($intake, $questions['cooling_heating'] ?? null);
        $buildingType = $this->choiceLabel($intake, $questions['building_type'] ?? null);

        if ($function !== null) {
            $sentences[] = 'Gewenste functie: '.$function.'.';
        }

        if ($buildingType !== null) {
            $sentences[] = 'Pandtype: '.$buildingType.'.';
        }

        $unitCount = $this->numberAnswer($intake, 'indoor_unit_count');
        $rooms = $this->roomLabels($intake, $questions['room_type'] ?? null);

        if ($unitCount !== null || $rooms !== []) {
            $scope = $unitCount === null
                ? 'Opgegeven ruimtes'
                : 'Opgegeven omvang: '.$unitCount.' '.($unitCount === 1 ? 'binnenunit' : 'binnenunits');

            if ($rooms !== []) {
                $scope .= ' voor '.implode(', ', $rooms);
            }

            $sentences[] = $scope.'.';
        }

        $planning = $this->textAnswer($intake, 'desired_planning');

        if ($planning !== null) {
            $sentences[] = 'Gewenste planning: '.$planning.'.';
        }

        return implode(' ', $sentences);
    }

    /**
     * @return list<string>
     */
    private function roomLabels(Intake $intake, ?IntakeQuestion $question): array
    {
        if ($question === null) {
            return [];
        }

        $counts = [];

        foreach ($intake->answers as $answer) {
            if ($answer->question_key !== $question->key || $answer->section_instance_key === null) {
                continue;
            }

            $value = $answer->value['value'] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $label = $question->options->firstWhere('value', $value)?->label;

            if (is_string($label) && $label !== '') {
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }

        $labels = [];

        foreach ($counts as $label => $count) {
            $labels[] = $count > 1 ? $count.'x '.$label : $label;
        }

        return $labels;
    }

    private function choiceLabel(Intake $intake, ?IntakeQuestion $question): ?string
    {
        if ($question === null) {
            return null;
        }

        $answer = $this->answer($intake, $question->key);
        $value = $answer?->value['value'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $label = $question->options->firstWhere('value', $value)?->label;

        return is_string($label) && $label !== '' ? $label : null;
    }

    private function numberAnswer(Intake $intake, string $questionKey): ?int
    {
        $value = $this->answer($intake, $questionKey)?->value['number'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function textAnswer(Intake $intake, string $questionKey): ?string
    {
        $value = $this->answer($intake, $questionKey)?->value['text'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function answer(Intake $intake, string $questionKey): ?IntakeAnswer
    {
        return $intake->answers->first(
            static fn (IntakeAnswer $answer): bool => $answer->question_key === $questionKey
                && $answer->section_instance_key === null,
        );
    }
}
