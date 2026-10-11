<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeReview;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\CustomerConsentPresenter;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
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
            'review' => $this->presentReview($intake, $review),
            'installerNotes' => $this->buildInstallerNotes($intake),
            'selectedInstallationOption' => $this->presentSelectedInstallationOption($intake),
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
        // Alleen de actuele beoordeling stuurt de slotzin. Na afronden van een
        // aanvullingsronde wist CompleteFollowUpRound reviewed_at; de oude
        // NeedMoreInfo-beslissing blijft bewaard maar is niet meer leidend.
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
     * Toon de opgeslagen beoordeling wanneer die nog actueel is (reviewed_at gezet).
     * Na een afgeronde aanvullingsronde is reviewed_at leeggemaakt; de installateur
     * moet opnieuw beslissen — de oude NeedMoreInfo-tekst hoort dan niet meer in
     * het rapport als definitieve beoordeling.
     *
     * @return array{
     *     decision_label: string,
     *     site_visit_needed: bool,
     *     enough_information: bool,
     *     summary: string|null,
     *     reviewed_at: Carbon|null,
     *     reviewer_name: string|null
     * }|null
     */
    private function presentReview(Intake $intake, ?IntakeReview $review): ?array
    {
        if (! $review instanceof IntakeReview
            || $review->decision === ReviewDecision::Pending
            || $intake->reviewed_at === null) {
            return null;
        }

        return [
            'decision_label' => $review->decision->label(),
            'site_visit_needed' => (bool) $review->site_visit_needed,
            'enough_information' => (bool) $review->enough_information,
            'summary' => $review->summary,
            'reviewed_at' => $review->reviewed_at,
            'reviewer_name' => $review->reviewer?->name,
        ];
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
     * @return array{
     *     label: string,
     *     configuration_label: string,
     *     summary: string|null,
     *     units: list<string>,
     *     routes: list<array{type_label: string, label: string, from: string, to: string, segments: list<string>}>
     * }|null
     */
    private function presentSelectedInstallationOption(Intake $intake): ?array
    {
        /** @var AircoInstallationOption|null $option */
        $option = $intake->aircoInstallationOptions
            ->first(static fn (AircoInstallationOption $candidate): bool => $candidate->status === AircoOptionStatus::Selected);

        if (! $option instanceof AircoInstallationOption) {
            return null;
        }

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

            $value = $answers[$composite] ?? null;
            $reportSections[$bucketKey]['questions'][] = [
                'label' => $question->label,
                'display' => $question->key === 'fusebox_clarity'
                    ? $this->formatFuseboxClarity($intake, $question, $value)
                    : $this->formatValue($question, $value),
                'is_photo' => $question->type === QuestionType::Photo,
            ];
        }

        return array_values($reportSections);
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

        $fromAssessment = PhotoContinueAnywayAttention::statusSentences($uploads);
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
