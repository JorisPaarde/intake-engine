<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\MustAcceptQuestions;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\TechnicalProposalCopy;
use App\Domains\Intake\Support\UploadSupersessionResolver;
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

        $intake->loadMissing('answers');

        foreach ($progress['missing_required'] as $item) {
            $question = $this->findQuestion($version, $item['question_key']);
            $section = $this->findSectionForQuestion($version, $item['question_key']);
            $reason = $question !== null && $question->type === QuestionType::Photo
                ? 'required_photo'
                : 'required_answer';

            if ($question !== null && MustAcceptQuestions::requiresAcceptance($question)) {
                $answer = $intake->answers->first(
                    static fn (IntakeAnswer $row): bool => $row->question_key === $item['question_key']
                        && $row->section_instance_key === $item['section_instance_key'],
                );
                $value = $answer instanceof IntakeAnswer && is_array($answer->value)
                    ? $answer->value
                    : null;
                $prefillSource = $answer instanceof IntakeAnswer ? $answer->prefill_source : null;
                if ($this->answerValueReader->isFilled($value, QuestionType::Boolean)
                    && ! MustAcceptQuestions::isAccepted($value, $prefillSource)) {
                    $reason = 'must_accept';
                }
            }

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
        $intake->loadMissing(['answers', 'uploads', 'aircoRooms', 'followUpRounds.items.uploads', 'contributionTasks', 'dossierSubjects.records']);
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
            ...$this->photoContinueAnywayPoints($intake, $version),
        ];

        return [
            ...$points,
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'free_group_known',
                'electrical_provision_open',
                'Open technisch punt: stroomvoorziening / vrije groep nog te beoordelen',
            ),
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'natural_fall_possible',
                'condensate_pump_open',
                'Open technisch punt: condenspomp of natuurlijk afschot nog te bepalen',
            ),
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'pipe_route_description',
                'pipe_route_open',
                'Open technisch punt: leidingroute nog te beoordelen',
            ),
            ...$this->technicalDecisionAttention(
                $intake,
                $version,
                'drillings_needed',
                'drillings_open',
                'Open technisch punt: doorboringen door muren/vloeren nog te beoordelen',
            ),
        ];
    }

    /**
     * Eén systeempunt per fotovraag + plek (niet per upload). Oude codes
     * `photo_subject_mismatch_{id}` verdwijnen bij de volgende afronding/sync;
     * bestaande opnames worden niet gemigreerd.
     *
     * @return list<array{code: string, label: string}>
     */
    private function photoContinueAnywayPoints(Intake $intake, IntakeTemplateVersion $version): array
    {
        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);

        /** @var array<string, list<IntakeUpload>> $byPlace */
        $byPlace = [];
        /** @var array<string, bool> $hasOverride */
        $hasOverride = [];

        foreach ($intake->uploads as $upload) {
            if ($upload->question_key === 'installer_evidence'
                || $upload->intake_follow_up_item_id !== null) {
                continue;
            }

            $info = $supersessions[(int) $upload->id] ?? null;
            if (is_array($info) && $info['superseded'] === true) {
                continue;
            }

            $groupKey = $upload->question_key.'|'.(string) ($upload->section_instance_key ?? '');
            $byPlace[$groupKey] ??= [];
            $byPlace[$groupKey][] = $upload;

            $assessment = $upload->contentAssessment();
            if ($assessment instanceof PhotoContentAssessment && $assessment->customerAcceptedOverride()) {
                $hasOverride[$groupKey] = true;
            }
        }

        $points = [];

        // Ruimtes in natuurlijke volgorde, daarna gebieden (niet-ruimte).
        uksort($byPlace, static function (string $a, string $b): int {
            [$aKey, $aInstance] = array_pad(explode('|', $a, 2), 2, '');
            [$bKey, $bInstance] = array_pad(explode('|', $b, 2), 2, '');
            $aIsRoom = str_starts_with((string) $aInstance, 'room-');
            $bIsRoom = str_starts_with((string) $bInstance, 'room-');
            if ($aIsRoom !== $bIsRoom) {
                return $aIsRoom ? -1 : 1;
            }
            $instanceCmp = strnatcmp((string) $aInstance, (string) $bInstance);
            if ($instanceCmp !== 0) {
                return $instanceCmp;
            }

            return strcmp((string) $aKey, (string) $bKey);
        });

        foreach ($byPlace as $groupKey => $uploads) {
            if (! ($hasOverride[$groupKey] ?? false)) {
                continue;
            }

            [$questionKey, $instanceRaw] = array_pad(explode('|', $groupKey, 2), 2, '');
            $sectionInstanceKey = $instanceRaw === '' ? null : $instanceRaw;

            $points[] = PhotoContinueAnywayAttention::buildPoint(
                $intake,
                $version,
                (string) $questionKey,
                $sectionInstanceKey,
                $uploads,
            );
        }

        return $points;
    }

    /**
     * Technische *_open-punten blijven open als installateurs-to-do (show/rapport).
     * AI- of klantwaarden zijn context, geen afhandeling — zie BL-117.
     *
     * @return list<array{code: string, label: string}>
     */
    private function technicalDecisionAttention(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
        string $openCode,
        string $openLabel,
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
        $display = $this->formatDecisionValue($answer, $question);
        $source = $answer->prefill_source;
        $fieldLabel = is_string($question?->label) && trim($question->label) !== ''
            ? trim($question->label)
            : TechnicalProposalCopy::fallbackFieldLabel($questionKey);

        if (PrefillSources::isProposedAi($source)) {
            $photoSource = $this->relatedPhotoSource($intake, $version, $questionKey);
            $sourceLabel = $photoSource
                ?? PrefillSources::installerSourceLabel($source);
            $confidence = match (true) {
                PrefillSources::isSuggestion($source) => 'middel',
                PrefillSources::isStrongAi($source) => 'hoog',
                default => 'middel',
            };
            $uncertainty = TechnicalProposalCopy::uncertainty($intake, $questionKey, $confidence);

            return [[
                'code' => $openCode,
                'label' => sprintf(
                    'AI-voorstel · %s: %s · %s · bron: %s · zekerheid: %s',
                    $fieldLabel,
                    $display,
                    $uncertainty,
                    $sourceLabel,
                    $confidence,
                ),
            ]];
        }

        // Klantantwoord op gepinde intake (of andere niet-AI-bron): blijft open.
        if ($source === null) {
            $uncertainty = TechnicalProposalCopy::uncertainty($intake, $questionKey, 'middel');

            return [[
                'code' => $openCode,
                'label' => "{$fieldLabel}: {$display} · {$uncertainty}",
            ]];
        }

        return [[
            'code' => $openCode,
            'label' => $openLabel,
        ]];
    }

    /**
     * Bronregel voor AI-open-puntlabels: foto met bestandsnaam + vraaglabel, geen keys.
     */
    private function relatedPhotoSource(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
    ): ?string {
        $photoKey = match ($questionKey) {
            'natural_fall_possible' => 'drain_photo',
            'pipe_route_description', 'drillings_needed' => 'pipe_route_photos',
            'free_group_known' => 'fusebox_photo',
            default => null,
        };

        if ($photoKey === null) {
            return null;
        }

        $photoQuestion = $this->findQuestion($version, $photoKey);
        $photoQuestionLabel = is_string($photoQuestion?->label) && trim($photoQuestion->label) !== ''
            ? trim($photoQuestion->label)
            : null;

        $photoAnswer = $intake->answers
            ->first(static fn ($row): bool => $row->question_key === $photoKey
                && $row->section_instance_key === null);

        $filename = null;
        if ($photoAnswer instanceof IntakeAnswer && is_array($photoAnswer->value)) {
            $uploadIds = $photoAnswer->value['upload_ids'] ?? null;
            if (is_array($uploadIds) && $uploadIds !== []) {
                $firstId = $uploadIds[0] ?? null;
                if (is_numeric($firstId)) {
                    $upload = $intake->uploads->first(
                        static fn ($row): bool => (int) $row->id === (int) $firstId,
                    );
                    if (! $upload instanceof IntakeUpload) {
                        $upload = IntakeUpload::query()->find((int) $firstId);
                    }
                    if ($upload instanceof IntakeUpload && $upload->original_filename !== '') {
                        $filename = $upload->original_filename;
                    }
                }
            }
        }

        if ($filename !== null && $photoQuestionLabel !== null) {
            return "foto «{$filename}» ({$photoQuestionLabel})";
        }
        if ($filename !== null) {
            return "foto «{$filename}»";
        }

        $place = $this->relatedPhotoPlace($intake, $version, $questionKey);
        if ($place !== null) {
            return "foto bij {$place}";
        }

        return PrefillSources::installerSourceLabel(PrefillSources::AI_PHOTO);
    }

    /**
     * Leesbare fotoplek voor AI-open-puntlabels (sectietitel, geen keys/upload-IDs).
     */
    private function relatedPhotoPlace(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
    ): ?string {
        $photoKey = match ($questionKey) {
            'natural_fall_possible' => 'drain_photo',
            'pipe_route_description', 'drillings_needed' => 'pipe_route_photos',
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

        $section = $this->findSectionForQuestion($version, $photoKey);
        if ($section instanceof IntakeSection && $section->title !== '') {
            return $section->title;
        }

        return null;
    }

    private function formatDecisionValue(IntakeAnswer $answer, ?IntakeQuestion $question): string
    {
        $value = is_array($answer->value) ? $answer->value : [];

        if (array_key_exists('bool', $value)) {
            $raw = $value['bool'] === true ? 'Ja' : ($value['bool'] === false ? 'Nee' : 'Onbekend');

            return $raw;
        }

        $choice = $value['value'] ?? null;
        if (! is_string($choice) || $choice === '') {
            return 'Onbekend';
        }

        if ($question !== null) {
            $question->loadMissing('options');
            $label = $question->options->firstWhere('value', $choice)?->label;
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        // Nooit Engelse enum-keys naar klant of dossierlabels lekken.
        return 'Onbekend';
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
