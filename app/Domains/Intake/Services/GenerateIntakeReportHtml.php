<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Support\CustomerConsentPresenter;
use App\Enums\QuestionType;
use Illuminate\Support\Collection;

final class GenerateIntakeReportHtml
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly IntakeAnswerTargets $answerTargets,
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
        $intake->loadMissing(['answers', 'uploads', 'externalFacts', 'followUpRounds.items.uploads']);

        $sections = $this->buildReportSections($intake, $version);
        // PDF needs inline data_uri; web workspace/show use intakes.aerial.show.
        $externalData = $this->externalFactPresenter->present($intake, includeAerialDataUri: true);
        $followUpRounds = $intake->followUpRounds
            ->filter(static fn ($round): bool => $round->completed_at !== null)
            ->values();
        $nextStep = $followUpRounds->isNotEmpty()
            ? ($externalData['uncertainties'] !== []
                ? 'Beoordeel de aangeleverde aanvulling, controleer de gemarkeerde onzekerheden en bepaal daarna de offerte of volgende stap.'
                : 'Beoordeel de aangeleverde aanvulling en bepaal daarna de offerte of volgende stap.')
            : ($externalData['uncertainties'] !== []
                ? 'Controleer eerst de gemarkeerde onzekerheden en bepaal daarna of een gerichte vraag of locatiebezoek nodig is.'
            : ($attentionPoints !== []
                ? 'Beoordeel de aandachtspunten en bereid daarna de offerte of een gerichte vervolgvraag voor.'
                : 'De aanvraag is compleet; bereid de offerte voor.'));

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
            'generatedAt' => now(),
        ])->render();
    }

    /**
     * @return list<array{title: string, instance_label: string|null, questions: list<array{label: string, display: string, is_photo: bool}>}>
     */
    private function buildReportSections(Intake $intake, IntakeTemplateVersion $version): array
    {
        /** @var Collection<int, IntakeSection> $sections */
        $sections = $version->sections;
        $questions = $sections->flatMap(static fn (IntakeSection $section): Collection => $section->questions)->values();
        $answers = $this->answerTargets->answerMap($intake);
        $questionTypes = [];
        $sectionsByQuestionKey = [];

        foreach ($sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
            }
        }

        $targets = $this->answerTargets->targets($sections, $answers, $questionTypes);
        $visibility = $this->visibilityResolver->resolve(
            $questions,
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
        );

        $reportSections = [];

        foreach ($targets as $target) {
            $question = $version->findQuestion($target['question_key']);

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
                'display' => $this->formatValue($question, $value),
                'is_photo' => $question->type === QuestionType::Photo,
            ];
        }

        return array_values($reportSections);
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
