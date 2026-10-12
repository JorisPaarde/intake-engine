<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeReview;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\CustomerConsentPresenter;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\OutdoorPhotoReuse;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AircoOptionStatus;
use App\Enums\DossierRecordStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class GenerateIntakeReportHtml
{
    private const INSTALLER_NOTE_METHODS = [
        'installer_note',
    ];

    /** Neutral report label when no installation option is selected yet (N35 rest). */
    public const NO_INSTALLATION_CHOICE_LABEL = 'Nog geen keuze gemaakt';

    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly VisibilityResolver $visibilityResolver,
        private readonly ExternalFactPresenter $externalFactPresenter,
        private readonly IntakeDossierSummaryBuilder $summaryBuilder,
        private readonly InstallerPhotoGalleryBuilder $photoGalleryBuilder,
        private readonly CustomerConsentPresenter $customerConsentPresenter,
    ) {}

    /**
     * @param  list<array{code: string, label: string}>  $attentionPoints
     * @param  array{summary: string, highlights: list<string>}|null  $aiSummary
     */
    public function handle(
        Intake $intake,
        IntakeTemplateVersion $version,
        array $attentionPoints = [],
        ?array $aiSummary = null,
    ): string {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules', 'template']);
        $intake->loadMissing([
            'answers',
            'uploads',
            'externalFacts',
            'followUpRounds.items.uploads',
            'review.reviewer',
            'dossierSubjects.records',
            'aircoInstallationOptions.placements.room',
            'aircoInstallationOptions.connections.fromPlacement',
            'aircoInstallationOptions.connections.toPlacement',
        ]);

        $sections = $this->buildReportSections($intake, $version);
        // PDF needs inline data_uri; web workspace/show use intakes.aerial.show.
        $externalData = $this->externalFactPresenter->present($intake, includeAerialDataUri: true);
        $followUpRounds = $intake->followUpRounds
            ->filter(static fn ($round): bool => $round->completed_at !== null)
            ->values();
        $review = $intake->review;
        $nextStep = $this->resolveNextStep($intake, $review, $followUpRounds, $externalData, $attentionPoints);

        return view('reports.intake-html', [
            'intake' => $intake,
            'version' => $version,
            'sections' => $sections,
            'attentionPoints' => $attentionPoints,
            'dossierSummary' => $this->summaryBuilder->build($intake, $version),
            'aiSummary' => $aiSummary,
            'externalData' => $externalData,
            'photoGroups' => $this->photoGalleryBuilder->handle($intake),
            'followUpRounds' => $followUpRounds,
            'nextStep' => $nextStep,
            'customerConsent' => $this->customerConsentPresenter->present($intake),
            'review' => $this->presentReview($intake, $review, $followUpRounds),
            'installerNotes' => $this->buildInstallerNotes($intake),
            'installationChoice' => $this->presentInstallationChoice($intake),
            'generatedAt' => now(),
        ])->render();
    }

    /**
     * @param  Collection<int, mixed>  $followUpRounds
     * @param  array{uncertainties: list<string>}  $externalData
     * @param  list<array{code: string, label: string}>  $attentionPoints
     */
    private function resolveNextStep(
        Intake $intake,
        ?IntakeReview $review,
        Collection $followUpRounds,
        array $externalData,
        array $attentionPoints,
    ): string {
        // Alleen de actuele beoordeling (intake.reviewed_at gezet) stuurt de slotzin.
        // Na afronden van een aanvullingsronde wist CompleteFollowUpRound reviewed_at;
        // de oude beslissing blijft zichtbaar in presentReview, maar is niet meer leidend.
        if ($review instanceof IntakeReview && $intake->reviewed_at !== null) {
            $decisionNextStep = match ($review->decision) {
                ReviewDecision::PrepareQuote => 'Bereid de offerte voor op basis van deze beoordeling.',
                ReviewDecision::SiteVisitNeeded => 'Plan een locatiebezoek; de beoordeling is vastgelegd.',
                ReviewDecision::NeedMoreInfo => 'Wacht op de aanvullende informatie van de klant en beoordeel daarna opnieuw.',
                ReviewDecision::NotSuitable => 'De aanvraag is niet passend; geen offerte voorbereiden.',
                ReviewDecision::Pending => null,
            };
            if ($decisionNextStep !== null) {
                return $decisionNextStep;
            }
        }

        if ($followUpRounds->isNotEmpty()) {
            return $externalData['uncertainties'] !== []
                ? 'Beoordeel de aangeleverde aanvulling, controleer de gemarkeerde onzekerheden en bepaal daarna de offerte of volgende stap.'
                : 'Beoordeel de aangeleverde aanvulling en bepaal daarna de offerte of volgende stap.';
        }

        if ($externalData['uncertainties'] !== []) {
            return 'Controleer eerst de gemarkeerde onzekerheden en bepaal daarna of een gerichte vraag of locatiebezoek nodig is.';
        }

        if ($attentionPoints !== []) {
            return 'Beoordeel de aandachtspunten en bereid daarna de offerte of een gerichte vervolgvraag voor.';
        }

        return 'De aanvraag is compleet; bereid de offerte voor.';
    }

    /**
     * Toon de laatst opgeslagen beoordeling (besluit, samenvatting, beoordelaar, datum).
     * Ook na een afgeronde aanvullingsronde (intake.reviewed_at leeg): het blok blijft
     * staan met een markering dat er daarna nieuwe aanvulling is. Pending blijft verborgen.
     * De next-step-tekst (resolveNextStep) blijft herbeoordeling vragen zolang
     * intake.reviewed_at leeg is — alleen de weergave van het opgeslagen besluit blijft.
     *
     * @param  Collection<int, mixed>  $followUpRounds  alleen afgeronde rondes
     * @return array{
     *     decision_label: string,
     *     site_visit_needed: bool,
     *     enough_information: bool,
     *     summary: string|null,
     *     reviewed_at: Carbon|null,
     *     reviewer_name: string|null,
     *     stale_before_round_notice: string|null
     * }|null
     */
    private function presentReview(Intake $intake, ?IntakeReview $review, Collection $followUpRounds): ?array
    {
        if (! $review instanceof IntakeReview || $review->decision === ReviewDecision::Pending) {
            return null;
        }

        return [
            'decision_label' => $review->decision->label(),
            'site_visit_needed' => (bool) $review->site_visit_needed,
            'enough_information' => (bool) $review->enough_information,
            'summary' => $review->summary,
            'reviewed_at' => $review->reviewed_at,
            'reviewer_name' => $review->reviewer?->name,
            'stale_before_round_notice' => $this->reviewStaleBeforeRoundNotice(
                $intake,
                $review,
                $followUpRounds,
            ),
        ];
    }

    /**
     * Markering wanneer intake.reviewed_at leeg is en er een afgeronde ronde is
     * ná de review-timestamp (review.reviewed_at vs. round.completed_at).
     *
     * @param  Collection<int, mixed>  $followUpRounds
     */
    private function reviewStaleBeforeRoundNotice(
        Intake $intake,
        IntakeReview $review,
        Collection $followUpRounds,
    ): ?string {
        if ($intake->reviewed_at !== null) {
            return null;
        }

        $reviewAt = $review->reviewed_at;
        if ($reviewAt === null) {
            return null;
        }

        $firstRoundAfter = $followUpRounds
            ->filter(static function (mixed $round) use ($reviewAt): bool {
                $completedAt = $round->completed_at ?? null;

                return $completedAt instanceof Carbon && $completedAt->greaterThan($reviewAt);
            })
            ->sortBy(static fn (mixed $round): int => (int) $round->round_number)
            ->first();

        if ($firstRoundAfter === null) {
            return null;
        }

        $roundNumber = (int) $firstRoundAfter->round_number;

        return 'Beoordeeld vóór ronde '.$roundNumber.'. Bekijk de nieuwe aanvulling.';
    }

    /**
     * @return list<array{subject_label: string, text: string}>
     */
    private function buildInstallerNotes(Intake $intake): array
    {
        $notes = [];

        foreach ($intake->dossierSubjects as $subject) {
            foreach ($subject->records as $record) {
                if (! $this->isInstallerNoteRecord($record)) {
                    continue;
                }

                $text = $record->value['text'] ?? null;
                if (! is_string($text) || trim($text) === '') {
                    continue;
                }

                $notes[] = [
                    'subject_label' => $subject->label,
                    'text' => $text,
                ];
            }
        }

        return $notes;
    }

    private function isInstallerNoteRecord(DossierRecord $record): bool
    {
        return $record->status === DossierRecordStatus::Established
            && $record->source_type === 'installer'
            && in_array($record->method, self::INSTALLER_NOTE_METHODS, true)
            && $record->superseded_by_id === null;
    }

    /**
     * Installation choice + per-connection route status for the report (N35).
     * Always returns a status: selected option as before, or neutral
     * {@see NO_INSTALLATION_CHOICE_LABEL} with candidate proposals when none is selected.
     *
     * @return array{
     *     has_selection: bool,
     *     choice_status_label: string,
     *     label: string,
     *     configuration_label: string,
     *     summary: string|null,
     *     units: list<string>,
     *     routes: list<array{
     *         type_label: string,
     *         label: string,
     *         from: string,
     *         to: string,
     *         segments: list<string>,
     *         status_label: string,
     *         installer_note: string|null,
     *         uncertainties: list<string>
     *     }>,
     *     proposals: list<array{
     *         label: string,
     *         configuration_label: string,
     *         summary: string|null,
     *         units: list<string>,
     *         routes: list<array{
     *             type_label: string,
     *             label: string,
     *             from: string,
     *             to: string,
     *             segments: list<string>,
     *             status_label: string,
     *             installer_note: string|null,
     *             uncertainties: list<string>
     *         }>
     *     }>
     * }
     */
    private function presentInstallationChoice(Intake $intake): array
    {
        /** @var AircoInstallationOption|null $selected */
        $selected = $intake->aircoInstallationOptions
            ->first(static fn (AircoInstallationOption $candidate): bool => $candidate->status === AircoOptionStatus::Selected);

        if ($selected instanceof AircoInstallationOption) {
            $payload = $this->presentInstallationOption($intake, $selected);

            return [
                'has_selection' => true,
                'choice_status_label' => $payload['label'],
                'label' => $payload['label'],
                'configuration_label' => $payload['configuration_label'],
                'summary' => $payload['summary'],
                'units' => $payload['units'],
                'routes' => $payload['routes'],
                'proposals' => [],
            ];
        }

        $proposals = [];
        foreach ($intake->aircoInstallationOptions as $option) {
            if ($option->status === AircoOptionStatus::Rejected) {
                continue;
            }

            $proposals[] = $this->presentInstallationOption($intake, $option);
        }

        return [
            'has_selection' => false,
            'choice_status_label' => self::NO_INSTALLATION_CHOICE_LABEL,
            'label' => self::NO_INSTALLATION_CHOICE_LABEL,
            'configuration_label' => '',
            'summary' => null,
            'units' => [],
            'routes' => [],
            'proposals' => $proposals,
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     configuration_label: string,
     *     summary: string|null,
     *     units: list<string>,
     *     routes: list<array{
     *         type_label: string,
     *         label: string,
     *         from: string,
     *         to: string,
     *         segments: list<string>,
     *         status_label: string,
     *         installer_note: string|null,
     *         uncertainties: list<string>
     *     }>
     * }
     */
    private function presentInstallationOption(Intake $intake, AircoInstallationOption $option): array
    {
        $units = [];
        foreach ($option->placements as $placement) {
            $parts = [$placement->type->label(), $placement->label];
            if ($placement->room?->name) {
                $parts[] = $placement->room->name;
            }
            $units[] = implode(' · ', $parts);
        }

        $routes = [];
        foreach ($option->connections as $connection) {
            $rawSegments = $connection->segments ?? [];
            $segments = [];
            foreach ($rawSegments as $segment) {
                if (trim($segment) !== '') {
                    $segments[] = $segment;
                }
            }

            $fromPlacement = $connection->fromPlacement;
            $toPlacement = $connection->toPlacement;

            $routes[] = [
                'type_label' => $connection->type->label(),
                'label' => $connection->label,
                'from' => $fromPlacement !== null ? $fromPlacement->label : 'Beginpunt open',
                'to' => $toPlacement !== null ? $toPlacement->label : 'Eindpunt open',
                'segments' => $segments,
                'status_label' => $connection->status->label(),
                'installer_note' => $this->connectionInstallerNote($intake, $connection),
                'uncertainties' => $this->connectionUncertaintyList($connection),
            ];
        }

        return [
            'label' => $option->label,
            'configuration_label' => $option->configuration_type->label(),
            'summary' => $option->summary,
            'units' => $units,
            'routes' => $routes,
        ];
    }

    private function connectionInstallerNote(Intake $intake, AircoConnection $connection): ?string
    {
        $subject = $intake->dossierSubjects->firstWhere('id', $connection->dossier_subject_id);
        if ($subject === null) {
            return null;
        }

        foreach ($subject->records as $record) {
            if (! $this->isInstallerNoteRecord($record)) {
                continue;
            }

            $text = $record->value['text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function connectionUncertaintyList(AircoConnection $connection): array
    {
        $list = [];
        foreach ($connection->uncertainties ?? [] as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $list[] = $text;
            }
        }

        return $list;
    }

    /**
     * @return list<array{title: string, instance_label: string|null, questions: list<array{label: string, display: string, is_photo: bool}>}>
     */
    private function buildReportSections(Intake $intake, IntakeTemplateVersion $version): array
    {
        /** @var Collection<int, IntakeSection> $sections */
        $sections = $version->sections;
        $questions = $sections->flatMap(static fn (IntakeSection $section): Collection => $section->questions)->values();
        $answers = $this->buildAnswerMap($intake);
        $questionTypes = [];
        $sectionsByQuestionKey = [];

        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
            }
        }

        $targets = $this->buildTargets($sections, $answers, $questionTypes);
        $visibility = $this->visibilityResolver->resolve(
            $questions,
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
        );

        $answerModels = $this->buildAnswerModelMap($intake);
        $reportSections = [];

        foreach ($targets as $target) {
            $question = $this->findQuestion($sections, $target['question_key']);

            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            $composite = VisibilityResolver::compositeKey(
                $target['question_key'],
                $target['section_instance_key'],
            );
            $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];

            if (! $state['visible']) {
                continue;
            }

            $section = $sectionsByQuestionKey[$question->key] ?? null;

            if ($section === null) {
                continue;
            }

            $value = $answers[$composite] ?? null;
            $answerModel = $answerModels[$composite] ?? null;
            $display = $this->presentQuestionDisplay($intake, $question, $value, $answerModel);

            if ($display === null) {
                continue;
            }

            $sectionTitle = $section->title;
            $instanceLabel = $target['section_instance_key'];
            $bucketKey = $section->key.'|'.($instanceLabel ?? '');

            if (! isset($reportSections[$bucketKey])) {
                $reportSections[$bucketKey] = [
                    'title' => $sectionTitle,
                    'instance_label' => $instanceLabel,
                    'questions' => [],
                ];
            }

            $reportSections[$bucketKey]['questions'][] = [
                'label' => $question->label,
                'display' => $display,
                'is_photo' => $question->type === QuestionType::Photo,
            ];
        }

        return array_values($reportSections);
    }

    /**
     * @return array<string, IntakeAnswer>
     */
    private function buildAnswerModelMap(Intake $intake): array
    {
        $map = [];

        foreach ($intake->answers as $answer) {
            $key = VisibilityResolver::compositeKey(
                $answer->question_key,
                $answer->section_instance_key,
            );
            $map[$key] = $answer;
        }

        return $map;
    }

    /**
     * Provenance-bewaking voor rapportregels (N36/N42): lege rijen weg;
     * AI-`unknown` zonder klantvraag verdwijnt; bronlabels volgen echte herkomst.
     *
     * @param  array<string, mixed>|null  $value
     */
    private function presentQuestionDisplay(
        Intake $intake,
        IntakeQuestion $question,
        ?array $value,
        ?IntakeAnswer $answer,
    ): ?string {
        if ($question->key === OutdoorPhotoReuse::TARGET_KEY
            && OutdoorPhotoReuse::hasUsableOutdoorContext($intake)) {
            // Bruikbare buiten-/gevel-foto: rondom-huis-vraag was verborgen — geen lege rij.
            return null;
        }

        if ($question->key === 'fusebox_clarity') {
            $clarity = $this->formatFuseboxClarity($intake, $question, $value);

            return $clarity === '—' || $clarity === '' ? null : $clarity;
        }

        $rawChoice = $value['value'] ?? null;
        $prefillSource = $answer?->prefill_source;
        $isCustomerAnswer = $prefillSource === null || $prefillSource === '';

        if (! $isCustomerAnswer && is_string($rawChoice) && $rawChoice === 'unknown') {
            // AI-/prefill-unknown is geen klant-"Weet ik niet".
            return null;
        }

        $formatted = $this->formatValue($question, $value);
        if ($formatted === '—' || $formatted === '') {
            return null;
        }

        if ($question->type === QuestionType::Photo || $isCustomerAnswer) {
            // Klantantwoord: geen herkomst-suffix (N42 review).
            return $formatted;
        }

        return $formatted.' ('.$this->answerProvenanceLabel($prefillSource, $answer).')';
    }

    /**
     * Herkomstlabel voor niet-klant antwoorden.
     *
     * «Voorzet uit aanvraag, bevestigd door klant» alleen bij opgeslagen bewijs:
     * fact_source=klantantwoord met behouden prefill (known-summary Wijzigen → zelfde
     * waarde herstelt prefill_source na SaveIntakeAnswer). Er is geen apart veld/event
     * voor known-summary «Klopt, verder» — die route laat prefill+fact_source ongemoeid.
     */
    private function answerProvenanceLabel(?string $prefillSource, ?IntakeAnswer $answer): string
    {
        if (in_array($prefillSource, ['pdok', 'bag'], true)) {
            return 'Voorzet uit BAG';
        }

        if ($prefillSource === 'epo') {
            return 'Voorzet uit energielabel';
        }

        if (PrefillSources::isPhotoSuggestion($prefillSource)
            || $prefillSource === PrefillSources::AI_PHOTO) {
            return 'Voorzet uit foto';
        }

        if ($prefillSource === PrefillSources::DERIVED_LXW) {
            return 'Berekend';
        }

        $provenance = FactProvenance::tryFromMixed($answer?->fact_provenance);

        // Bevestigd: klantfact met behouden voorzet-bron (niet BAG/EPO — die mappen ook op klantantwoord).
        if ($answer !== null
            && FactSource::tryFrom((string) ($answer->fact_source ?? '')) === FactSource::CustomerAnswer
            && ! in_array($prefillSource, ['pdok', 'bag', 'epo'], true)) {
            return 'Voorzet uit aanvraag, bevestigd door klant';
        }

        if ($prefillSource === 'installer') {
            return PrefillSources::installerSourceLabel($prefillSource, $provenance);
        }

        if (PrefillSources::isTextSuggestion($prefillSource)
            || PrefillSources::isTextDerived($prefillSource)
            || $prefillSource === PrefillSources::AI_LEGACY
            || $prefillSource === PrefillSources::AI_SUGGESTION_LEGACY) {
            return 'Voorzet uit tekst';
        }

        return PrefillSources::installerSourceLabel($prefillSource, $provenance);
    }

    /**
     * Interne engine-vraag: toon assessment-status (Actueel/galerij-bron), niet het
     * opgeslagen clear/needs_clearer_photo-antwoord (N19: anders “Duidelijk genoeg”
     * terwijl de AI een meterkastfoto afkeurde en de klant Toch doorgaan koos).
     *
     * @param  array<string, mixed>|null  $value
     */
    private function formatFuseboxClarity(Intake $intake, IntakeQuestion $question, ?array $value): string
    {
        $uploads = [
            ...PhotoContinueAnywayAttention::currentWizardUploadsAt($intake, 'fusebox_photo', null),
            ...PhotoContinueAnywayAttention::currentWizardUploadsAt($intake, 'fusebox_photo_extra', null),
        ];

        // Korte status i.p.v. de Actueel-zin, zodat “AI keurde… Toch doorgaan”
        // niet dubbel in het rapport staat (N21).
        $fromAssessment = PhotoContinueAnywayAttention::fuseboxClarityReportLabel($uploads);
        if ($fromAssessment !== null) {
            return $fromAssessment;
        }

        return $this->formatValue($question, $value);
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private function buildAnswerMap(Intake $intake): array
    {
        $answers = [];

        foreach ($intake->answers as $answer) {
            $key = VisibilityResolver::compositeKey(
                $answer->question_key,
                $answer->section_instance_key,
            );
            $answers[$key] = $answer->value;
        }

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
     * @param  Collection<int, IntakeSection>  $sections
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     * @return list<array{question_key: string, section_instance_key: string|null}>
     */
    private function buildTargets(Collection $sections, array $answers, array $questionTypes): array
    {
        $targets = [];

        foreach ($sections as $section) {
            if ($section->is_repeatable) {
                $count = $this->repeatInstanceCount($section, $answers, $questionTypes);

                for ($index = 1; $index <= $count; $index++) {
                    $instanceKey = Str::singular($section->key).'-'.$index;

                    foreach ($section->questions as $question) {
                        $targets[] = [
                            'question_key' => $question->key,
                            'section_instance_key' => $instanceKey,
                        ];
                    }
                }

                continue;
            }

            foreach ($section->questions as $question) {
                $targets[] = [
                    'question_key' => $question->key,
                    'section_instance_key' => null,
                ];
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, QuestionType>  $questionTypes
     */
    private function repeatInstanceCount(IntakeSection $section, array $answers, array $questionTypes): int
    {
        $countQuestionKey = $section->repeat_count_question_key;

        if ($countQuestionKey === null || $countQuestionKey === '') {
            return 0;
        }

        $type = $questionTypes[$countQuestionKey] ?? null;

        if ($type !== QuestionType::Number) {
            return 0;
        }

        $answerKey = VisibilityResolver::compositeKey($countQuestionKey, null);
        $number = $this->answerValueReader->readComparable($answers[$answerKey] ?? null, $type);

        if (! is_numeric($number)) {
            return 0;
        }

        return max(0, (int) $number);
    }

    /**
     * @param  Collection<int, IntakeSection>  $sections
     */
    private function findQuestion(Collection $sections, string $questionKey): ?IntakeQuestion
    {
        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $question;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function formatValue(IntakeQuestion $question, ?array $value): string
    {
        if (! $this->answerValueReader->isFilled($value, $question->type)) {
            return '—';
        }

        return match ($question->type) {
            QuestionType::ShortText, QuestionType::LongText => (string) ($value['text'] ?? ''),
            QuestionType::Number => (string) ($value['number'] ?? ''),
            QuestionType::SingleChoice => $this->optionLabel($question, (string) ($value['value'] ?? '')),
            QuestionType::MultiChoice => implode(', ', array_map(
                fn (mixed $v): string => $this->optionLabel($question, (string) $v),
                is_array($value['values'] ?? null) ? array_values($value['values']) : [],
            )),
            QuestionType::Boolean => ($value['bool'] ?? false) ? 'Ja' : 'Nee',
            QuestionType::Photo => count($value['upload_ids'] ?? []).' foto(s)',
        };
    }

    private function optionLabel(IntakeQuestion $question, string $optionValue): string
    {
        $question->loadMissing('options');

        foreach ($question->options as $option) {
            if ($option->value === $optionValue) {
                return $option->label;
            }
        }

        return $optionValue;
    }
}
