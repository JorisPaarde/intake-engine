<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\InternalCustomerQuestions;
use App\Domains\Intake\Support\KnownSummaryCatalog;
use App\Domains\Intake\Support\MustAcceptQuestions;
use App\Domains\Intake\Support\OutdoorPhotoReuse;
use App\Domains\Intake\Support\PhotoFollowUpWaiver;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\RoomAreaAcceptance;
use App\Domains\Intake\Support\RoomLabelResolver;
use App\Domains\Intake\Support\SharedRoomAnswers;
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
 *     kind?: 'question'|'known_summary'|'question_group'|'closing_wishes',
 *     known_items?: list<KnownSummaryItem>,
 *     group_key?: string,
 *     group_question_keys?: list<string>,
 *     bundle_question_keys?: list<string>,
 *     sticky_photo_composite?: string|null,
 *     shared_scope?: bool,
 *     shared_target_instances?: list<string>,
 *     share_differs_label?: string
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
    /** @var list<string> */
    public const CLOSING_WISH_KEYS = ['brand_preference', 'desired_planning', 'additional_comments'];

    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly VisibilityResolver $visibilityResolver,
    ) {}

    /**
     * @param  array<string, array<string, mixed>|null>  $liveAnswers  optional in-memory form answers for live visibility
     * @param  list<string>  $forceShowComposites  composites that must stay visible (known-summary “Wijzigen”)
     * @param  list<string>  $stickyStepKeys  full wizard step keys kept visible while the customer is still on them
     *                                        (e.g. room_name after autosave until Volgende)
     * @return list<IntakeStep>
     */
    public function build(
        Intake $intake,
        IntakeTemplateVersion $version,
        array $liveAnswers = [],
        array $forceShowComposites = [],
        array $stickyStepKeys = [],
    ): array {
        $context = $this->buildContext($intake, $version, $liveAnswers);
        $steps = [];

        foreach ($version->sections->sortBy('sort_order') as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatCount($section, $context['answers'], $context['questionTypes']);
                $instanceKeys = [];
                for ($i = 1; $i <= $count; $i++) {
                    $instanceKeys[] = Str::singular($section->key).'-'.$i;
                }

                if ($count > 1) {
                    $this->appendSharedRoomQuestionSteps(
                        $steps,
                        $intake,
                        $section,
                        $instanceKeys,
                        $context,
                        $forceShowComposites,
                        $stickyStepKeys,
                    );
                }

                foreach ($instanceKeys as $instanceKey) {
                    $this->appendVisibleQuestionSteps(
                        $steps,
                        $intake,
                        $section,
                        $instanceKey,
                        $context,
                        $forceShowComposites,
                        $stickyStepKeys,
                        sharedTargets: $instanceKeys,
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
                $stickyStepKeys,
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
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
     * }
     */
    private function buildContext(Intake $intake, IntakeTemplateVersion $version, array $liveAnswers = []): array
    {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules']);
        $intake->loadMissing(['answers', 'aircoRooms', 'uploads']);

        $answers = [];
        $answerSources = [];
        $answerFacts = [];
        foreach ($intake->answers as $answer) {
            $composite = VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key);
            $answers[$composite] = $answer->value;
            $answerSources[$composite] = $answer->prefill_source;
            $answerFacts[$composite] = [
                'provenance' => $answer->fact_provenance,
                'confidence' => is_int($answer->fact_confidence) ? $answer->fact_confidence : null,
                'fact_source' => $answer->fact_source,
            ];
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
            'answerFacts' => $answerFacts,
            'questionTypes' => $questionTypes,
            'sectionsByQuestionKey' => $sectionsByQuestionKey,
            'allQuestions' => $allQuestions,
            'hasUsableOutdoorContext' => OutdoorPhotoReuse::hasUsableOutdoorContext($intake),
        ];
    }

    /**
     * Één scherm per deelbare ruimtevraag (verdieping/zon/glas/…) vóór de per-ruimte-loop.
     *
     * @param  list<IntakeStep>  $steps
     * @param  list<string>  $instanceKeys
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
     * }  $context
     * @param  list<string>  $forceShowComposites
     * @param  list<string>  $stickyStepKeys
     */
    private function appendSharedRoomQuestionSteps(
        array &$steps,
        Intake $intake,
        IntakeSection $section,
        array $instanceKeys,
        array $context,
        array $forceShowComposites,
        array $stickyStepKeys,
    ): void {
        if (count($instanceKeys) < 2) {
            return;
        }

        $questions = $section->questions->sortBy('sort_order')->values();
        // Visibility voor gedeeld scherm: evalueer alsof we op de eerste ruimte zitten
        // (regels die room-* antwoorden nodig hebben blijven werken).
        $probeInstance = $instanceKeys[0];
        $visibility = $this->resolveVisibilityForSection($questions, $probeInstance, $context);

        foreach ($questions as $question) {
            if (! SharedRoomAnswers::isShareable($question)) {
                continue;
            }

            // BL-168: na “Verschilt per ruimte” de per-kamer-stappen hier in de
            // gedeelde-blok-volgorde zetten (niet pas in elke room-* na dit blok).
            // Anders springt chooseSharedDiffers naar kamer 1 en vallen latere
            // shareables (glas, verdieping, …) stil weg tot Afronden.
            if (SharedRoomAnswers::customerChoseDiffers($context['answers'], $question->key)) {
                $this->appendDiffersPerRoomQuestionSteps(
                    $steps,
                    $intake,
                    $section,
                    $question,
                    $instanceKeys,
                    $context,
                    $forceShowComposites,
                    $stickyStepKeys,
                );

                continue;
            }

            if (SharedRoomAnswers::allInstancesAnswered($instanceKeys, $context['answers'], $question->key)) {
                continue;
            }

            $sharedComposite = VisibilityResolver::compositeKey($question->key, SharedRoomAnswers::SCOPE_INSTANCE);
            $forceShow = in_array($sharedComposite, $forceShowComposites, true)
                || in_array(
                    VisibilityResolver::compositeKey($question->key, $probeInstance),
                    $forceShowComposites,
                    true,
                );

            $presentation = $this->questionPresentation(
                $question,
                $probeInstance,
                $visibility,
                $context,
                $forceShow,
            );

            if ($presentation['reason'] !== 'visible' && ! $forceShow) {
                continue;
            }

            $shareTitle = SharedRoomAnswers::sectionTitleForTargets($instanceKeys, $context['answers']);
            if (is_string($question->meta['share_section_title'] ?? null)
                && $shareTitle === SharedRoomAnswers::SECTION_TITLE) {
                $shareTitle = (string) $question->meta['share_section_title'];
            }

            $shareLabel = $question->meta['share_label'] ?? null;
            $title = is_string($shareLabel) && trim($shareLabel) !== ''
                ? trim($shareLabel)
                : $question->label;

            $shareHelp = $question->meta['share_help_text'] ?? null;
            $helpText = is_string($shareHelp) && trim($shareHelp) !== ''
                ? trim($shareHelp)
                : $question->help_text;

            $steps[] = [
                'key' => $section->key.'::'.SharedRoomAnswers::SCOPE_INSTANCE.'::'.$question->key,
                'section_key' => $section->key,
                'section_instance_key' => SharedRoomAnswers::SCOPE_INSTANCE,
                'question_key' => $question->key,
                'title' => $title,
                'section_title' => $shareTitle,
                'description' => 'Dit antwoord gebruiken we voor alle ruimtes, tenzij je aangeeft dat het verschilt.',
                'help_text' => $helpText,
                'is_repeatable' => true,
                'is_required' => $presentation['required'],
                'kind' => 'question',
                'sticky_photo_composite' => null,
                'shared_scope' => true,
                'shared_target_instances' => $instanceKeys,
                'share_differs_label' => SharedRoomAnswers::differsLabel($question),
            ];
        }
    }

    /**
     * Per-kamer-stappen voor een shareable vraag op de plek van het gedeelde scherm.
     *
     * @param  list<IntakeStep>  $steps
     * @param  list<string>  $instanceKeys
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
     * }  $context
     * @param  list<string>  $forceShowComposites
     * @param  list<string>  $stickyStepKeys
     */
    private function appendDiffersPerRoomQuestionSteps(
        array &$steps,
        Intake $intake,
        IntakeSection $section,
        IntakeQuestion $question,
        array $instanceKeys,
        array $context,
        array $forceShowComposites,
        array $stickyStepKeys,
    ): void {
        $sortedQuestions = $section->questions->sortBy('sort_order')->values();

        foreach ($instanceKeys as $instanceKey) {
            $visibility = $this->resolveVisibilityForSection($sortedQuestions, $instanceKey, $context);
            $composite = VisibilityResolver::compositeKey($question->key, $instanceKey);
            $forceShow = in_array($composite, $forceShowComposites, true);
            $presentation = $this->questionPresentation(
                $question,
                $instanceKey,
                $visibility,
                $context,
                $forceShow,
            );

            $candidateStepKey = $section->key.'::'.$instanceKey.'::'.$question->key;
            $stickyKeep = in_array($candidateStepKey, $stickyStepKeys, true)
                && in_array($presentation['reason'], ['prefilled', 'overgeslagen'], true);

            if ($presentation['reason'] !== 'visible' && ! $forceShow && ! $stickyKeep) {
                continue;
            }

            $steps[] = [
                'key' => $candidateStepKey,
                'section_key' => $section->key,
                'section_instance_key' => $instanceKey,
                'question_key' => $question->key,
                'title' => $question->label,
                'section_title' => $this->sectionTitleForInstance($intake, $section, $instanceKey, $context['answers']),
                'description' => $section->description,
                'help_text' => $question->help_text,
                'is_repeatable' => true,
                'is_required' => $presentation['required'],
                'kind' => 'question',
                'sticky_photo_composite' => null,
            ];
        }
    }

    /**
     * @param  list<IntakeStep>  $steps
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
     * }  $context
     * @param  list<string>  $forceShowComposites
     * @param  list<string>  $stickyStepKeys
     * @param  list<string>  $sharedTargets
     */
    private function appendVisibleQuestionSteps(
        array &$steps,
        Intake $intake,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        array $context,
        array $forceShowComposites,
        array $stickyStepKeys = [],
        array $sharedTargets = [],
    ): void {
        $sortedQuestions = $section->questions->sortBy('sort_order')->values();
        // Shareable multi-room vragen staan in het gedeelde blok (of als per-kamer
        // expansie ná differs, BL-168) — niet opnieuw in elke room-*-reeks.
        $questions = $sortedQuestions;
        $visibility = $this->resolveVisibilityForSection($sortedQuestions, $sectionInstanceKey, $context);
        /** @var array<string, true> $emittedGroups */
        $emittedGroups = [];
        /** @var list<array{question: IntakeQuestion, required: bool}> $pendingClosing */
        $pendingClosing = [];

        $flushClosing = function () use (&$steps, &$pendingClosing, $intake, $section, $sectionInstanceKey, $context): void {
            if ($pendingClosing === []) {
                return;
            }

            $keys = array_map(
                static fn (array $row): string => $row['question']->key,
                $pendingClosing,
            );
            $instanceSuffix = $sectionInstanceKey === null ? '' : '::'.$sectionInstanceKey;
            $steps[] = [
                'key' => $section->key.$instanceSuffix.'::_closing_wishes',
                'section_key' => $section->key,
                'section_instance_key' => $sectionInstanceKey,
                'question_key' => '_closing_wishes',
                'title' => 'Merk, planning en opmerkingen',
                'section_title' => $this->sectionTitleForInstance($intake, $section, $sectionInstanceKey, $context['answers']),
                'description' => 'Optioneel. Zonder voorkeur kiest de installateur wat het beste past.',
                'help_text' => 'Dit houdt de opname niet tegen. Sla over wat je niet weet.',
                'is_repeatable' => false,
                'is_required' => false,
                'kind' => 'closing_wishes',
                'bundle_question_keys' => $keys,
            ];
            $pendingClosing = [];
        };

        foreach ($questions as $question) {
            // Multi-room shareables: gedeeld scherm of differs-expansie in het
            // gedeelde blok (BL-168). Alleen force-show (Afronden-link) mag hier.
            if ($sectionInstanceKey !== null
                && SharedRoomAnswers::isShareable($question)
                && count($sharedTargets) > 1
                && ! in_array(
                    VisibilityResolver::compositeKey($question->key, $sectionInstanceKey),
                    $forceShowComposites,
                    true,
                )) {
                continue;
            }

            $composite = VisibilityResolver::compositeKey($question->key, $sectionInstanceKey);
            $forceShow = in_array($composite, $forceShowComposites, true);
            $presentation = $this->questionPresentation(
                $question,
                $sectionInstanceKey,
                $visibility,
                $context,
                $forceShow,
            );

            // BL-161: na Toch doorgaan op fusebox_photo de extra foto helemaal
            // verbergen (niet alleen un-require) — ook als fusebox_clarity per
            // ongeluk weer needs_clearer_photo is.
            if (PhotoFollowUpWaiver::waives($intake, $intake->templateVersion, $question, $sectionInstanceKey)) {
                continue;
            }

            $instanceSuffix = $sectionInstanceKey === null ? '' : '::'.$sectionInstanceKey;
            $wizardGroup = is_string($question->meta['wizard_group'] ?? null)
                ? (string) $question->meta['wizard_group']
                : null;
            $candidateStepKey = $wizardGroup !== null && $wizardGroup !== ''
                ? $section->key.$instanceSuffix.'::'.$wizardGroup
                : $section->key.$instanceSuffix.'::'.$question->key;
            $aroundHouseHidden = $question->key === OutdoorPhotoReuse::TARGET_KEY
                && ($context['hasUsableOutdoorContext'] ?? false) === true;
            // Sticky keeps the active step until Volgende/Vorige (BL-140):
            // - prefilled/overgeslagen (e.g. room_name after autosave)
            // - primary photo steps that AI just made rule-irrelevant (e.g. wall_outlet after
            //   outlet=present) so the assessment tile can render — not *_extra photos
            //   (fusebox_photo_extra must still disappear after a clear meterkastfoto)
            // - never around_house hidden by a usable outdoor photo
            $stickyPhotoAssessment = $presentation['reason'] === 'niet_relevant'
                && $question->type === QuestionType::Photo
                && ! str_ends_with($question->key, '_extra');
            $stickyKeep = in_array($candidateStepKey, $stickyStepKeys, true)
                && (
                    in_array($presentation['reason'], ['prefilled', 'overgeslagen'], true)
                    || $stickyPhotoAssessment
                )
                && ! $aroundHouseHidden;

            if ($presentation['reason'] !== 'visible' && ! $stickyKeep) {
                continue;
            }

            if ($section->key === 'closing'
                && $sectionInstanceKey === null
                && in_array($question->key, self::CLOSING_WISH_KEYS, true)
                && ! $forceShow
                && ! $stickyKeep) {
                $pendingClosing[] = [
                    'question' => $question,
                    'required' => $presentation['required'],
                ];

                continue;
            }

            $flushClosing();

            if ($wizardGroup !== null && $wizardGroup !== '') {
                if (isset($emittedGroups[$wizardGroup])) {
                    continue;
                }

                $groupMembers = $questions
                    ->filter(static function (IntakeQuestion $candidate) use ($wizardGroup): bool {
                        return ($candidate->meta['wizard_group'] ?? null) === $wizardGroup;
                    })
                    ->values();

                $visibleMembers = [];
                $groupRequired = false;
                $anyMemberForced = false;
                foreach ($groupMembers as $member) {
                    $memberComposite = VisibilityResolver::compositeKey($member->key, $sectionInstanceKey);
                    $memberForce = in_array($memberComposite, $forceShowComposites, true);
                    $anyMemberForced = $anyMemberForced || $memberForce;
                    $memberPresentation = $this->questionPresentation(
                        $member,
                        $sectionInstanceKey,
                        $visibility,
                        $context,
                        $memberForce,
                    );
                    if ($memberPresentation['reason'] !== 'visible') {
                        continue;
                    }
                    $visibleMembers[] = $member;
                    $groupRequired = $groupRequired || $memberPresentation['required'];
                }

                if ($visibleMembers === []) {
                    continue;
                }

                $areaPresent = $this->roomAreaPresent($context, $sectionInstanceKey);
                $areaTrusted = $areaPresent && $this->roomAreaTrusted($context, $sectionInstanceKey);
                $lxwTrusted = $this->roomLengthWidthTrusted($context, $sectionInstanceKey);

                // Trusted m² óf letterlijk opgegeven L×B (aanvraag) → geen herhaalvraag.
                // Onzekere AI-m² houdt het scherm zichtbaar maar optioneel (BL-124).
                if ($wizardGroup === 'room_dimensions'
                    && ($areaTrusted || $lxwTrusted)
                    && ! $anyMemberForced
                    && ! $stickyKeep) {
                    $emittedGroups[$wizardGroup] = true;

                    continue;
                }

                $emittedGroups[$wizardGroup] = true;
                $primary = $visibleMembers[0];
                $instanceSuffix = $sectionInstanceKey === null ? '' : '::'.$sectionInstanceKey;
                $sectionTitle = $this->sectionTitleForInstance($intake, $section, $sectionInstanceKey, $context['answers']);
                $groupTitle = is_string($primary->meta['wizard_group_title'] ?? null)
                    ? (string) $primary->meta['wizard_group_title']
                    : $primary->label;
                // Ruimtelabel alleen in section_title (eyebrow), niet dubbel in de titel.
                $groupHelp = is_string($primary->meta['wizard_group_help'] ?? null)
                    ? (string) $primary->meta['wizard_group_help']
                    : $primary->help_text;

                // Bekende (ook onzekere) m²: L×B blijft optioneel op het scherm.
                if ($wizardGroup === 'room_dimensions' && $areaPresent) {
                    $groupRequired = false;
                    if ($groupHelp === null || $groupHelp === '') {
                        $groupHelp = 'Het vloeroppervlak is al bekend. Lengte en breedte zijn optioneel.';
                    }
                }

                $steps[] = [
                    'key' => $section->key.$instanceSuffix.'::'.$wizardGroup,
                    'section_key' => $section->key,
                    'section_instance_key' => $sectionInstanceKey,
                    'question_key' => $primary->key,
                    'title' => $groupTitle,
                    'section_title' => $sectionTitle,
                    'description' => $section->description,
                    'help_text' => $groupHelp,
                    'is_repeatable' => $section->is_repeatable,
                    'is_required' => $groupRequired,
                    'kind' => 'question_group',
                    'group_key' => $wizardGroup,
                    'group_question_keys' => array_map(
                        static fn (IntakeQuestion $member): string => $member->key,
                        $visibleMembers,
                    ),
                    // Group members stay on visibilityForQuestions; never force via sticky photo.
                    'sticky_photo_composite' => null,
                ];

                continue;
            }

            $instanceSuffix = $sectionInstanceKey === null ? '' : '::'.$sectionInstanceKey;
            $sectionTitle = $this->sectionTitleForInstance($intake, $section, $sectionInstanceKey, $context['answers']);
            // Ruimtelabel alleen in section_title (eyebrow), niet herhaald in de vraagtitel.
            $title = $question->label;
            $helpText = $question->help_text;

            if ($question->key === 'indoor_unit_position_photo') {
                $helpText = $this->extraRoomOverviewHelp($context['answers'], $sectionInstanceKey, $helpText);
            }

            $steps[] = [
                'key' => $section->key.$instanceSuffix.'::'.$question->key,
                'section_key' => $section->key,
                'section_instance_key' => $sectionInstanceKey,
                'question_key' => $question->key,
                'title' => $title,
                'section_title' => $sectionTitle,
                'description' => $section->description,
                'help_text' => $helpText,
                'is_repeatable' => $section->is_repeatable,
                'is_required' => $presentation['required'],
                'kind' => 'question',
                // Explicit marker for IntakeWizard::render — only this composite may be
                // force-visible when sticky-kept after AI made it rule-irrelevant (E2E 08).
                'sticky_photo_composite' => $stickyPhotoAssessment
                    ? VisibilityResolver::compositeKey($question->key, $sectionInstanceKey)
                    : null,
            ];
        }

        $flushClosing();
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function extraRoomOverviewHelp(
        array $answers,
        ?string $sectionInstanceKey,
        ?string $fallback,
    ): string {
        // Alleen tonen wanneer assessment expliciet room_extra_overview_needed=needs_photo zette.
        $overview = $answers[VisibilityResolver::compositeKey('room_extra_overview_needed', $sectionInstanceKey)] ?? null;
        $overviewValue = is_array($overview) ? ($overview['value'] ?? null) : null;

        if ($overviewValue === 'needs_photo') {
            return 'Laat de ontbrekende wand of deur zien die op de eerdere ruimtefoto nog niet duidelijk in beeld was. Je hoeft zelf geen plek voor een binnenunit te kiezen.';
        }

        return is_string($fallback) && trim($fallback) !== ''
            ? $fallback
            : 'Laat de ontbrekende wand of deur zien die op de eerdere ruimtefoto nog niet duidelijk in beeld was. Je hoeft zelf geen plek voor een binnenunit te kiezen.';
    }

    /**
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>
     * }  $context
     * @param  array{provenance: ?string, confidence: ?int, fact_source: ?string}|null  $answerFact
     */
    private function roomCountMatchesStatedRooms(
        array $context,
        ?string $answerSource,
        ?array $answerFact,
        mixed $answerValue,
    ): bool {
        // Only an AI/request prefill; a customer or installer answer is never overruled.
        // An inferred count ("woonkamer en twee slaapkamers" → 3) is never a known fact,
        // so in production it is stored as a text suggestion; that is the case this rule covers.
        if (! in_array($answerSource, [
            PrefillSources::AI_TEXT,
            PrefillSources::REQUEST_TEXT,
            PrefillSources::AI_TEXT_SUGGESTION,
        ], true)) {
            return false;
        }

        $number = is_array($answerValue) ? ($answerValue['number'] ?? null) : null;
        if (! is_numeric($number) || (int) $number < 1) {
            return false;
        }

        $statedRooms = 0;
        foreach ($context['answerFacts'] as $composite => $fact) {
            if (! str_ends_with((string) $composite, '__room_type')) {
                continue;
            }
            if (FactProvenance::tryFromMixed($fact['provenance'] ?? null) === FactProvenance::Stated) {
                $statedRooms++;
            }
        }

        return $statedRooms === (int) $number;
    }

    /**
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
     * }  $context
     */
    /**
     * @param  array{answers: array<string, array<string, mixed>|null>}  $context
     */
    private function roomAreaPresent(array $context, ?string $sectionInstanceKey): bool
    {
        return RoomAreaAcceptance::hasKnownArea($context['answers'], $sectionInstanceKey);
    }

    /**
     * Trusted m² mag L×B stil overslaan (#176).
     * Zonder fact-meta (legacy AI-fill) blijft L×B zichtbaar maar optioneel (BL-124).
     *
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>
     * }  $context
     */
    private function roomAreaTrusted(array $context, ?string $sectionInstanceKey): bool
    {
        if (! $this->roomAreaPresent($context, $sectionInstanceKey)) {
            return false;
        }

        $composite = VisibilityResolver::compositeKey('room_area_m2', $sectionInstanceKey);
        $value = $context['answers'][$composite] ?? null;
        $source = $context['answerSources'][$composite] ?? null;

        // Klant heeft zelf m² gezet (geen prefill) → betrouwbaar genoeg om L×B over te slaan.
        if ($source === null) {
            return true;
        }

        $fact = $context['answerFacts'][$composite] ?? null;
        $provenance = FactProvenance::tryFromMixed($fact['provenance'] ?? null);

        // Alleen letterlijk stated m² mag L×B verbergen (#176). AI/inferred houdt het scherm
        // zichtbaar maar optioneel (BL-124), ook bij hoge default-confidence op ai_text.
        if ($provenance !== FactProvenance::Stated) {
            return false;
        }

        return PrefillSources::maySkipAsPrefill(
            $source,
            [PrefillSources::REQUEST_TEXT, PrefillSources::AI_TEXT, PrefillSources::AI_PHOTO, PrefillSources::AI_LEGACY, PrefillSources::DERIVED_LXW],
            'room_area_m2',
            $fact,
            is_array($value) ? $value : null,
        );
    }

    /**
     * Letterlijk opgegeven L én B (aanvraag/request_text of klant) → geen matenscherm opnieuw.
     *
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     allQuestions?: Collection<string, IntakeQuestion>
     * }  $context
     */
    private function roomLengthWidthTrusted(array $context, ?string $sectionInstanceKey): bool
    {
        if ($sectionInstanceKey === null) {
            return false;
        }

        foreach (['room_length_m', 'room_width_m'] as $key) {
            $composite = VisibilityResolver::compositeKey($key, $sectionInstanceKey);
            $value = $context['answers'][$composite] ?? null;
            $number = is_array($value) ? ($value['number'] ?? null) : null;
            if (! is_numeric($number) || (float) $number <= 0) {
                return false;
            }

            $source = $context['answerSources'][$composite] ?? null;
            // Klant zelf ingevuld (geen prefill_source) telt als bekend.
            if ($source === null) {
                continue;
            }

            $question = ($context['allQuestions'] ?? collect())->get($key);
            $skipSources = is_array($question?->meta['skip_when_prefilled_by'] ?? null)
                ? $question->meta['skip_when_prefilled_by']
                : [PrefillSources::REQUEST_TEXT, PrefillSources::AI_TEXT, PrefillSources::AI_PHOTO, PrefillSources::AI_LEGACY];
            $fact = $context['answerFacts'][$composite] ?? null;

            if (! PrefillSources::maySkipAsPrefill(
                $source,
                $skipSources,
                $key,
                $fact,
                $value,
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<CatalogRow>  $rows
     * @param  array{
     *     answers: array<string, array<string, mixed>|null>,
     *     answerSources: array<string, string|null>,
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
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
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
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
     *     answerFacts: array<string, array{provenance: ?string, confidence: ?int, fact_source: ?string}>,
     *     questionTypes: array<string, QuestionType>,
     *     sectionsByQuestionKey: array<string, IntakeSection>,
     *     allQuestions: Collection<string, IntakeQuestion>,
     *     hasUsableOutdoorContext?: bool
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
        $answerFact = $context['answerFacts'][$composite] ?? null;
        $answerValue = $context['answers'][$composite] ?? null;
        $answered = $this->answerValueReader->isFilled(
            is_array($answerValue) ? $answerValue : null,
            $question->type,
        );

        if ($answered && MustAcceptQuestions::requiresAcceptance($question)) {
            $answered = MustAcceptQuestions::isAccepted(
                is_array($answerValue) ? $answerValue : null,
                $answerSource,
            );
        }

        // Geaccepteerde must-accept (AVG-toestemming / waarheidsverklaring) niet opnieuw tonen.
        // Embed legt privacy_consent al bij start vast — voorkomt dubbele stap + tellersprong.
        $mustAcceptSatisfied = ! $forceShow
            && $answered
            && MustAcceptQuestions::requiresAcceptance($question);

        $prefilledSkipped = ! $forceShow && $this->isPrefillSkipped(
            $question,
            $answerSource,
            $answerFact,
            is_array($answerValue) ? $answerValue : null,
        );
        // Klant koos expliciet “Verschilt per ruimte”: AI-/fotoprefill mag de
        // per-kamer-vraag niet stil overslaan — die moet per ruimte gesteld worden.
        if ($prefilledSkipped
            && is_string($sectionInstanceKey)
            && str_starts_with($sectionInstanceKey, 'room-')
            && SharedRoomAnswers::isShareable($question)
            && SharedRoomAnswers::customerChoseDiffers($context['answers'], $question->key)) {
            $prefilledSkipped = false;
        }
        $roomNameHidden = ! $forceShow
            && $question->key === 'room_name'
            && ! $this->shouldAskRoomName($context['answers'], $sectionInstanceKey);
        $aroundHouseReused = ! $forceShow
            && $question->key === OutdoorPhotoReuse::TARGET_KEY
            && ($context['hasUsableOutdoorContext'] ?? false) === true;
        $ceilingHeightNotNeeded = ! $forceShow
            && ! $answered
            && $question->key === 'ceiling_height_m'
            && ! $this->roomNeedsCeilingHeight($context['answers'], $sectionInstanceKey);
        // N63: grootteklasse is overbodig zodra het vloeroppervlak al bekend is
        // (ook als de size-prefill nog niet geschreven is, bijv. door timing).
        $sizeClassNotNeeded = ! $forceShow
            && $question->key === 'room_size_indication'
            && $this->roomAreaPresent($context, $sectionInstanceKey);
        // N56: an AI-inferred room count that equals the number of rooms the request
        // literally names (stated room_type per instance) is already shown in the known
        // summary — do not ask it again. Structural: counts stated facts, never text.
        $roomCountCoveredByRooms = ! $forceShow
            && $answered
            && $question->key === 'indoor_unit_count'
            && $sectionInstanceKey === null
            && $this->roomCountMatchesStatedRooms($context, $answerSource, $answerFact, $answerValue);
        $internal = $this->isInternalQuestion($question);
        $ruleVisible = $state['visible'] === true;
        $wizardVisible = $ruleVisible
            && ! $prefilledSkipped
            && ! $mustAcceptSatisfied
            && ! $roomNameHidden
            && ! $aroundHouseReused
            && ! $ceilingHeightNotNeeded
            && ! $sizeClassNotNeeded
            && ! $roomCountCoveredByRooms
            && ! $internal;

        $reason = $this->catalogReason(
            wizardVisible: $wizardVisible,
            ruleVisible: $ruleVisible,
            prefilledSkipped: $prefilledSkipped || $mustAcceptSatisfied || $roomNameHidden || $aroundHouseReused || $ceilingHeightNotNeeded || $sizeClassNotNeeded || $roomCountCoveredByRooms,
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

    /**
     * @param  array{provenance: ?string, confidence: ?int, fact_source: ?string}|null  $answerFact
     * @param  array<string, mixed>|null  $answerValue
     */
    private function isPrefillSkipped(
        IntakeQuestion $question,
        ?string $answerSource,
        ?array $answerFact = null,
        ?array $answerValue = null,
    ): bool {
        // Eén bron of een lijst: `building_type` kan zowel uit de BAG als uit een
        // geregistreerd energielabel komen, en beide mogen de vraag laten vervallen.
        $skipSources = $question->meta['skip_when_prefilled_by'] ?? null;
        $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];

        return PrefillSources::maySkipAsPrefill(
            $answerSource,
            $skipSources,
            $question->key,
            $answerFact,
            $answerValue,
        );
    }

    private function isInternalQuestion(IntakeQuestion $question): bool
    {
        if (InternalCustomerQuestions::hidesFromCustomer($question)) {
            return true;
        }

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

        // N37/N56: with a known m² or L×B the size class is only derived; one size row per room.
        $roomsWithMeasuredArea = [];
        foreach ($intake->answers as $answer) {
            if (in_array($answer->question_key, ['room_area_m2', 'room_length_m', 'room_width_m'], true)
                && is_string($answer->section_instance_key)
                && is_numeric(is_array($answer->value) ? ($answer->value['number'] ?? null) : null)) {
                $roomsWithMeasuredArea[$answer->section_instance_key][$answer->question_key] = true;
            }
        }
        $roomsWithMeasuredArea = array_filter(
            $roomsWithMeasuredArea,
            static fn (array $keys): bool => isset($keys['room_area_m2']) || (isset($keys['room_length_m']) && isset($keys['room_width_m'])),
        );

        foreach ($intake->answers as $answer) {
            $source = $answer->prefill_source;
            $question = $allQuestions->get($answer->question_key);
            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            if (! KnownSummaryCatalog::allowsAnswer($answer, $question)) {
                continue;
            }

            if ($answer->question_key === 'room_size_indication'
                && is_string($answer->section_instance_key)
                && isset($roomsWithMeasuredArea[$answer->section_instance_key])) {
                continue;
            }

            $display = $source === PrefillSources::DERIVED_LXW
                && $answer->question_key === 'room_area_m2'
                ? $this->displayDerivedAreaValue($answer, $answers)
                : $this->displayAnswerValue($question, $answer->value);
            if ($display === null) {
                continue;
            }

            $instanceLabel = $this->instanceLabel($intake, $answer->section_instance_key, $answers);
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
     * Ruimtelabel voor de klant: overall “Kamer N van M (type)” (BL-163) — niet per soort
     * met een totaal dat niet bij dat soort hoort (klanttest run 3).
     *
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function instanceLabel(Intake $intake, ?string $sectionInstanceKey, array $answers = []): ?string
    {
        if ($sectionInstanceKey === null) {
            return null;
        }

        $total = $this->roomInstanceTotal($intake, $answers);
        $index = $this->roomInstanceIndex($sectionInstanceKey, $intake, $answers);

        $explicit = $this->explicitRoomName($answers, $sectionInstanceKey)
            ?? $this->customDossierRoomName($intake, $sectionInstanceKey);
        if ($explicit !== null) {
            if ($total <= 1 || $index === null) {
                return $explicit;
            }
            if (preg_match('/ van \d+$/u', $explicit) === 1) {
                return $explicit;
            }

            return $explicit.' · '.$index.' van '.$total;
        }

        $type = $this->roomTypeForInstance($intake, $sectionInstanceKey, $answers);
        $typeLower = RoomLabelResolver::typeLabelLower($type);

        if ($total <= 1 || $index === null) {
            return RoomLabelResolver::typeLabel($type);
        }

        $label = 'Kamer '.$index.' van '.$total;
        if ($typeLower !== null) {
            $label .= ' ('.$typeLower.')';
        }

        return $label;
    }

    private function customDossierRoomName(Intake $intake, string $sectionInstanceKey): ?string
    {
        $intake->loadMissing('aircoRooms');
        $room = $intake->aircoRooms->firstWhere('key', $sectionInstanceKey);
        if ($room === null) {
            return null;
        }

        $name = $this->stripAppendedFloorLabel(trim($room->name));
        if ($name === '' || RoomLabelResolver::isGeneratedPlaceholder($name)) {
            return null;
        }

        return $name;
    }

    /**
     * Known-summary: toon het berekende m²-getal, met optionele L×B-toelichting.
     *
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function displayDerivedAreaValue(IntakeAnswer $answer, array $answers): ?string
    {
        $area = is_array($answer->value) ? ($answer->value['number'] ?? null) : null;
        if (! is_numeric($area)) {
            return null;
        }

        $areaLabel = $this->formatMetersSquared((float) $area);
        $instance = $answer->section_instance_key;
        if (! is_string($instance) || $instance === '') {
            return $areaLabel;
        }

        $lengthValue = $answers[VisibilityResolver::compositeKey('room_length_m', $instance)] ?? null;
        $widthValue = $answers[VisibilityResolver::compositeKey('room_width_m', $instance)] ?? null;
        $length = is_array($lengthValue) ? ($lengthValue['number'] ?? null) : null;
        $width = is_array($widthValue) ? ($widthValue['number'] ?? null) : null;
        if (! is_numeric($length) || ! is_numeric($width)) {
            return $areaLabel;
        }

        return $areaLabel.' (berekend uit '.$this->formatMeterComponent((float) $length)
            .' × '.$this->formatMeterComponent((float) $width).' m)';
    }

    private function formatMetersSquared(float $value): string
    {
        return $this->formatMeterComponent($value).' m²';
    }

    private function formatMeterComponent(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return (string) (int) round($value);
        }

        $formatted = number_format($value, 2, ',', '');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function explicitRoomName(array $answers, string $sectionInstanceKey): ?string
    {
        $explicit = $answers[VisibilityResolver::compositeKey('room_name', $sectionInstanceKey)] ?? null;
        if (! is_array($explicit)) {
            return null;
        }
        $text = $explicit['text'] ?? $explicit['value'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        return $this->stripAppendedFloorLabel(trim($text));
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function roomTypeForInstance(Intake $intake, string $sectionInstanceKey, array $answers): ?string
    {
        $types = RoomLabelResolver::typesByInstance($answers);
        if (array_key_exists($sectionInstanceKey, $types)) {
            $type = $types[$sectionInstanceKey];

            return is_string($type) && $type !== '' ? $type : null;
        }

        $intake->loadMissing('aircoRooms');
        $room = $intake->aircoRooms->firstWhere('key', $sectionInstanceKey);
        $useType = is_string($room?->use_type) && $room->use_type !== '' ? $room->use_type : null;

        return $useType;
    }

    /**
     * DossierManager::appendFloorLabel plakt “, kelder / souterrain” e.d. aan de kamernaam.
     * Die hoort niet in de klant-eyebrow tussen naam en “van N”.
     */
    private function stripAppendedFloorLabel(string $name): string
    {
        $suffixes = [
            ', kelder / souterrain',
            ', begane grond',
            ', 1e verdieping',
            ', 2e verdieping',
            ', 3e verdieping of hoger',
            ', zolder',
        ];

        foreach ($suffixes as $suffix) {
            if (str_ends_with(mb_strtolower($name), mb_strtolower($suffix))) {
                return trim(mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix)));
            }
        }

        return $name;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function roomInstanceTotal(Intake $intake, array $answers): int
    {
        $intake->loadMissing('aircoRooms');
        $fromRooms = $intake->aircoRooms->count();
        if ($fromRooms > 0) {
            return $fromRooms;
        }

        $types = RoomLabelResolver::typesByInstance($answers);

        return max(count($types), 0);
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function roomInstanceIndex(string $sectionInstanceKey, Intake $intake, array $answers): ?int
    {
        $intake->loadMissing('aircoRooms');
        if ($intake->aircoRooms->isNotEmpty()) {
            $ordered = $intake->aircoRooms->sortBy('key')->values();
            foreach ($ordered as $i => $room) {
                if ($room->key === $sectionInstanceKey) {
                    return $i + 1;
                }
            }
        }

        $types = RoomLabelResolver::typesByInstance($answers);
        $keys = array_keys($types);
        $pos = array_search($sectionInstanceKey, $keys, true);

        return $pos === false ? null : $pos + 1;
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

        // Nooit ruwe enum-values aan de klant tonen.
        return is_string($option?->label) && trim($option->label) !== ''
            ? $option->label
            : null;
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
            $label = $this->optionLabel($question, $value);
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * N56: hoogte is alleen nodig waar die het besluit verandert (RoomHeightRequirement: zolder).
     * Alleen overslaan als ruimtetype én verdieping bekend zijn en geen van beide zolder is.
     *
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function roomNeedsCeilingHeight(array $answers, ?string $sectionInstanceKey): bool
    {
        if ($sectionInstanceKey === null
            || isset(RoomLabelResolver::atticInstances($answers)[$sectionInstanceKey])) {
            return true;
        }

        $type = RoomLabelResolver::typesByInstance($answers)[$sectionInstanceKey] ?? null;
        $floor = $answers[VisibilityResolver::compositeKey('floor_level', $sectionInstanceKey)] ?? null;
        $floorValue = is_array($floor) ? ($floor['value'] ?? null) : null;

        // Alleen expliciet bekende niet-zolderwaarden; 'unknown' of iets anders → vraag blijft.
        return ! in_array($type, ['living_room', 'bedroom', 'office', 'other'], true)
            || ! in_array($floorValue, ['basement', 'ground', '1', '2', '3_plus'], true);
    }

    /**
     * room_name: dossier/wizard nummert per type (Slaapkamer 1, 2, …).
     * Niet standaard vragen — eigen namen via known-summary “Wijzigen” (forceShow).
     * Sticky-BL-140 dekt tekst-autosave via preferred_indoor_location.
     *
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function shouldAskRoomName(array $answers, ?string $sectionInstanceKey): bool
    {
        unset($answers, $sectionInstanceKey);

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    private function sectionTitleForInstance(
        Intake $intake,
        IntakeSection $section,
        ?string $sectionInstanceKey,
        array $answers = [],
    ): string {
        if ($sectionInstanceKey === null) {
            return $section->title;
        }

        $label = $this->instanceLabel($intake, $sectionInstanceKey, $answers);

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
     * Exact cursor match only (question + optional section/instance). No section-start fallback.
     *
     * @param  list<IntakeStep>  $steps
     */
    public function indexForExactCursor(
        array $steps,
        ?string $sectionKey,
        ?string $questionKey,
        ?string $sectionInstanceKey = null,
    ): ?int {
        if ($questionKey === null || $questionKey === '') {
            return null;
        }

        foreach ($steps as $index => $step) {
            $matchesKey = $step['question_key'] === $questionKey
                || (
                    ($step['kind'] ?? '') === 'question_group'
                    && in_array($questionKey, $step['group_question_keys'] ?? [], true)
                )
                || (
                    ($step['kind'] ?? '') === 'closing_wishes'
                    && in_array($questionKey, $step['bundle_question_keys'] ?? [], true)
                );

            if (! $matchesKey) {
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

        return null;
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
        $exact = $this->indexForExactCursor($steps, $sectionKey, $questionKey, $sectionInstanceKey);
        if ($exact !== null) {
            return $exact;
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
