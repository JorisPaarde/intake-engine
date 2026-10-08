<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;

/**
 * Antwoorden van de klant voor overzicht/werkplek — gewone taal, per ruimte of onderdeel.
 *
 * Alleen antwoorden zonder eigen veld: maten, kamernaam, type, verdieping en
 * toestemming horen elders (ruimtekaart / stroom 3).
 */
final class CustomerAnswerBlocks
{
    /** @var list<string> */
    private const EXCLUDED_KEYS = [
        'room_length_m',
        'room_width_m',
        'room_area_m2',
        'ceiling_height_m',
        'room_name',
        'room_type',
        'use_type',
        'floor_level',
        'privacy_consent',
        'truth_confirmation',
    ];

    /**
     * @return list<array{
     *     heading: string,
     *     items: list<array{label: string, value: string}>
     * }>
     */
    public static function forIntake(Intake $intake): array
    {
        $intake->loadMissing([
            'answers',
            'aircoRooms',
            'templateVersion.sections.questions.options',
        ]);
        $version = $intake->templateVersion;
        if ($version === null) {
            return [];
        }

        /** @var Collection<int, IntakeSection> $sections */
        $sections = $version->sections->sortBy('sort_order')->values();

        /** @var Collection<string, IntakeQuestion> $questions */
        $questions = $sections
            ->flatMap(static fn (IntakeSection $section) => $section->questions)
            ->keyBy('key');

        /** @var Collection<string, IntakeSection> $sectionByQuestionKey */
        $sectionByQuestionKey = collect();
        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                $sectionByQuestionKey->put($question->key, $section);
            }
        }

        $roomsByInstance = $intake->aircoRooms
            ->filter(static fn (AircoRoom $room): bool => str_starts_with($room->key, 'room-'))
            ->sortBy('sort_order')
            ->keyBy('key');

        /** @var array<string, array{heading: string, sort: float, items: list<array{label: string, value: string}>}> $grouped */
        $grouped = [];

        foreach ($intake->answers as $answer) {
            if (in_array($answer->question_key, self::EXCLUDED_KEYS, true)) {
                continue;
            }

            // Alleen echte klantantwoorden (geen prefill, ook niet stated-from-request).
            if ($answer->prefill_source !== null) {
                continue;
            }

            $question = $questions->get($answer->question_key);
            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                continue;
            }

            $display = self::displayValue($question, $answer);
            if ($display === null) {
                continue;
            }

            $section = $sectionByQuestionKey->get($answer->question_key);
            $sectionSort = $section instanceof IntakeSection ? (float) $section->sort_order : 9999.0;

            $instanceKey = $answer->section_instance_key;
            if (is_string($instanceKey) && $instanceKey !== '') {
                $room = $roomsByInstance->get($instanceKey);
                $heading = $room instanceof AircoRoom
                    ? $room->name
                    : $instanceKey;
                $roomSort = $room instanceof AircoRoom ? (float) $room->sort_order : 0.0;
                $groupKey = 'room:'.$instanceKey;
                $sort = $sectionSort + ($roomSort / 1000.0);
            } else {
                if ($section instanceof IntakeSection) {
                    $heading = $section->title !== '' ? $section->title : $section->key;
                    $groupKey = 'section:'.$section->key;
                } else {
                    $heading = $answer->question_key;
                    $groupKey = 'section:'.$heading;
                }
                $sort = $sectionSort;
            }

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'heading' => $heading,
                    'sort' => $sort,
                    'items' => [],
                ];
            }

            $grouped[$groupKey]['items'][] = [
                'label' => $question->label,
                'value' => $display,
            ];
        }

        uasort(
            $grouped,
            static fn (array $a, array $b): int => $a['sort'] <=> $b['sort'],
        );

        $blocks = [];
        foreach ($grouped as $group) {
            if ($group['items'] === []) {
                continue;
            }
            $blocks[] = [
                'heading' => $group['heading'],
                'items' => $group['items'],
            ];
        }

        return $blocks;
    }

    /**
     * Ruimtekaart-/overzichtlabel — één bron voor werkplek en overzicht.
     *
     * - vloerconflict → "Controleer maten…"
     * - L×B of trusted m² → "3,5 × 3,0 m (10,5 m²) · H 2,6 m" (+ " · van klant" bij customer)
     * - untrusted m² → "14,0 m² — nog controleren"
     * - leeg / alleen deels → null (views: "Maten nog leeg" / "Maten deels ingevuld")
     *
     * @param  array<string, float|string|null>|null  $dimensions
     */
    public static function roomDimensionsCaption(?array $dimensions): ?string
    {
        $measures = RoomDimensions::from($dimensions);
        if (! $measures->hasAnyMeasure()) {
            return null;
        }

        if ($measures->hasFloorAreaConflict()) {
            return 'Controleer maten: L×B en m² komen niet overeen';
        }

        $parts = [];
        if ($measures->hasLengthAndWidth()) {
            $parts[] = number_format((float) $measures->lengthM(), 1, ',', '.')
                .' × '
                .number_format((float) $measures->widthM(), 1, ',', '.')
                .' m';
            $computed = $measures->areaFromLengthWidth();
            if ($computed !== null) {
                $parts[] = '('.number_format($computed, 1, ',', '.').' m²)';
            }
        } elseif ($measures->hasTrustedAreaM2()) {
            $parts[] = number_format((float) $measures->declaredAreaM2(), 1, ',', '.').' m²';
        } elseif ($measures->hasUntrustedAreaM2()) {
            return number_format((float) $measures->declaredAreaM2(), 1, ',', '.').' m² — nog controleren';
        }

        if ($parts === []) {
            return null;
        }

        if ($measures->hasHeight()) {
            $parts[] = '· H '.number_format((float) $measures->heightM(), 1, ',', '.').' m';
        }

        $caption = implode(' ', $parts);
        $source = is_array($dimensions) ? ($dimensions['dimensions_source'] ?? null) : null;

        if ($source === 'customer') {
            return $caption.' · van klant';
        }

        // Installateur / AI / request-text: maten zonder bronlabel.
        return $caption;
    }

    private static function displayValue(IntakeQuestion $question, IntakeAnswer $answer): ?string
    {
        $value = $answer->value;
        if (! is_array($value)) {
            return null;
        }

        return match ($question->type) {
            QuestionType::SingleChoice => self::choiceLabel($question, $value['value'] ?? null),
            QuestionType::MultiChoice => self::multiChoiceLabels($question, $value['values'] ?? null),
            QuestionType::Boolean => isset($value['bool'])
                ? (((bool) $value['bool']) ? 'Ja' : 'Nee')
                : null,
            QuestionType::Number => isset($value['number']) && is_numeric($value['number'])
                ? (string) $value['number']
                : null,
            QuestionType::ShortText, QuestionType::LongText => self::textValue($value),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function textValue(array $value): ?string
    {
        $text = $value['text'] ?? $value['value'] ?? null;
        if (! is_string($text)) {
            return null;
        }
        $trimmed = trim($text);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function choiceLabel(IntakeQuestion $question, mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $option = $question->options->firstWhere('value', $raw);

        return is_string($option?->label) && $option->label !== ''
            ? $option->label
            : $raw;
    }

    private static function multiChoiceLabels(IntakeQuestion $question, mixed $raw): ?string
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $labels = [];
        foreach ($raw as $item) {
            $label = self::choiceLabel($question, $item);
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels !== [] ? implode(', ', $labels) : null;
    }
}
