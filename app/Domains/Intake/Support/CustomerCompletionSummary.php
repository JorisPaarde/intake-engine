<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;

/**
 * Korte klantgerichte samenvatting op het bedankscherm (BL-166).
 * Geen interne keys/enums — alleen Nederlandse labels en veilige tellingen.
 */
final class CustomerCompletionSummary
{
    /**
     * @return array{
     *     response_within: string,
     *     address: string|null,
     *     room_count: int,
     *     photo_count: int,
     *     highlights: list<array{label: string, value: string}>
     * }
     */
    public static function forIntake(Intake $intake): array
    {
        $intake->loadMissing([
            'answers',
            'uploads',
            'aircoRooms',
            'templateVersion.sections.questions.options',
        ]);

        $responseWithin = trim((string) config(
            'intake.customer_completion.installer_response_within',
            'binnen 2 werkdagen',
        ));
        if ($responseWithin === '') {
            $responseWithin = 'binnen 2 werkdagen';
        }

        $address = trim($intake->fullAddress());
        $address = $address !== '' ? $address : null;

        $roomCount = $intake->aircoRooms
            ->filter(static fn ($room): bool => str_starts_with((string) $room->key, 'room-'))
            ->count();

        if ($roomCount === 0) {
            $countAnswer = $intake->answers->firstWhere('question_key', 'indoor_unit_count');
            $number = is_array($countAnswer?->value) ? ($countAnswer->value['number'] ?? null) : null;
            if (is_numeric($number) && (int) $number > 0) {
                $roomCount = (int) $number;
            }
        }

        $photoCount = $intake->uploads
            ->filter(static fn (IntakeUpload $upload): bool => $upload->purged_at === null)
            ->count();

        return [
            'response_within' => $responseWithin,
            'address' => $address,
            'room_count' => $roomCount,
            'photo_count' => $photoCount,
            'highlights' => self::highlights($intake),
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function highlights(Intake $intake): array
    {
        /** @var Collection<string, IntakeQuestion> $questions */
        $questions = $intake->templateVersion?->sections
            ->flatMap(static fn ($section) => $section->questions)
            ->keyBy('key') ?? collect();

        $keys = [
            'cooling_heating',
            'ownership',
            'landlord_permission',
            'outdoor_location',
            'noise_sensitive',
            'brand_preference',
        ];

        $lines = [];
        foreach ($keys as $key) {
            $answer = $intake->answers->first(
                static fn (IntakeAnswer $row): bool => $row->question_key === $key
                    && ($row->section_instance_key === null || $row->section_instance_key === ''),
            );
            if (! $answer instanceof IntakeAnswer) {
                continue;
            }

            $question = $questions->get($key);
            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            if (InternalCustomerQuestions::isInternalKey($key)
                || TechnicalDecisionKeys::contains($key)) {
                continue;
            }

            $value = self::displayValue($question, $answer);
            if ($value === null) {
                continue;
            }

            $lines[] = [
                'label' => self::highlightLabel($key, $question->label),
                'value' => $value,
            ];
        }

        return $lines;
    }

    private static function highlightLabel(string $key, string $fallback): string
    {
        return match ($key) {
            'cooling_heating' => 'Koelen / verwarmen',
            'ownership' => 'Koop of huur',
            'landlord_permission' => 'Toestemming verhuurder',
            'outdoor_location' => 'Plek buitenunit',
            'noise_sensitive' => 'Geluidsgevoelig',
            'brand_preference' => 'Merkvoorkeur',
            default => $fallback,
        };
    }

    private static function displayValue(IntakeQuestion $question, IntakeAnswer $answer): ?string
    {
        $raw = $answer->value;
        if (! is_array($raw)) {
            return null;
        }

        return match ($question->type) {
            QuestionType::Boolean => isset($raw['bool']) && is_bool($raw['bool'])
                ? ($raw['bool'] ? 'Ja' : 'Nee')
                : null,
            QuestionType::SingleChoice => self::optionLabel($question, is_string($raw['value'] ?? null) ? $raw['value'] : null),
            QuestionType::MultiChoice => self::multiLabels($question, is_array($raw['values'] ?? null) ? $raw['values'] : []),
            QuestionType::ShortText, QuestionType::LongText => self::nonEmptyText($raw['text'] ?? null),
            QuestionType::Number => is_numeric($raw['number'] ?? null) ? (string) $raw['number'] : null,
            default => null,
        };
    }

    private static function optionLabel(IntakeQuestion $question, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $label = $question->options->firstWhere('value', $value)?->label;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function multiLabels(IntakeQuestion $question, array $values): ?string
    {
        $labels = [];
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $label = self::optionLabel($question, $value);
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            return null;
        }

        return implode(', ', $labels);
    }

    private static function nonEmptyText(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
    }
}
