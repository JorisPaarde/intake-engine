<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;

/**
 * Klantpagina “Dit hebben we gevonden” (BL-174): wat we uit tekst, foto’s en openbare bronnen
 * haalden, in gewone taal. Geen interne keys, zekerheidspercentages of installateursnotities.
 *
 * @phpstan-type Finding array{key: string, label: string, value: string, source: string}
 * @phpstan-type FindingGroup array{title: string, items: list<Finding>}
 * @phpstan-type PhotoTile array{upload: IntakeUpload, caption: string}
 */
final class CustomerFindings
{
    /** Volgorde = weergave; alleen onderwerpen die een klant herkent. */
    private const WISH_KEYS = [
        'cooling_heating' => 'Koelen of verwarmen',
        'app_control_wanted' => 'Bedienen met een app',
        'outdoor_location' => 'Plek buitenunit',
        'noise_sensitive' => 'Stilte belangrijk',
        'desired_planning' => 'Wanneer',
        'brand_preference' => 'Merk',
        'ownership' => 'Koop of huur',
    ];

    /**
     * @return array{
     *     groups: list<FindingGroup>,
     *     photos: list<PhotoTile>,
     *     has_aerial: bool,
     *     pending: bool
     * }
     */
    public static function forIntake(Intake $intake): array
    {
        $intake->loadMissing([
            'answers',
            'uploads',
            'externalFacts',
            'templateVersion.sections.questions.options',
        ]);

        /** @var Collection<string, IntakeQuestion> $questions */
        $questions = $intake->templateVersion?->sections
            ->flatMap(static fn ($section) => $section->questions)
            ->keyBy('key') ?? collect();

        $groups = array_values(array_filter([
            ['title' => 'Je woning', 'items' => self::homeItems($intake)],
            ['title' => 'Je ruimtes', 'items' => self::roomItems($intake, $questions)],
            ['title' => 'Je wensen', 'items' => self::wishItems($intake, $questions)],
        ], static fn (array $group): bool => $group['items'] !== []));

        return [
            'groups' => $groups,
            'photos' => self::photos($intake),
            'has_aerial' => app(ExternalFactPresenter::class)->selectAerialFact($intake) !== null,
            'pending' => self::analysisPending($intake),
        ];
    }

    /**
     * @return list<Finding>
     */
    private static function homeItems(Intake $intake): array
    {
        $items = [];
        foreach ($intake->knownHousingFactLines() as $line) {
            $items[] = [
                'key' => 'home_'.strtolower($line['label']),
                'label' => $line['label'],
                'value' => $line['value'],
                'source' => $line['label'] === 'Energielabel' ? 'energielabel' : 'Kadaster',
            ];
        }

        $insulation = $intake->answers->first(
            static fn (IntakeAnswer $answer): bool => $answer->question_key === 'insulation_indication'
                && $answer->section_instance_key === null
                && $answer->prefill_source === 'epo',
        );
        $insulationLabel = match (is_array($insulation?->value) ? ($insulation->value['value'] ?? null) : null) {
            'good' => 'Goed',
            'average' => 'Gemiddeld',
            'poor' => 'Matig',
            default => null,
        };
        if ($insulationLabel !== null) {
            $items[] = [
                'key' => 'home_insulation',
                'label' => 'Isolatie (schatting)',
                'value' => $insulationLabel,
                'source' => 'energielabel',
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<string, IntakeQuestion>  $questions
     * @return list<Finding>
     */
    private static function roomItems(Intake $intake, Collection $questions): array
    {
        $byRoom = [];
        foreach ($intake->answers as $answer) {
            $instance = $answer->section_instance_key;
            if (is_string($instance) && preg_match('/^room-(\d+)$/', $instance, $matches) === 1) {
                $byRoom[(int) $matches[1]][$answer->question_key] = $answer;
            }
        }
        ksort($byRoom);

        $total = count($byRoom);
        $items = [];
        foreach ($byRoom as $index => $answers) {
            $parts = [];
            $sources = [];

            $type = self::displayAnswer($answers['room_type'] ?? null, $questions->get('room_type'));
            $name = self::displayAnswer($answers['room_name'] ?? null, $questions->get('room_name'));
            $parts[] = $name ?? $type ?? 'Ruimte';
            $sources[] = $answers['room_name'] ?? $answers['room_type'] ?? null;

            $area = $answers['room_area_m2'] ?? null;
            $areaNumber = is_array($area?->value) ? ($area->value['number'] ?? null) : null;
            if (is_numeric($areaNumber) && (float) $areaNumber > 0) {
                $parts[] = 'ongeveer '.self::formatNumber((float) $areaNumber).' m²';
                $sources[] = $area;
            } else {
                $size = self::displayAnswer($answers['room_size_indication'] ?? null, $questions->get('room_size_indication'));
                if ($size !== null) {
                    $parts[] = mb_strtolower($size);
                    $sources[] = $answers['room_size_indication'] ?? null;
                }
            }

            $side = self::displayAnswer($answers['room_facade_side'] ?? null, $questions->get('room_facade_side'));
            if ($side !== null) {
                $parts[] = mb_strtolower($side);
            }

            $items[] = [
                'key' => 'room_'.$index,
                'label' => $total > 1 ? 'Ruimte '.$index : 'Ruimte',
                'value' => implode(' · ', $parts),
                'source' => self::sourceLabel(array_values(array_filter($sources))),
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<string, IntakeQuestion>  $questions
     * @return list<Finding>
     */
    private static function wishItems(Intake $intake, Collection $questions): array
    {
        $items = [];
        foreach (self::WISH_KEYS as $key => $label) {
            $answer = $intake->answers->first(
                static fn (IntakeAnswer $row): bool => $row->question_key === $key && $row->section_instance_key === null,
            );
            $display = self::displayAnswer($answer, $questions->get($key));
            if ($answer === null || $display === null) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $label,
                'value' => $display,
                'source' => self::sourceLabel([$answer]),
            ];
        }

        // Eén plek voor alle ruimtes → één regel; anders laat de installateur het per ruimte zien.
        $locations = $intake->answers
            ->filter(static fn (IntakeAnswer $row): bool => $row->question_key === 'preferred_indoor_location')
            ->map(static fn (IntakeAnswer $row): ?string => self::displayAnswer($row, null))
            ->filter()
            ->unique()
            ->values();
        if ($locations->count() === 1) {
            $row = $intake->answers->firstWhere('question_key', 'preferred_indoor_location');
            $items[] = [
                'key' => 'preferred_indoor_location',
                'label' => 'Plek binnenunit',
                'value' => (string) $locations->first(),
                'source' => self::sourceLabel($row instanceof IntakeAnswer ? [$row] : []),
            ];
        }

        return $items;
    }

    /**
     * @return list<PhotoTile>
     */
    private static function photos(Intake $intake): array
    {
        $captions = [
            'room_photos' => 'Ruimte',
            'outdoor_location_photos' => 'Buitenunit',
            'fusebox_photo' => 'Meterkast',
        ];

        $tiles = [];
        foreach ($intake->uploads->sortBy('id') as $upload) {
            if (! isset($captions[$upload->question_key]) || $upload->purged_at !== null) {
                continue;
            }

            $caption = $captions[$upload->question_key];
            if ($upload->question_key === 'room_photos'
                && is_string($upload->section_instance_key)
                && preg_match('/^room-(\d+)$/', $upload->section_instance_key, $matches) === 1) {
                $caption .= ' '.$matches[1];
            }

            $tiles[] = ['upload' => $upload, 'caption' => $caption];
        }

        return $tiles;
    }

    /**
     * Foto-AI of tekst-AI is nog bezig: de pagina ververst zichzelf; versturen kan altijd.
     */
    private static function analysisPending(Intake $intake): bool
    {
        $photoPending = $intake->uploads->contains(
            static fn (IntakeUpload $upload): bool => $upload->purged_at === null
                && $upload->assessment_status === PhotoAssessmentStatus::Pending,
        );

        if ($photoPending) {
            return true;
        }

        return AiRun::query()
            ->where('intake_id', $intake->id)
            ->where('type', AiRunType::RequestIntent)
            ->where('status', AiRunStatus::Pending)
            ->where('created_at', '>=', now()->subMinutes(3))
            ->exists();
    }

    private static function displayAnswer(?IntakeAnswer $answer, ?IntakeQuestion $question): ?string
    {
        $raw = $answer?->value;
        if (! is_array($raw)) {
            return null;
        }

        $text = $raw['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $value = $raw['value'] ?? null;
        if (is_string($value) && $value !== '' && $value !== 'unknown') {
            $option = $question?->options->firstWhere('value', $value);

            return is_string($option?->label) && $option->label !== '' ? $option->label : null;
        }

        $values = $raw['values'] ?? null;
        if (is_array($values) && $values !== [] && $question instanceof IntakeQuestion) {
            $labels = collect($values)
                ->map(static fn ($v): ?string => $question->options->firstWhere('value', $v)?->label)
                ->filter()
                ->values()
                ->all();

            return $labels === [] ? null : implode(', ', $labels);
        }

        if (array_key_exists('bool', $raw) && is_bool($raw['bool']) && $question?->type === QuestionType::Boolean) {
            return $raw['bool'] ? 'Ja' : 'Nee';
        }

        $number = $raw['number'] ?? null;

        return is_numeric($number) ? self::formatNumber((float) $number) : null;
    }

    /**
     * @param  list<IntakeAnswer>  $answers
     */
    private static function sourceLabel(array $answers): string
    {
        $labels = [];
        foreach ($answers as $answer) {
            $source = $answer->prefill_source;
            $labels[] = match (true) {
                $source === null => 'door jou ingevuld',
                PrefillSources::isPhotoSuggestion($source) || $source === PrefillSources::AI_PHOTO => 'uit je foto',
                PrefillSources::isTextSuggestion($source) => 'uit je tekst (geschat)',
                default => 'uit je tekst',
            };
        }

        $labels = array_values(array_unique($labels));

        return $labels === [] ? 'uit je tekst' : implode(' en ', $labels);
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
