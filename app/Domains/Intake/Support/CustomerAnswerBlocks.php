<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Services\VisibilityResolver;
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
            'uploads',
            'aircoRooms',
            'templateVersion.sections.questions.options',
            'templateVersion.sections.questions.rules',
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

        /** @var array<string, QuestionType> $questionTypes */
        $questionTypes = [];
        /** @var array<string, IntakeSection> $sectionsByQuestionKey */
        $sectionsByQuestionKey = [];
        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
            }
        }

        $answerMap = self::buildAnswerMap($intake);
        $visibilityResolver = app(VisibilityResolver::class);

        $roomsByInstance = $intake->aircoRooms
            ->filter(static fn (AircoRoom $room): bool => str_starts_with($room->key, 'room-'))
            ->sortBy('sort_order')
            ->keyBy('key');

        /** @var array<string, array{heading: string, sort: float, items: list<array{label: string, value: string, sort: int}>}> $grouped */
        $grouped = [];

        foreach ($intake->answers as $answer) {
            if (in_array($answer->question_key, self::EXCLUDED_KEYS, true)) {
                continue;
            }

            if ($answer->prefill_source !== null && $answer->prefill_source !== '') {
                continue;
            }

            $question = $questions->get($answer->question_key);
            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            $section = $sectionsByQuestionKey[$answer->question_key] ?? null;
            if (! $section instanceof IntakeSection) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                continue;
            }

            // N38: lege string instance = survey-niveau (zelfde als null), niet een ontbrekende kamer.
            $instanceKey = $answer->section_instance_key;
            if ($instanceKey === '') {
                $instanceKey = null;
            }

            $visibility = $visibilityResolver->resolveQuestion(
                $question,
                $instanceKey,
                $answerMap,
                $questionTypes,
                $sectionsByQuestionKey,
                customerMode: true,
            );
            if (! $visibility['visible']) {
                continue;
            }

            $display = self::displayValue($question, $answer);
            if ($display === null) {
                continue;
            }

            $sectionSort = (float) $section->sort_order;

            if (is_string($instanceKey)) {
                $room = $roomsByInstance->get($instanceKey);
                if (! $room instanceof AircoRoom) {
                    continue;
                }
                $heading = $room->name;
                $groupKey = 'room:'.$instanceKey;
                $sort = $sectionSort + ((float) $room->sort_order / 1000.0);
            } else {
                $heading = $section->title !== '' ? $section->title : $section->key;
                $groupKey = 'section:'.$section->key;
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
                'sort' => (int) $question->sort_order,
            ];
        }

        // N38: garandeer condensafvoer-klantkeuze via dezelfde bron als routekaarten,
        // ook wanneer visibility/instance-lookup de rij eerder oversloeg.
        self::ensureDrainBlock($intake, $questions, $sectionsByQuestionKey, $grouped);

        uasort(
            $grouped,
            static fn (array $a, array $b): int => $a['sort'] <=> $b['sort'],
        );

        $blocks = [];
        foreach ($grouped as $group) {
            if ($group['items'] === []) {
                continue;
            }

            $items = $group['items'];
            usort(
                $items,
                static fn (array $a, array $b): int => $a['sort'] <=> $b['sort'],
            );

            $blocks[] = [
                'heading' => $group['heading'],
                'items' => array_map(
                    static fn (array $item): array => [
                        'label' => $item['label'],
                        'value' => $item['value'],
                    ],
                    $items,
                ),
            ];
        }

        return $blocks;
    }

    /**
     * Zelfde drain-klantkeuze als CondensateCustomerDrainNote in het compacte blok.
     *
     * @param  Collection<string, IntakeQuestion>  $questions
     * @param  array<string, IntakeSection>  $sectionsByQuestionKey
     * @param  array<string, array{heading: string, sort: float, items: list<array{label: string, value: string, sort: int}>}>  $grouped
     */
    private static function ensureDrainBlock(
        Intake $intake,
        Collection $questions,
        array $sectionsByQuestionKey,
        array &$grouped,
    ): void {
        $note = app(CondensateCustomerDrainNote::class);
        $label = $note->customerChoiceLabel($intake);
        $answer = $note->customerAnswer($intake);
        if ($label === null || ! $answer instanceof IntakeAnswer) {
            return;
        }

        $question = $questions->get('drain_location');
        $section = $sectionsByQuestionKey['drain_location'] ?? null;
        if (! $question instanceof IntakeQuestion || ! $section instanceof IntakeSection) {
            return;
        }

        $groupKey = 'section:'.$section->key;
        $questionLabel = $question->label;

        if (isset($grouped[$groupKey])) {
            foreach ($grouped[$groupKey]['items'] as $item) {
                if ($item['label'] === $questionLabel) {
                    return;
                }
            }
        } else {
            $grouped[$groupKey] = [
                'heading' => $section->title !== '' ? $section->title : $section->key,
                'sort' => (float) $section->sort_order,
                'items' => [],
            ];
        }

        $grouped[$groupKey]['items'][] = [
            'label' => $questionLabel,
            'value' => $label,
            'sort' => (int) $question->sort_order,
        ];
    }

    /**
     * Antwoordkaart voor VisibilityResolver (inclusief upload_ids voor filled-regels).
     *
     * @return array<string, array<string, mixed>|null>
     */
    private static function buildAnswerMap(Intake $intake): array
    {
        $answers = [];

        foreach ($intake->answers as $answer) {
            $instanceKey = $answer->section_instance_key;
            if ($instanceKey === '') {
                $instanceKey = null;
            }
            $key = VisibilityResolver::compositeKey(
                $answer->question_key,
                $instanceKey,
            );
            $answers[$key] = is_array($answer->value) ? $answer->value : null;
        }

        /** @var array<string, list<int>> $uploadIdsByKey */
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
     * Altijd een label voor werkplek én overzicht (één bron).
     *
     * @param  array<string, float|string|null>|null  $dimensions
     */
    public static function roomDimensionsLabel(?array $dimensions): string
    {
        $caption = self::roomDimensionsCaption($dimensions);
        if ($caption !== null) {
            return $caption;
        }

        $measures = RoomDimensions::from($dimensions);
        if ($measures->hasAnyMeasure()) {
            return 'Maten deels ingevuld';
        }

        return 'Maten nog leeg';
    }

    /**
     * Ruimtekaart-/overzichtlabel — meetwaarden of null bij leeg/deels.
     *
     * - vloerconflict → "Controleer maten…"
     * - L×B of trusted m² → "3,5 × 3 m (10,5 m²) · H 2,6 m" (+ " · van klant" bij customer)
     * - untrusted m² → "14 m² — nog controleren" (trailing ,0 weg)
     * - leeg / alleen deels → null (zie {@see roomDimensionsLabel()})
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
            $parts[] = self::formatMeasure((float) $measures->lengthM())
                .' × '
                .self::formatMeasure((float) $measures->widthM())
                .' m';
            $computed = $measures->areaFromLengthWidth();
            if ($computed !== null) {
                $parts[] = '('.self::formatMeasure($computed).' m²)';
            }
        } elseif ($measures->hasTrustedAreaM2()) {
            $parts[] = self::formatMeasure((float) $measures->declaredAreaM2()).' m²';
        } elseif ($measures->hasUntrustedAreaM2()) {
            return self::formatMeasure((float) $measures->declaredAreaM2()).' m² — nog controleren';
        }

        if ($parts === []) {
            return null;
        }

        if ($measures->hasHeight()) {
            $parts[] = '· H '.self::formatMeasure((float) $measures->heightM()).' m';
        }

        $caption = implode(' ', $parts);
        $source = is_array($dimensions) ? ($dimensions['dimensions_source'] ?? null) : null;

        if ($source === 'customer') {
            return $caption.' · van klant';
        }

        return $caption;
    }

    /** "3,5" blijft; "3,0" wordt "3". */
    private static function formatMeasure(float $value): string
    {
        $formatted = number_format($value, 1, ',', '.');

        return str_ends_with($formatted, ',0')
            ? substr($formatted, 0, -2)
            : $formatted;
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
