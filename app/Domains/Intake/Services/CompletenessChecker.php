<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\TechnicalDecisionKeys;
use App\Enums\QuestionType;
use Illuminate\Support\Str;

final class CompletenessChecker
{
    public function __construct(
        private readonly ProgressCalculator $progressCalculator,
        private readonly AnswerValueReader $answerValueReader,
    ) {}

    /**
     * @return array{
     *     is_complete: bool,
     *     missing: list<array{question_key: string, section_instance_key: string|null, reason: string, label: string, instance_label: string|null}>,
     *     attention_points: list<array{code: string, label: string}>
     * }
     */
    public function check(Intake $intake, IntakeTemplateVersion $version): array
    {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules']);

        $progress = $this->progressCalculator->calculate($intake, $version);
        $missing = [];

        foreach ($progress['missing_required'] as $item) {
            $question = $this->findQuestion($version, $item['question_key']);
            $section = $this->findSectionForQuestion($version, $item['question_key']);
            $reason = $question !== null && $question->type === QuestionType::Photo
                ? 'required_photo'
                : 'required_answer';

            $missing[] = [
                'question_key' => $item['question_key'],
                'section_instance_key' => $item['section_instance_key'],
                'reason' => $reason,
                'label' => $question !== null ? $question->label : $item['question_key'],
                'instance_label' => $this->instanceLabel($section, $item['section_instance_key']),
            ];
        }

        return [
            'is_complete' => $missing === [],
            'missing' => $missing,
            'attention_points' => $this->attentionPoints($intake, $version),
        ];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    private function attentionPoints(Intake $intake, IntakeTemplateVersion $version): array
    {
        $intake->loadMissing(['answers']);
        $points = [];

        $indoorUnitCount = $intake->answers
            ->first(static fn ($answer): bool => $answer->question_key === 'indoor_unit_count'
                && $answer->section_instance_key === null);
        $indoorUnitCountValue = is_array($indoorUnitCount?->value)
            ? ($indoorUnitCount->value['number'] ?? null)
            : null;

        if (is_numeric($indoorUnitCountValue) && (int) $indoorUnitCountValue > 1) {
            $count = (int) $indoorUnitCountValue;
            $points[] = [
                'code' => 'review_split_configuration',
                'label' => "Beoordeel voor {$count} ruimtes: één multi-split of meerdere single-splits.",
            ];
        }

        $points = [
            ...$points,
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'free_group_known',
                'electrical_provision_open',
                'Open technisch punt: stroomvoorziening / vrije groep nog te beoordelen',
                static function (IntakeAnswer $answer): ?array {
                    $value = is_array($answer->value) ? ($answer->value['value'] ?? null) : null;

                    if ($value === 'no') {
                        return [
                            'code' => 'no_free_group',
                            'label' => 'Geen vrije groep bekend',
                        ];
                    }

                    if ($value === 'unknown') {
                        return [
                            'code' => 'free_group_unknown',
                            'label' => 'Onbekend of er een vrije groep beschikbaar is',
                        ];
                    }

                    return null;
                },
            ),
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'natural_fall_possible',
                'condensate_pump_open',
                'Open technisch punt: condenspomp of natuurlijk afschot nog te bepalen',
                static function (IntakeAnswer $answer): ?array {
                    $value = is_array($answer->value) ? ($answer->value['bool'] ?? null) : null;

                    if ($value === false) {
                        return [
                            'code' => 'condensate_pump_likely',
                            'label' => 'Natuurlijk afschot waarschijnlijk niet mogelijk — pomp mogelijk nodig',
                        ];
                    }

                    return null;
                },
            ),
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'pipe_route_description',
                'pipe_route_open',
                'Open technisch punt: leidingroute nog te beoordelen',
                static function (IntakeAnswer $answer): ?array {
                    $value = is_array($answer->value) ? ($answer->value['value'] ?? null) : null;

                    if ($value === 'unknown') {
                        return [
                            'code' => 'pipe_route_open',
                            'label' => 'Open technisch punt: leidingroute nog te beoordelen',
                        ];
                    }

                    return null;
                },
            ),
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'drillings_needed',
                'drillings_open',
                'Open technisch punt: doorboringen door muren/vloeren nog te beoordelen',
                static fn (): ?array => null,
            ),
        ];

        return $points;
    }

    /**
     * AI-/klantwaarden zijn voorstellen: het open punt blijft staan tot de installateur beslist.
     *
     * @param  callable(IntakeAnswer): ?array{code: string, label: string}  $installerOutcome
     * @return list<array{code: string, label: string}>
     */
    private function technicalDecisionAttention(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
        string $openCode,
        string $openLabel,
        callable $installerOutcome,
    ): array {
        $answer = $intake->answers
            ->first(static fn ($row): bool => $row->question_key === $questionKey
                && $row->section_instance_key === null);

        $question = $this->findQuestion($version, $questionKey);
        $type = $question instanceof IntakeQuestion ? $question->type : QuestionType::SingleChoice;
        $filled = $answer instanceof IntakeAnswer
            && $this->answerValueReader->isFilled(
                is_array($answer->value) ? $answer->value : null,
                $type,
            );

        if (! $filled) {
            return [[
                'code' => $openCode,
                'label' => $openLabel,
            ]];
        }

        /** @var IntakeAnswer $answer */
        $source = $answer->prefill_source;

        if (TechnicalDecisionKeys::isInstallerPrefillSource($source)) {
            $extra = $installerOutcome($answer);

            return $extra === null ? [] : [$extra];
        }

        $display = $this->formatDecisionValue($answer, $question);

        if (TechnicalDecisionKeys::isAiPrefillSource($source)) {
            $photoRef = $this->relatedPhotoReference($intake, $questionKey);
            $provenance = $photoRef !== null
                ? "bron: {$source} · foto: {$photoRef}"
                : "bron: {$source}";

            return [[
                'code' => $openCode,
                'label' => "AI-voorstel: {$display}, nog te beoordelen ({$provenance})",
            ]];
        }

        // Klant- of andere bron: nog geen installateursbesluit.
        return [[
            'code' => $openCode,
            'label' => $openLabel,
        ]];
    }

    /**
     * Gerelateerde fotovraag voor herkomst in open-puntlabels (AI-voorstel + bron/foto).
     */
    private function relatedPhotoReference(Intake $intake, string $questionKey): ?string
    {
        $photoKey = match ($questionKey) {
            'natural_fall_possible' => 'drain_photo',
            'pipe_route_description', 'pipe_distance_indication', 'drillings_needed' => 'pipe_route_photos',
            'free_group_known' => 'fusebox_photo',
            default => null,
        };

        if ($photoKey === null) {
            return null;
        }

        $photoAnswer = $intake->answers
            ->first(static fn ($row): bool => $row->question_key === $photoKey
                && $row->section_instance_key === null);

        if (! $photoAnswer instanceof IntakeAnswer || ! is_array($photoAnswer->value)) {
            return null;
        }

        $uploadIds = $photoAnswer->value['upload_ids'] ?? null;
        if (! is_array($uploadIds) || $uploadIds === []) {
            return null;
        }

        return $photoKey.'#'.implode(',', array_map('strval', $uploadIds));
    }

    private function formatDecisionValue(IntakeAnswer $answer, ?IntakeQuestion $question): string
    {
        $value = is_array($answer->value) ? $answer->value : [];

        if (array_key_exists('bool', $value)) {
            return $value['bool'] === true ? 'ja' : ($value['bool'] === false ? 'nee' : 'onbekend');
        }

        $choice = $value['value'] ?? null;
        if (! is_string($choice) || $choice === '') {
            return 'onbekend';
        }

        if ($question !== null) {
            $question->loadMissing('options');
            $label = $question->options->firstWhere('value', $choice)?->label;
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return match ($choice) {
            'yes' => 'ja',
            'no' => 'nee',
            'unknown' => 'weet ik niet',
            default => $choice,
        };
    }

    private function findQuestion(IntakeTemplateVersion $version, string $questionKey): ?IntakeQuestion
    {
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $question;
                }
            }
        }

        return null;
    }

    private function findSectionForQuestion(IntakeTemplateVersion $version, string $questionKey): ?IntakeSection
    {
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $section;
                }
            }
        }

        return null;
    }

    /**
     * Same readable pattern as IntakeStepBuilder: "Ruimtes 2" instead of "room-2".
     */
    private function instanceLabel(?IntakeSection $section, ?string $sectionInstanceKey): ?string
    {
        if ($sectionInstanceKey === null || $sectionInstanceKey === '') {
            return null;
        }

        if ($section instanceof IntakeSection) {
            return $section->title.' '.Str::afterLast($sectionInstanceKey, '-');
        }

        return $sectionInstanceKey;
    }
}
