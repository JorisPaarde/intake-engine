<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;

/**
 * Antwoorden van de klant voor overzicht/werkplek — gewone taal, per ruimte of algemeen.
 *
 * Maten (L/B/H/m²) horen op de ruimtekaart; die keys blijven hier buiten.
 */
final class CustomerAnswerBlocks
{
    /** @var list<string> */
    private const DIMENSION_KEYS = [
        'room_length_m',
        'room_width_m',
        'room_area_m2',
        'ceiling_height_m',
    ];

    /**
     * @return list<array{
     *     heading: string,
     *     items: list<array{label: string, value: string}>
     * }>
     */
    public static function forIntake(Intake $intake): array
    {
        $intake->loadMissing(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']);
        $version = $intake->templateVersion;
        if ($version === null) {
            return [];
        }

        /** @var Collection<string, IntakeQuestion> $questions */
        $questions = $version->sections
            ->flatMap(static fn ($section) => $section->questions)
            ->keyBy('key');

        $roomsByInstance = $intake->aircoRooms
            ->filter(static fn (AircoRoom $room): bool => str_starts_with($room->key, 'room-'))
            ->keyBy('key');

        /** @var array<string, list<array{label: string, value: string}>> $grouped */
        $grouped = [];

        foreach ($intake->answers as $answer) {
            if (in_array($answer->question_key, self::DIMENSION_KEYS, true)) {
                continue;
            }

            // Alleen klantantwoorden (eigen invoer of bevestigd stated).
            $isCustomer = $answer->prefill_source === null
                || $answer->fact_provenance === FactProvenance::Stated->value;
            if (! $isCustomer) {
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

            $instanceKey = $answer->section_instance_key;
            $heading = 'Algemeen';
            if (is_string($instanceKey) && $instanceKey !== '') {
                $room = $roomsByInstance->get($instanceKey);
                $heading = $room instanceof AircoRoom
                    ? $room->name
                    : $instanceKey;
            }

            $grouped[$heading][] = [
                'label' => $question->label,
                'value' => $display,
            ];
        }

        $blocks = [];
        foreach ($grouped as $heading => $items) {
            if ($items === []) {
                continue;
            }
            $blocks[] = [
                'heading' => $heading,
                'items' => $items,
            ];
        }

        return $blocks;
    }

    /**
     * Ruimtekaart-label: "3,5 × 3 m (10,5 m²) · van klant" of null als leeg.
     *
     * @param  array<string, float|string|null>|null  $dimensions
     */
    public static function roomDimensionsCaption(?array $dimensions): ?string
    {
        $measures = RoomDimensions::from($dimensions);
        if (! $measures->hasAnyMeasure()) {
            return null;
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
        } elseif ($measures->declaredAreaM2() !== null) {
            $parts[] = number_format((float) $measures->declaredAreaM2(), 1, ',', '.').' m²';
        }

        if ($measures->hasHeight()) {
            $parts[] = 'H '.number_format((float) $measures->heightM(), 1, ',', '.').' m';
        }

        if ($parts === []) {
            return null;
        }

        $caption = implode(' ', $parts);
        $source = is_array($dimensions) ? ($dimensions['dimensions_source'] ?? null) : null;
        if ($source === 'installer') {
            return $caption.' · van installateur';
        }

        return $caption.' · van klant';
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
