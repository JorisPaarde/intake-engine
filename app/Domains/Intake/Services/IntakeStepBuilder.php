<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\KnownSummaryCatalog;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\RoomLabelResolver;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the customer wizard as one visible question per step (BL-018),
 * and a full question catalog with skip reasons for AI traces (BL-116).
 * Known-summary + force-show for prefilled edits: BL-118.
 *
 * @phpstan-type KnownSummaryItem array{
 *     question_key: string,
 *     section_instance_key: string|null,
 *     label: string,
 *     display_value: string,
 *     prefill_source: string
 * }
 * @phpstan-type IntakeStep array{
 *     key: string,
 *     section_key: string,
 *     section_instance_key: string|null,
 *     question_key: string,
 *     title: string,
 *     section_title: string,
 *     description: string|null,
 *     help_text: string|null,
 *     is_repeatable: bool,
 *     is_required: bool,
 *     kind?: 'question'|'known_summary',
 *     known_items?: list<KnownSummaryItem>
 * }
 * @phpstan-type CatalogRow array{
 *     question_key: string,
 *     section_instance_key: string|null,
 *     section_key: string,
 *     title: string,
 *     visible: bool,
 *     required: bool,
 *     reason: 'visible'|'prefilled'|'niet_relevant'|'intern'|'overgeslagen',
 *     prefill_source: string|null,
 *     answered: bool
 * }
 */
final class IntakeStepBuilder
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly VisibilityResolver $visibilityResolver,
    ) {}

    /**
     * @param  array<string, array<string, mixed>|null>  $liveAnswers  optional in-memory form answers for live visibility
     * @param  list<string>  $forceShowComposites  composites that must stay visible (known-summary “Wijzigen”)
     * @return list<IntakeStep>
     */
    public function build(
        Intake $intake,
        IntakeTemplateVersion $version,
        array $liveAnswers = [],
        array $forceShowComposites = [],
    ): array {
        $context = $this->buildContext($intake, $version, $liveAnswers);
        $steps = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $context['answers'], $context['questionTypes']);

                for ($i = 1; $i <= $count; $i++) {
                    $instanceKey = Str::singular($section->key).'-'.$i;
                    $this->appendVisibleQuestionSteps(
                        $steps,
                        $intake,
                        $section,
                        $instanceKey,
                        $context,
                        $forceShowComposites,
                    );
                }

                continue;
            }

            $this->appendVisibleQuestionSteps(
                $steps,
                $intake,
                $section,
                null,
                $context,
                $forceShowComposites,
            );
        }

        return $this->insertKnownSummaryStep($steps, $intake, $version, $context['answers'], $context['allQuestions']);
    }

    /**
     * Full question catalog for every section instance (visible + skipped) with reasons.
     *
     * @param  array<string, array<string, mixed>|null>  $liveAnswers
     * @return list<CatalogRow>
     */
    public function buildCatalog(Intake $intake, IntakeTemplateVersion $version, array $liveAnswers = []): array
    {
        $context = $this->buildContext($intake, $version, $liveAnswers);
        $rows = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $context['answers'], $context['questionTypes']);
                $count = max(1, $count);

                for ($i = 1; $i <= $count; $i++) {
                    $instanceKey = Str::singular($section->key).'-'.$i;
                    $this->appendCatalogRows($rows, $section, $instanceKey, $context);
                }

                continue;
            }

            $this->appendCatalogRows($rows, $section, null, $context);
        }

        return $rows;
    }

    /**
     * First catalog row that is visible and unanswered (wizard remaining work).
     *
     * @param  list<CatalogRow>  $catalog
     * @return CatalogRow|null
     */
    public function nextUnansweredVisible(array $catalog): ?array
    {
        foreach ($catalog as $row) {
            if ($row['visible'] === true && $row['answered'] !== true) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<CatalogRow>  $catalog
     */
    public function remainingUnansweredVisibleCount(array $catalog): int
    {
        $count = 0;
        foreach ($catalog as $row) {
            if ($row['visible'] === true && $row['answered'] !== true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $liveAnswers
     * @return array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }
     */
    private function buildContext(Intake $intake, IntakeTemplateVersion $version, array $liveAnswers = []): array
    {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules']);
        $intake->loadMissing(['answers', 'aircoRooms']);

        $answers = [];
        $answerSources = [];
        foreach ($intake->answers as $answer) {
            $composite = VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key);
            $answers[$composite] = $answer->value;
            $answerSources[$composite] = $answer->prefill_source;
        }

        foreach ($liveAnswers as $key => $value) {
            if (is_array($value)) {
                $answers[$key] = $value;
            }
        }

        $questionTypes = [];
        $sectionsByQuestionKey = [];
        $allQuestions = collect();

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
                $allQuestions->put($question->key, $question);
            }
        }

        return [
            'answers' => $answers,
            'answerSources' => $answerSources,
            'questionTypes' => $questionTypes,
            'sectionsByQuestionKey' => $sectionsByQuestionKey,
            'allQuestions' => $allQuestions,
        ];
    }

    /**
     * @param  list<IntakeStep>  $steps
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     * @param  list<string>  $forceShowComposites
     */
    private function appendVisibleQuestionSteps(
        array &$steps,
        Intake $intake,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        array $context,
        array $forceShowComposites,
    ): void {
        $questions = $section->questions->sortBy('sort_order')->values();
        $visibility = $this->resolveVisibilityForSection($questions, $sectionInstanceKey, $context);

        foreach ($questions as $question) {
            $composite = VisibilityResolver::compositeKey($question->key, $sectionInstanceKey);
            $forceShow = in_array($composite, $forceShowComposites, true);
            $presentation = $this->questionPresentation(
                $question,
                $sectionInstanceKey,
                $visibility,
                $context,
                $forceShow,
            );

            if ($presentation['reason'] !== 'visible') {
                continue;
            }

            $instanceSuffix = $sectionInstanceKey === null ? '' : '::'.$sectionInstanceKey;
            $sectionTitle = $this->sectionTitleForInstance($intake, $section, $sectionInstanceKey);
            $title = $question->label;
            if ($sectionInstanceKey !== null && in_array($question->key, ['room_photos', 'indoor_unit_position_photo'], true)) {
                $roomLabel = $this->instanceLabel($intake, $sectionInstanceKey);
                if ($roomLabel !== null) {
                    $title = $question->label.' — '.$roomLabel;
                }
            }

            $steps[] = [
                'key' => $section->key.$instanceSuffix.'::'.$question->key,
                'section_key' => $section->key,
                'section_instance_key' => $sectionInstanceKey,
                'question_key' => $question->key,
                'title' => $title,
                'section_title' => $sectionTitle,
                'description' => $section->description,
                'help_text' => $question->help_text,
                'is_repeatable' => $section->is_repeatable,
                'is_required' => $presentation['required'],
                'kind' => 'question',
            ];
        }
    }

    /**
     * @param  list<CatalogRow>  $rows
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     */
    private function appendCatalogRows(
        array &$rows,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        array $context,
    ): void {
        $questions = $section->questions->sortBy('sort_order')->values();
        $visibility = $this->resolveVisibilityForSection($questions, $sectionInstanceKey, $context);

        foreach ($questions as $question) {
            $presentation = $this->questionPresentation(
                $question,
                $sectionInstanceKey,
                $visibility,
                $context,
                forceShow: false,
            );

            $rows[] = [
                'question_key' => $question->key,
                'section_instance_key' => $sectionInstanceKey,
                'section_key' => $section->key,
                'title' => $question->label,
                'visible' => $presentation['visible'],
                'required' => $presentation['required'],
                'reason' => $presentation['reason'],
                'prefill_source' => $presentation['prefill_source'],
                'answered' => $presentation['answered'],
            ];
        }
    }

    /**
     * Shared visibility/skip reason for wizard steps and catalog rows.
     *
     * @param  Collection<int, IntakeQuestion>  $questions
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     * @return array<string, array{visible: bool, required: bool}>
     */
    private function resolveVisibilityForSection(
        Collection $questions,
        ?string $sectionInstanceKey,
        array $context,
    ): array {
        $targets = [];
        foreach ($questions as $question) {
            $targets[] = [
                'question_key' => $question->key,
                'section_instance_key' => $sectionInstanceKey,
            ];
        }

        return $this->visibilityResolver->resolve(
            $context['allQuestions']->values(),
            $context['answers'],
            $context['questionTypes'],
            $context['sectionsByQuestionKey'],
            $targets,
            customerMode: true,
        );
    }

    /**
     * @param  array<string, array{visible: bool, required: bool}>  $visibility
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>
     * }  $context
     * @return array{
     *     visible: bool,
     *     required: bool,
     *     reason: 'visible'|'prefilled'|'niet_relevant'|'intern'|'overgeslagen',
     *     prefill_source: string|null,
     *     answered: bool
     * }
     */
    private function questionPresentation(
        IntakeQuestion $question,
        ?string $sectionInstanceKey,
        array $visibility,
        array $context,
        bool $forceShow = false,
    ): array {
        $composite = VisibilityResolver::compositeKey($question->key, $sectionInstanceKey);
        $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];
        $answerSource = $context['answerSources'][$composite] ?? null;
        $answerValue = $context['answers'][$composite] ?? null;
        $answered = $this->answerValueReader->isFilled(
            is_array($answerValue) ? $answerValue : null,
            $question->type,
        );

        $prefilledSkipped = ! $forceShow && $this->isPrefillSkipped($question, $answerSource);
        $roomNameHidden = ! $forceShow
            && $question->key === 'room_name'
            && ! $this->shouldAskRoomName($context['answers'], $sectionInstanceKey);
        $internal = $this->isInternalQuestion($question);
        $ruleVisible = $state['visible'] === true;
        $wizardVisible = $ruleVisible && ! $prefilledSkipped && ! $roomNameHidden;

        $reason = $this->catalogReason(
            wizardVisible: $wizardVisible,
            ruleVisible: $ruleVisible,
            prefilledSkipped: $prefilledSkipped || $roomNameHidden,
            internal: $internal,
        );

        return [
            'visible' => $wizardVisible,
            'required' => $state['required'] === true,
            'reason' => $reason,
            'prefill_source' => $answerSource,
            'answered' => $answered,
        ];
    }

    /**
     * @return 'visible'|'prefilled'|'niet_relevant'|'intern'|'overgeslagen'
     */
    private function catalogReason(
        bool $wizardVisible,
        bool $ruleVisible,
        bool $prefilledSkipped,
        bool $internal,
    ): string {
        return match (true) {
            $wizardVisible => 'visible',
            $prefilledSkipped => 'prefilled',
            $internal => 'intern',
            ! $ruleVisible => 'niet_relevant',
            default => 'overgeslagen',
        };
    }

    private function isPrefillSkipped(IntakeQuestion $question, ?string $answerSource): bool
    {
        // Eén bron of een lijst: `building_type` kan zowel uit de BAG als uit een
        // geregistreerd energielabel komen, en beide mogen de vraag laten vervallen.
        $skipSources = $question->meta['skip_when_prefilled_by'] ?? null;
        $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];

        // request_text / derived_lxw altijd; legacy `ai` matcht ook ai_text/ai_photo (BL-118).
        return PrefillSources::shouldSkipPrefill($answerSource, $skipSources);
    }

    private function isInternalQuestion(IntakeQuestion $question): bool
    {
        $audience = is_string($question->meta['audience'] ?? null)
            ? (string) $question->meta['audience']
            : null;

        return ($question->meta['internal'] ?? false) === true
            || $audience === 'installer'
            || $audience === 'internal';
    }

    /**
     * @param  list<IntakeStep>  $steps
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  Collection<string, IntakeQuestion>  $allQuestions
     * @return list<IntakeStep>
     */
    private function insertKnownSummaryStep(
        array $steps,
        Intake $intake,
        IntakeTemplateVersion $version,
        array $answers,
        Collection $allQuestions,
    ): array {
        $items = [];
        $intake->loadMissing('aircoRooms');

        // Sectie → natural instance → vraag (room-2 vóór room-10).
        $sectionOrder = [];
        $questionOrder = [];
        $questionSection = [];
        foreach ($version->sections->sortBy('sort_order') as $section) {
            $sectionOrder[$section->key] = (int) $section->sort_order;
            foreach ($section->questions->sortBy('sort_order') as $question) {
                $questionOrder[$question->key] = (int) $question->sort_order;
                $questionSection[$question->key] = $section->key;
            }
        }

        foreach ($intake->answers as $answer) {
            $source = $answer->prefill_source;
            $question = $allQuestions->get($answer->question_key);
            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            if (! KnownSummaryCatalog::allows($question)
                || ! KnownSummaryCatalog::allowsSource($source)
                || ! KnownSummaryCatalog::isSkipped($source, $question)) {
                continue;
            }

            $display = $source === PrefillSources::DERIVED_LXW
                && $answer->question_key === 'room_area_m2'
                ? 'berekend uit L×B'
                : $this->displayAnswerValue($question, $answer->value);
            if ($display === null) {
                continue;
            }

            $instanceLabel = $this->instanceLabel($intake, $answer->section_instance_key);
            $label = $question->label;
            if ($instanceLabel !== null) {
                $label = $instanceLabel.' — '.$label;
            }

            $sectionKey = $questionSection[$answer->question_key] ?? '';
            $items[] = [
                'question_key' => $answer->question_key,
                'section_instance_key' => $answer->section_instance_key,
                'label' => $label,
                'display_value' => $display,
                'prefill_source' => (string) $source,
                '_section_order' => $sectionOrder[$sectionKey] ?? PHP_INT_MAX,
                '_question_order' => $questionOrder[$answer->question_key] ?? PHP_INT_MAX,
            ];
        }

        if ($items === []) {
            return $steps;
        }

        usort($items, static function (array $a, array $b): int {
            $sectionCmp = ($a['_section_order'] <=> $b['_section_order']);
            if ($sectionCmp !== 0) {
                return $sectionCmp;
            }

            $instanceCmp = strnatcmp((string) ($a['section_instance_key'] ?? ''), (string) ($b['section_instance_key'] ?? ''));
            if ($instanceCmp !== 0) {
                return $instanceCmp;
            }

            return ($a['_question_order'] <=> $b['_question_order'])
                ?: ($a['question_key'] <=> $b['question_key']);
        });

        $items = array_map(static function (array $item): array {
            unset($item['_section_order'], $item['_question_order']);

            return $item;
        }, $items);

        $summary = [
            'key' => '_known_summary',
            'section_key' => 'request',
            'section_instance_key' => null,
            'question_key' => '_known_summary',
            'title' => 'Dit hebben we al uit je aanvraag',
            'section_title' => 'Bekende gegevens',
            'description' => 'Klopt dit? Je kunt iets wijzigen; verder gaan mag meteen.',
            'help_text' => 'Alleen echte onzekerheid vragen we later nog apart. Merkvoorkeur houdt de opname niet tegen.',
            'is_repeatable' => false,
            'is_required' => false,
            'kind' => 'known_summary',
            'known_items' => $items,
        ];

        // Na request_reason wanneer die in de flow staat; anders vooraan zodat het nooit wordt overgeslagen.
        $insertAt = 0;
        foreach ($steps as $index => $step) {
            if ($step['question_key'] === 'request_reason') {
                $insertAt = $index + 1;
                break;
            }
        }

        array_splice($steps, $insertAt, 0, [$summary]);

        return $steps;
    }

    /**
     * Alleen AircoRoom.name; anders null (sectietitel als fallback).
     */
    private function instanceLabel(Intake $intake, ?string $sectionInstanceKey): ?string
    {
        if ($sectionInstanceKey === null) {
            return null;
        }

        $intake->loadMissing('aircoRooms');
        $room = $intake->aircoRooms->firstWhere('key', $sectionInstanceKey);
        if ($room !== null && trim($room->name) !== '') {
            return trim($room->name);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function displayAnswerValue(IntakeQuestion $question, ?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($question->type) {
            QuestionType::SingleChoice => $this->optionLabel($question, is_string($value['value'] ?? null) ? $value['value'] : null),
            QuestionType::MultiChoice => $this->multiOptionLabels($question, is_array($value['values'] ?? null) ? $value['values'] : []),
            QuestionType::Number => isset($value['number']) && is_numeric($value['number'])
                ? (string) $value['number']
                : null,
            QuestionType::ShortText, QuestionType::LongText => isset($value['text']) && is_string($value['text']) && trim($value['text']) !== ''
                ? trim($value['text'])
                : null,
            QuestionType::Boolean => array_key_exists('bool', $value) && is_bool($value['bool'])
                ? ($value['bool'] ? 'Ja' : 'Nee')
                : null,
            default => null,
        };
    }

    private function optionLabel(IntakeQuestion $question, ?string $optionValue): ?string
    {
        if ($optionValue === null) {
            return null;
        }

        $option = $question->options->firstWhere('value', $optionValue);

        return is_string($option?->label) ? $option->label : $optionValue;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function multiOptionLabels(IntakeQuestion $question, array $values): ?string
    {
        $labels = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $labels[] = $this->optionLabel($question, $value) ?? $value;
        }

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * room_name alleen als minstens twee ruimtes hetzelfde type hebben én deze
     * ruimte nog geen geëxtraheerde naam heeft. Anders leidt de wizard/dossier af.
     *
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function shouldAskRoomName(array $answers, ?string $sectionInstanceKey): bool
    {
        if ($sectionInstanceKey === null) {
            return false;
        }

        $ownName = $answers[VisibilityResolver::compositeKey('room_name', $sectionInstanceKey)] ?? null;
        $ownText = is_array($ownName) ? ($ownName['text'] ?? null) : null;
        if (is_string($ownText) && trim($ownText) !== '') {
            return false;
        }

        $typesByInstance = RoomLabelResolver::typesByInstance($answers);
        $ownType = $typesByInstance[$sectionInstanceKey] ?? null;
        if ($ownType === null) {
            return false;
        }

        $sameType = 0;
        foreach ($typesByInstance as $type) {
            if ($type === $ownType) {
                $sameType++;
            }
        }

        return $sameType >= 2;
    }

    private function sectionTitleForInstance(
        Intake $intake,
        IntakeSection $section,
        ?string $sectionInstanceKey,
    ): string {
        if ($sectionInstanceKey === null) {
            return $section->title;
        }

        $label = $this->instanceLabel($intake, $sectionInstanceKey);

        return $label ?? ($section->title.' '.Str::afterLast($sectionInstanceKey, '-'));
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     */
    private function repeatCount(IntakeSection $section, array $answers, array $questionTypes): int
    {
        $key = $section->repeat_count_question_key;

        if ($key === null || $key === '') {
            return 0;
        }

        $type = $questionTypes[$key] ?? null;
        if ($type !== QuestionType::Number) {
            return 0;
        }

        $number = $this->answerValueReader->readComparable(
            $answers[VisibilityResolver::compositeKey($key, null)] ?? null,
            $type,
        );

        if (! is_numeric($number)) {
            return 0;
        }

        return max(0, min(8, (int) $number));
    }

    /**
     * @param  list<IntakeStep>  $steps
     */
    public function indexForCursor(
        array $steps,
        ?string $sectionKey,
        ?string $questionKey,
        ?string $sectionInstanceKey = null,
    ): int {
        if ($questionKey !== null && $questionKey !== '') {
            foreach ($steps as $index => $step) {
                if ($step['question_key'] !== $questionKey) {
                    continue;
                }

                if ($sectionKey !== null && $step['section_key'] !== $sectionKey) {
                    continue;
                }

                if ($sectionInstanceKey !== null && $step['section_instance_key'] !== $sectionInstanceKey) {
                    continue;
                }

                return $index;
            }
        }

        if ($sectionKey !== null && $sectionKey !== '') {
            foreach ($steps as $index => $step) {
                if ($step['section_key'] === $sectionKey) {
                    return $index;
                }
            }
        }

        return 0;
    }

    /**
     * @param  list<IntakeStep>  $steps
     */
    public function indexForStepKey(array $steps, ?string $stepKey): ?int
    {
        if ($stepKey === null || $stepKey === '') {
            return null;
        }

        foreach ($steps as $index => $step) {
            if ($step['key'] === $stepKey) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @deprecated Use indexForCursor(); kept for callers that only know a section.
     *
     * @param  list<IntakeStep>  $steps
     */
    public function indexForSectionKey(array $steps, ?string $sectionKey, ?string $sectionInstanceKey = null): int
    {
        return $this->indexForCursor($steps, $sectionKey, null, $sectionInstanceKey);
    }

    public function questionForStep(IntakeTemplateVersion $version, string $sectionKey, string $questionKey): ?IntakeQuestion
    {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules']);

        $section = $version->sections->firstWhere('key', $sectionKey);

        if ($section === null) {
            return null;
        }

        $question = $section->questions->firstWhere('key', $questionKey);

        if (! $question instanceof IntakeQuestion) {
            return null;
        }

        $question->loadMissing(['options', 'rules']);
        $question->setRelation('section', $section);

        return $question;
    }
}
