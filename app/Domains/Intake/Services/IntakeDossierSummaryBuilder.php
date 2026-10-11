<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\RoomDimensions;

final class IntakeDossierSummaryBuilder
{
    public function build(Intake $intake, IntakeTemplateVersion $version): string
    {
        $version->loadMissing('sections.questions.options');
        $intake->loadMissing(['answers', 'aircoRooms']);

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
        $fromAircoRooms = $this->aircoRoomSummaries($intake);
        $fallbackRooms = $fromAircoRooms === []
            ? $this->roomTypeOptionLabels($intake, $questions['room_type'] ?? null)
            : [];

        if ($fromAircoRooms !== []) {
            $count = count($fromAircoRooms);
            $prefix = $count.' '.($count === 1 ? 'ruimte' : 'ruimtes');
            $sentences[] = $prefix.': '.implode('; ', $fromAircoRooms).'.';
        } elseif ($unitCount !== null || $fallbackRooms !== []) {
            $scope = $unitCount === null
                ? 'Opgegeven ruimtes'
                : 'Opgegeven omvang: '.$unitCount.' '.($unitCount === 1 ? 'ruimte' : 'ruimtes');

            if ($fallbackRooms !== []) {
                $scope .= ' voor '.implode(', ', $fallbackRooms);
            }

            $sentences[] = $scope.'.';
        }

        $outdoor = $this->choiceLabel($intake, $questions['outdoor_location'] ?? null);
        if ($outdoor !== null) {
            $sentences[] = 'Buitenunit: '.$outdoor.'.';
        }

        $planning = $this->choiceLabel($intake, $questions['desired_planning'] ?? null)
            ?? $this->textAnswer($intake, 'desired_planning');

        if ($planning !== null) {
            $sentences[] = 'Gewenste planning: '.$planning.'.';
        }

        return implode(' ', $sentences);
    }

    /**
     * @return list<string>
     */
    private function aircoRoomSummaries(Intake $intake): array
    {
        $rooms = $intake->aircoRooms
            ->sortBy(static fn (AircoRoom $room): int => (int) $room->sort_order)
            ->values();

        if ($rooms->isEmpty()) {
            return [];
        }

        $labels = [];

        foreach ($rooms as $room) {
            $name = trim($room->name);
            if ($name === '') {
                continue;
            }

            $area = RoomDimensions::from(is_array($room->dimensions) ? $room->dimensions : null)
                ->effectiveFloorAreaM2();

            if ($area !== null) {
                $labels[] = $name.' ('.$this->formatAreaM2($area).' m²)';
            } else {
                $labels[] = $name;
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function roomTypeOptionLabels(Intake $intake, ?IntakeQuestion $question): array
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

    private function formatAreaM2(float $area): string
    {
        $formatted = number_format($area, 1, ',', '.');

        return str_ends_with($formatted, ',0')
            ? substr($formatted, 0, -2)
            : $formatted;
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
