<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\AssessPhotoUsability;
use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\DeleteFollowUpUpload;
use App\Domains\Intake\Actions\DeleteIntakeUpload;
use App\Domains\Intake\Actions\SaveFollowUpTextResponse;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AnswerValueReader;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\FollowUpProgressCalculator;
use App\Domains\Intake\Services\IntakePrefillResolver;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Domains\Intake\Support\KnownSummaryCatalog;
use App\Domains\Intake\Support\PhotoContentSatisfaction;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\QuestionType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.customer')]
class IntakeWizard extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $intakeId;

    #[Locked]
    public string $token = '';

    public int $stepIndex = 0;

    /** Stable step identity while visibility/step list may shift after an answer. */
    public string $activeStepKey = '';

    /**
     * Previous customer step keys — used by realignToActiveStep when the step list shrinks.
     *
     * @var list<string>
     */
    public array $knownStepKeys = [];

    /** @var array<string, mixed> */
    public array $form = [];

    /**
     * Composite key → one file or a list (multiselect, BL-021).
     *
     * @var array<string, TemporaryUploadedFile|array<int, TemporaryUploadedFile>|null>
     */
    public array $photoFiles = [];

    /**
     * Composite key → labelled prefill notice for the applicant (BL-016).
     * A prefill is a *voorzet*: the value sits editable in the form and is only
     * persisted once the applicant advances.
     *
     * @var array<string, string>
     */
    public array $prefillNotice = [];

    /**
     * Composite key → non-blocking photo-usability hint after upload (BL-007).
     *
     * @var array<string, string|null>
     */
    public array $photoHint = [];

    public string $saveMessage = '';

    public bool $showMissing = false;

    public bool $completed = false;

    public bool $followUpMode = false;

    #[Locked]
    public int $followUpRoundId = 0;

    public int $followUpStepIndex = 0;

    /** @var array<int, string|null> */
    public array $followUpResponses = [];

    /** @var array<int, TemporaryUploadedFile|array<int, TemporaryUploadedFile>|null> */
    public array $followUpPhotoFiles = [];

    /** @var array<int, TemporaryUploadedFile|array<int, TemporaryUploadedFile>|null> */
    public array $followUpDocumentFiles = [];

    /** @var list<array{question_key: string, section_instance_key: string|null, reason: string, label?: string, instance_label?: string|null}> */
    public array $completionMissing = [];

    /**
     * Klantzichtbare uploadfase: assessing | failed | '' (idle).
     * Uploaden zelf toont de client via wire:loading.
     */
    public string $uploadPhase = '';

    public string $uploadPhaseMessage = '';

    #[Locked]
    public string $uploadPhaseComposite = '';

    /** Korte uitleg wanneer analyse een extra klanttaak toevoegt. */
    public string $progressExtraNote = '';

    /**
     * Upload-ids per composite/follow-up-item die nog beoordeeld moeten worden.
     *
     * @var array<string, list<int>>
     */
    #[Locked]
    public array $pendingAssessUploadIds = [];

    /** Voorkomt herhaalde recover-js binnen één request-lifecycle. */
    private bool $queuedUnassessedRecovery = false;

    /**
     * Composites revealed via known-summary “Wijzigen” without clearing prefill_source yet.
     *
     * @var list<string>
     */
    public array $forceShowKnown = [];

    /**
     * Original prefill snapshot per composite while a known-summary edit is open.
     *
     * @var array<string, array{prefill_source: string, value: array<string, mixed>|null}>
     */
    public array $pendingKnownEdits = [];

    /**
     * Request-local caches (BL-025). Not public — Livewire does not dehydrate these
     * across requests; they only collapse duplicate queries within one lifecycle.
     */
    private ?Intake $resolvedIntake = null;

    private ?IntakeTemplateVersion $resolvedVersion = null;

    /**
     * @var list<array{
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
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string
     *     }>
     * }>|null
     */
    private ?array $resolvedSteps = null;

    private ?string $resolvedStepsFormSignature = null;

    public function mount(string $token): void
    {
        $this->token = $token;

        $intake = request()->attributes->get('customer_intake');

        if (! $intake instanceof Intake) {
            $intake = app(ResolveIntakeByAccessToken::class)->handle($token);
        }

        $this->intakeId = $intake->id;

        if ($intake->status === IntakeStatus::AwaitingCustomer) {
            $this->resolvedIntake = $intake->loadMissing(['answers', 'uploads']);
            $round = $intake->followUpRounds()
                ->where('status', FollowUpRoundStatus::Open)
                ->with('items')
                ->latest('round_number')
                ->firstOrFail();

            $this->followUpMode = true;
            $this->followUpRoundId = $round->id;
            $this->followUpResponses = $round->items
                ->mapWithKeys(static fn (IntakeFollowUpItem $item): array => [$item->id => $item->response_text])
                ->all();

            return;
        }

        // Herstelt ook eerder aangemaakte opnames waarvan de installateur de openingszin
        // al invulde. Alleen de lokale, evidente parser draait hier; een externe call
        // hoort niet stil bij iedere geopende klantlink te starten.
        app(DeriveIntentFromRequest::class)->handle($intake, allowExternal: false);
        $intake = $intake->fresh() ?? $intake;
        $this->resolvedIntake = $intake->loadMissing(['answers', 'uploads']);

        $this->hydrateFormFromAnswers();

        $steps = $this->steps();
        $this->stepIndex = app(IntakeStepBuilder::class)->indexForCursor(
            $steps,
            $intake->current_section_key,
            $intake->current_question_key,
            $intake->current_section_instance_key,
        );
        $this->clampStepIndex($steps);
        $this->syncActiveStepKey($steps);
        $this->knownStepKeys = array_map(
            static fn (array $step): string => $step['key'],
            $steps,
        );
        $this->applyPrefillForActiveStep();
        $this->recoverUnassessedUploads();
    }

    public function hydrate(): void
    {
        $intake = app(ResolveIntakeByAccessToken::class)->handle($this->token);
        abort_unless($intake->id === $this->intakeId, 404);

        $this->resolvedIntake = $intake->loadMissing(['answers', 'uploads']);
    }

    public function render(): View
    {
        $intake = $this->intake();
        $this->recoverUnassessedUploads();

        if ($this->followUpMode) {
            return $this->renderFollowUp($intake);
        }

        $version = $this->version();
        $steps = $this->steps();
        $this->clampStepIndex($steps);
        $step = $steps[$this->stepIndex] ?? null;
        // Banner-variant for the primary demo customer wizard (not a shortened allowlist).
        $demoCustomerPath = $intake->is_demo && ! $this->followUpMode;
        $progress = app(ProgressCalculator::class)->calculate($intake, $version);

        $question = null;
        $visibility = [];
        $uploadsByQuestion = [];
        $displayPhotoHint = $this->photoHint;
        $photoMismatchAssessment = null;

        if ($step !== null && ! $this->completed && ($step['kind'] ?? 'question') !== 'known_summary') {
            $question = app(IntakeStepBuilder::class)->questionForStep(
                $version,
                $step['section_key'],
                $step['question_key'],
            );

            if ($question instanceof IntakeQuestion) {
                $visibility = $this->visibilityForQuestions(
                    collect([$question]),
                    $step['section_instance_key'],
                );
                $uploadsByQuestion = $this->uploadsForStep($step['section_instance_key']);
                $this->ensureAnswerShape($question, $step['section_instance_key']);

                if ($question->type === QuestionType::Photo) {
                    $composite = VisibilityResolver::compositeKey(
                        $question->key,
                        $step['section_instance_key'],
                    );

                    if (empty($displayPhotoHint[$composite])) {
                        $persistentHint = $this->persistentIntakePhotoHint(
                            $intake,
                            $question,
                            $uploadsByQuestion[$question->key] ?? collect(),
                        );

                        if ($persistentHint !== null) {
                            $displayPhotoHint[$composite] = $persistentHint;
                        }
                    }

                    $photoMismatchAssessment = null;
                    $stepUploads = $uploadsByQuestion[$question->key] ?? collect();

                    // Banner alleen zolang de vraag niet content-satisfait is.
                    if (! PhotoContentSatisfaction::uploadsSatisfy($stepUploads)) {
                        $photoMismatchAssessment = PhotoContentSatisfaction::unresolvedWrongSubject(
                            $stepUploads,
                        );
                    }
                }
            }
        }

        $demoAiSummary = null;
        $demoAttentionPoints = [];

        if ($this->completed && $intake->is_demo) {
            $intake->loadMissing(['report', 'attentionPoints']);
            $meta = $intake->report?->meta;
            $metaSummary = is_array($meta) ? ($meta['ai_summary'] ?? null) : null;
            $demoAiSummary = is_array($metaSummary) ? $metaSummary : null;
            $demoAttentionPoints = $intake->attentionPoints
                ->where('status', AttentionPointStatus::Proposed)
                ->where('source', AttentionPointSource::Ai)
                ->values()
                ->all();
        }

        return view('livewire.customer.intake-wizard', [
            'intake' => $intake,
            'steps' => $steps,
            'step' => $step,
            'question' => $question,
            'visibility' => $visibility,
            'uploadsByQuestion' => $uploadsByQuestion,
            'displayPhotoHint' => $displayPhotoHint,
            'photoMismatchAssessment' => $photoMismatchAssessment,
            // Prop name kept for BL-076 banner sibling; value means "primary customer path".
            'demoShortCustomer' => $demoCustomerPath,
            'demoInstallerReturnUrl' => $demoCustomerPath
                ? route('intakes.show', $intake)
                : null,
            'progressPercent' => $this->completed ? 100 : $progress['percent'],
            'progressAnswered' => $progress['answered_required'],
            'progressTotal' => $progress['total_required'],
            'progressExtraNote' => $this->progressExtraNote,
            'uploadPhase' => $this->uploadPhase,
            'uploadPhaseMessage' => $this->uploadPhaseMessage,
            'uploadPhaseComposite' => $this->uploadPhaseComposite,
            'missingRequired' => $this->completionMissing !== []
                ? $this->completionMissing
                : $progress['missing_required'],
            'isLastStep' => $this->stepIndex >= count($steps) - 1,
            'maxUploadKb' => (int) config('intake.uploads.max_kilobytes', 5120),
            'demoAiSummary' => $demoAiSummary,
            'demoAttentionPoints' => $demoAttentionPoints,
        ]);
    }

    public function updatedPhotoFiles(mixed $value, ?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $files = $this->normalizeUploadFiles($this->photoFiles[$key] ?? $value);

        if ($files === []) {
            return;
        }

        $this->uploadPhotosForComposite($key, $files);
    }

    public function updatedFollowUpPhotoFiles(mixed $value, ?string $key): void
    {
        $this->uploadFollowUpFiles($value, $key, FollowUpItemType::Photo);
    }

    public function updatedFollowUpDocumentFiles(mixed $value, ?string $key): void
    {
        $this->uploadFollowUpFiles($value, $key, FollowUpItemType::Document);
    }

    public function removeFollowUpUpload(int $itemId, int $uploadId): void
    {
        $item = $this->followUpItem($itemId);
        $upload = IntakeUpload::query()->findOrFail($uploadId);

        try {
            app(DeleteFollowUpUpload::class)->handle($this->intake(), $item, $upload);
            $this->saveMessage = $item->type === FollowUpItemType::Photo
                ? 'Foto verwijderd'
                : 'Document verwijderd';
        } catch (ValidationException $exception) {
            $this->addError('follow_up', $exception->errors()['upload'][0]
                ?? $exception->errors()['photo'][0]
                ?? 'Verwijderen mislukt.');
        }
    }

    public function removePhoto(int $uploadId): void
    {
        $upload = IntakeUpload::query()->findOrFail($uploadId);

        try {
            app(DeleteIntakeUpload::class)->handle($this->intake(), $upload);

            // Een weggehaalde foto mag geen conclusie achterlaten die eruit was afgeleid.
            $this->invalidatePhotoDerivation($upload->question_key, $upload->section_instance_key);
            $this->runPhotoDerivation($upload->question_key, $upload->section_instance_key);

            $this->forgetIntakeDerivedCaches();
            $composite = VisibilityResolver::compositeKey(
                $upload->question_key,
                $upload->section_instance_key,
            );
            $this->refreshAnswerInForm($composite);
            $this->saveMessage = 'Foto verwijderd';
            $this->showMissing = false;
        } catch (ValidationException $e) {
            $this->addError('photo', $e->errors()['photo'][0] ?? 'Verwijderen mislukt.');
        }
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'followUpResponses.')) {
            $itemId = (int) substr($property, strlen('followUpResponses.'));

            if ($this->followUpMode && $itemId > 0) {
                app(SaveFollowUpTextResponse::class)->handle(
                    $this->intake(),
                    $this->followUpItem($itemId),
                    $this->followUpResponses[$itemId] ?? null,
                );
                $this->saveMessage = 'Opgeslagen';
            }

            return;
        }

        if (! str_starts_with($property, 'form.')) {
            return;
        }

        $remainder = substr($property, strlen('form.'));

        if (preg_match('/^(.*)\.(text|value|number|bool)$/', $remainder, $matches) === 1) {
            $composite = $matches[1];
            $field = $matches[2];

            unset($this->prefillNotice[$composite]);
            $this->persistComposite($composite);
            $this->saveMessage = 'Opgeslagen';
            $this->showMissing = false;
            $this->realignToActiveStep();
            $this->maybeAutoAdvanceAfterChoice($composite, $field);

            return;
        }

        if (preg_match('/^(.*)\.values(?:\.\d+)?$/', $remainder, $matches) === 1) {
            unset($this->prefillNotice[$matches[1]]);
            $this->persistComposite($matches[1]);
            $this->saveMessage = 'Opgeslagen';
            $this->showMissing = false;
            $this->realignToActiveStep();
        }
    }

    public function nextFollowUp(): void
    {
        if (! $this->currentFollowUpSatisfied()) {
            return;
        }

        $count = $this->followUpRound()->items->count();
        $this->followUpStepIndex = min($this->followUpStepIndex + 1, max(0, $count - 1));
        $this->saveMessage = '';
    }

    public function previousFollowUp(): void
    {
        $this->followUpStepIndex = max(0, $this->followUpStepIndex - 1);
        $this->saveMessage = '';
    }

    public function completeFollowUp(): void
    {
        if (! $this->currentFollowUpSatisfied()) {
            return;
        }

        try {
            app(CompleteFollowUpRound::class)->handle(
                $this->intake(),
                $this->followUpRound(),
                $this->followUpResponses,
            );
            $this->forgetIntakeDerivedCaches();
            $this->completed = true;
            $this->saveMessage = '';
        } catch (ValidationException $exception) {
            $this->addError('follow_up', $exception->errors()['follow_up'][0] ?? 'Aanvulling afronden mislukt.');
        }
    }

    private function renderFollowUp(Intake $intake): View
    {
        $round = $this->followUpRound();
        $items = $round->items->values();
        $this->followUpStepIndex = max(0, min($this->followUpStepIndex, max(0, $items->count() - 1)));
        $item = $items->get($this->followUpStepIndex);
        $progress = app(FollowUpProgressCalculator::class)->calculate($items, $this->followUpResponses);
        $currentStatus = $item instanceof IntakeFollowUpItem
            ? ($progress['item_statuses'][$item->id] ?? null)
            : null;

        return view('livewire.customer.follow-up-wizard', [
            'intake' => $intake,
            'round' => $round,
            'items' => $items,
            'item' => $item,
            'isLastStep' => $this->followUpStepIndex >= $items->count() - 1,
            'progressPercent' => $this->completed ? 100 : $progress['percent'],
            'progressCompleted' => $progress['completed'],
            'progressTotal' => $progress['total'],
            'currentItemStatus' => $currentStatus,
            'uploadPhase' => $this->uploadPhase,
            'uploadPhaseMessage' => $this->uploadPhaseMessage,
            'uploadPhaseComposite' => $this->uploadPhaseComposite,
            'followUpPhotoHint' => $item instanceof IntakeFollowUpItem
                && $item->type === FollowUpItemType::Photo
                ? $this->persistentFollowUpPhotoHint($item)
                : null,
            'choiceOptions' => $item instanceof IntakeFollowUpItem
                && $item->type === FollowUpItemType::Choice
                ? $this->followUpChoiceOptions($item)
                : [],
            'maxUploadKb' => (int) config('intake.uploads.max_kilobytes', 5120),
            'maxPhotos' => (int) config('intake.follow_up.max_photos_per_item', 5),
            'maxDocuments' => (int) config('intake.follow_up.max_documents_per_item', 3),
        ]);
    }

    private function followUpRound(): IntakeFollowUpRound
    {
        return IntakeFollowUpRound::query()
            ->with(['items.uploads'])
            ->where('intake_id', $this->intakeId)
            ->findOrFail($this->followUpRoundId);
    }

    private function followUpItem(int $itemId): IntakeFollowUpItem
    {
        return IntakeFollowUpItem::query()
            ->whereHas('round', fn ($query) => $query
                ->where('intake_id', $this->intakeId)
                ->where('id', $this->followUpRoundId))
            ->findOrFail($itemId);
    }

    private function currentFollowUpSatisfied(): bool
    {
        $item = $this->followUpRound()->items->get($this->followUpStepIndex);

        if (! $item instanceof IntakeFollowUpItem) {
            return false;
        }

        if ($item->type === FollowUpItemType::Text || $item->type === FollowUpItemType::Choice) {
            $response = trim((string) ($this->followUpResponses[$item->id] ?? ''));

            if ($response === '') {
                $this->addError(
                    'follow_up',
                    $item->type === FollowUpItemType::Choice
                        ? 'Kies eerst één van de opties.'
                        : 'Vul eerst een antwoord in.',
                );

                return false;
            }

            if ($item->type === FollowUpItemType::Choice) {
                $allowed = collect($this->followUpChoiceOptions($item))
                    ->pluck('value')
                    ->all();
                if (! in_array($response, $allowed, true)) {
                    $this->addError('follow_up', 'Kies een van de getoonde opties.');

                    return false;
                }
            }

            app(SaveFollowUpTextResponse::class)->handle($this->intake(), $item, $response);

            return true;
        }

        if ($item->uploads->isEmpty()) {
            $this->addError(
                'follow_up',
                $item->type === FollowUpItemType::Photo
                    ? 'Voeg eerst minimaal één foto toe.'
                    : 'Voeg eerst minimaal één PDF-document toe.',
            );

            return false;
        }

        // AI-contentverdict blokkeert afronden nooit; waarschuwing blijft zichtbaar via hint.

        return true;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function followUpChoiceOptions(IntakeFollowUpItem $item): array
    {
        $task = ContributionTask::query()
            ->where('intake_follow_up_item_id', $item->id)
            ->first();
        $meta = is_array($task?->meta) ? $task->meta : [];
        $choices = $meta['choices'] ?? [];

        if (! is_array($choices)) {
            return [];
        }

        $normalized = [];
        foreach ($choices as $choice) {
            if (! is_array($choice)) {
                continue;
            }
            $value = trim((string) ($choice['value'] ?? ''));
            $label = trim((string) ($choice['label'] ?? ''));
            if ($value === '' || $label === '') {
                continue;
            }
            $normalized[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * Commit a short_text/number value from Enter, then advance (BL-023).
     * Needed because wire:model.blur has not synced yet when Enter is pressed.
     */
    public function advanceFromEnter(string $composite, string $field, mixed $value): void
    {
        if ($this->completed || ! in_array($field, ['text', 'number'], true)) {
            return;
        }

        if (! is_array($this->form[$composite] ?? null)) {
            $this->form[$composite] = [];
        }

        $this->form[$composite][$field] = $value;

        unset($this->prefillNotice[$composite]);

        $leavingForcedEdit = in_array($composite, $this->forceShowKnown, true);
        $this->persistComposite($composite);
        $this->saveMessage = 'Opgeslagen';
        $this->showMissing = false;
        $this->realignToActiveStep();

        if ($leavingForcedEdit) {
            $this->leaveForcedKnownEdit();

            return;
        }

        $step = $this->currentStep();
        if (($step['kind'] ?? 'question') === 'known_summary') {
            return;
        }

        $this->next();
    }

    /**
     * @return list<TemporaryUploadedFile>
     */
    private function normalizeUploadFiles(mixed $raw): array
    {
        if ($raw instanceof TemporaryUploadedFile) {
            return [$raw];
        }

        if (! is_array($raw)) {
            return [];
        }

        $files = [];

        foreach ($raw as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function uploadFollowUpFiles(mixed $value, ?string $key, FollowUpItemType $type): void
    {
        if (! $this->followUpMode || $key === null || ! ctype_digit($key)) {
            return;
        }

        $itemId = (int) $key;
        $composite = (string) $itemId;
        $property = $type === FollowUpItemType::Photo
            ? 'followUpPhotoFiles'
            : 'followUpDocumentFiles';
        $errorBagKey = $property.'.'.$key;
        $this->resetErrorBag($errorBagKey);
        $files = $this->normalizeUploadFiles($this->{$property}[$itemId] ?? $value);

        if ($files === []) {
            return;
        }

        // Alleen deze item-fase resetten; pending van andere items blijft staan.
        $this->progressExtraNote = '';
        $this->clearPendingIdsFor($composite);
        if ($this->uploadPhaseComposite === $composite) {
            $this->uploadPhase = '';
            $this->uploadPhaseMessage = '';
        }

        $item = $this->followUpItem($itemId);
        $intake = $this->intake();
        $this->uploadPhaseComposite = $composite;

        $stored = 0;
        $error = null;
        $duplicateNotice = false;
        /** @var list<int> $storedUploadIds */
        $storedUploadIds = [];

        foreach ($files as $file) {
            try {
                $upload = app(StoreFollowUpUpload::class)->handle($intake, $item, $file);

                if (! $upload->wasRecentlyCreated) {
                    $duplicateNotice = true;

                    if ($type === FollowUpItemType::Photo && $upload->usability_verdict === null) {
                        $storedUploadIds[] = $upload->id;
                    }

                    continue;
                }

                $this->rememberStoredUpload($upload);
                $stored++;
                $storedUploadIds[] = $upload->id;
            } catch (ValidationException $exception) {
                $error = $exception->errors()['upload'][0]
                    ?? $exception->errors()['photo'][0]
                    ?? 'Bestand uploaden mislukt.';
            } catch (\Throwable $exception) {
                report($exception);
                $error = $this->customerThrowableMessage($exception, 'upload');
            }
        }

        $this->{$property}[$itemId] = null;
        $storedUploadIds = array_values(array_unique($storedUploadIds));

        if ($storedUploadIds !== [] && $type === FollowUpItemType::Photo) {
            $this->setPendingIdsFor($composite, $storedUploadIds);
            $this->setUploadPhase('assessing', 'Foto beoordelen…');
            $this->saveMessage = $duplicateNotice && $stored === 0
                ? 'Deze foto staat er al'
                : ($stored === 1 ? 'Foto opgeslagen' : ($stored > 1 ? "{$stored} foto's opgeslagen" : 'Deze foto staat er al'));
            $this->js('$wire.assessPendingUploads()');

            return;
        }

        if ($stored > 0) {
            $this->clearUploadPhase();
            $this->saveMessage = $stored === 1 ? 'Document opgeslagen' : "{$stored} documenten opgeslagen";
        } elseif ($duplicateNotice) {
            $this->clearUploadPhase();
            $this->saveMessage = 'Deze foto staat er al';
        } elseif ($error !== null) {
            $this->setUploadPhase('failed', 'Uploaden mislukt. Je eerdere antwoorden blijven bewaard.');
            $this->addError($errorBagKey, $error);
            $this->saveMessage = '';
        } else {
            $this->clearUploadPhase();
            $this->saveMessage = '';
        }
    }

    /**
     * Tweede Livewire-round-trip: beoordeling + fotoanalyse na opslaan.
     */
    public function assessPendingUploads(): void
    {
        $composite = $this->uploadPhaseComposite;

        if ($composite === '' || $this->pendingIdsFor($composite) === []) {
            return;
        }

        if ($this->followUpMode) {
            $this->assessPendingFollowUpUploads();

            return;
        }

        $uploadIds = $this->pendingIdsFor($composite);
        $intake = $this->intake();

        $this->setUploadPhase('assessing', 'Foto beoordelen…');

        $hints = [];
        [$questionKey, $instanceKey] = $this->splitComposite($composite);

        $previousKeys = app(ProgressCalculator::class)->calculate($intake, $this->version())['task_keys'];

        try {
            foreach ($uploadIds as $uploadId) {
                $upload = IntakeUpload::query()
                    ->where('intake_id', $intake->id)
                    ->whereKey($uploadId)
                    ->first();

                if (! $upload instanceof IntakeUpload) {
                    continue;
                }

                if ($questionKey === '') {
                    $questionKey = $upload->question_key;
                    $instanceKey = $upload->section_instance_key;
                }

                $verdict = app(AssessPhotoUsability::class)->handle($upload, correlationId: $this->correlationIdForUpload($upload));
                $retakeHint = $this->photoRetakeHint($verdict, $upload->question_key);

                if ($retakeHint !== null) {
                    $hints[] = $retakeHint;
                }
            }

            if ($questionKey !== '') {
                $this->runPhotoDerivation($questionKey, $instanceKey);
            }

            $this->forgetIntakeDerivedCaches();
            $this->realignToActiveStep();

            foreach ($uploadIds as $uploadId) {
                $upload = IntakeUpload::query()
                    ->where('intake_id', $this->intake()->id)
                    ->whereKey($uploadId)
                    ->first();

                if (! $upload instanceof IntakeUpload) {
                    continue;
                }

                $assessment = $upload->fresh()?->contentAssessment() ?? $upload->contentAssessment();

                if ($assessment instanceof PhotoContentAssessment
                    && ($msg = $assessment->customerMessage()) !== null) {
                    $hints[] = $msg;
                }
            }

            $progressAfter = app(ProgressCalculator::class)->calculate($this->intake(), $this->version());
            $this->setProgressExtraNoteFromNewTasks($previousKeys, $progressAfter);

            if ($hints !== []) {
                $this->photoHint[$composite] = implode(' ', array_values(array_unique($hints)));
            }

            $this->clearPendingIdsFor($composite);
            $this->clearUploadPhase();
        } catch (\Throwable $exception) {
            report($exception);
            $this->setUploadPhase(
                'failed',
                'Beoordelen mislukt. Je foto en antwoorden blijven bewaard.',
            );
            $this->addError(
                'photoFiles.'.$composite,
                $this->customerThrowableMessage($exception, 'assess'),
            );
        }
    }

    public function retryFailedUploadPhase(): void
    {
        $composite = $this->uploadPhaseComposite;

        if ($composite === '' || $this->pendingIdsFor($composite) === []) {
            $this->recoverUnassessedUploads(force: true);

            if ($this->uploadPhaseComposite === '' || $this->pendingIdsFor($this->uploadPhaseComposite) === []) {
                $this->clearUploadPhase();
                $this->saveMessage = 'Je kunt de foto opnieuw kiezen.';

                return;
            }

            return;
        }

        $this->resetErrorBag();
        $this->setUploadPhase('assessing', 'Foto beoordelen…');
        $this->js('$wire.assessPendingUploads()');
    }

    /**
     * Beoordeel alleen de pending follow-upfoto's van het actieve item.
     */
    private function assessPendingFollowUpUploads(): void
    {
        $composite = $this->uploadPhaseComposite;
        $uploadIds = $this->pendingIdsFor($composite);
        $intake = $this->intake();

        $this->setUploadPhase('assessing', 'Foto beoordelen…');

        try {
            $warnings = [];

            foreach ($uploadIds as $uploadId) {
                $upload = IntakeUpload::query()
                    ->where('intake_id', $intake->id)
                    ->whereKey($uploadId)
                    ->first();

                if (! $upload instanceof IntakeUpload) {
                    continue;
                }

                app(AssessPhotoUsability::class)->handle($upload, correlationId: $this->correlationIdForUpload($upload));

                $itemId = (int) $composite;
                $item = $this->followUpItem($itemId);
                $result = app(AssessFollowUpPhotoSubject::class)->handle($intake, $item, $upload);
                $message = $result['message'] ?? null;

                if (is_string($message) && $message !== '') {
                    $warnings[] = $message;
                }
            }

            if ($warnings !== []) {
                $this->addError(
                    'followUpPhotoFiles.'.($composite !== '' ? $composite : '0'),
                    implode(' ', array_values(array_unique($warnings))),
                );
            }

            $this->clearPendingIdsFor($composite);
            $this->clearUploadPhase();
        } catch (\Throwable $exception) {
            report($exception);
            $this->setUploadPhase(
                'failed',
                'Beoordelen mislukt. Je foto en antwoorden blijven bewaard.',
            );
            $this->addError(
                'followUpPhotoFiles.'.($composite !== '' ? $composite : '0'),
                $this->customerThrowableMessage($exception, 'assess'),
            );
        }
    }

    private function setUploadPhase(string $phase, string $message): void
    {
        $this->uploadPhase = $phase;
        $this->uploadPhaseMessage = $message;
    }

    private function clearUploadPhase(): void
    {
        $this->uploadPhase = '';
        $this->uploadPhaseMessage = '';
        $this->uploadPhaseComposite = '';
    }

    /**
     * @return list<int>
     */
    private function pendingIdsFor(string $key): array
    {
        if (! array_key_exists($key, $this->pendingAssessUploadIds)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $this->pendingAssessUploadIds[$key])));
    }

    /**
     * @param  list<int>  $ids
     */
    private function setPendingIdsFor(string $key, array $ids): void
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));

        if ($normalized === []) {
            unset($this->pendingAssessUploadIds[$key]);

            return;
        }

        $this->pendingAssessUploadIds[$key] = $normalized;
    }

    private function clearPendingIdsFor(string $key): void
    {
        unset($this->pendingAssessUploadIds[$key]);
    }

    /**
     * Na reload/timeout: uploads zonder usability_verdict opnieuw in de beoordelingswachtrij.
     */
    private function recoverUnassessedUploads(bool $force = false): void
    {
        if ($this->queuedUnassessedRecovery || $this->completed) {
            return;
        }

        if (! $force && $this->uploadPhase === 'failed') {
            return;
        }

        if (
            ! $force
            && $this->uploadPhase === 'assessing'
            && $this->uploadPhaseComposite !== ''
            && $this->pendingIdsFor($this->uploadPhaseComposite) !== []
        ) {
            return;
        }

        if ($this->followUpMode) {
            $items = $this->followUpRound()->items->values();
            $item = $items->get($this->followUpStepIndex);

            if (! $item instanceof IntakeFollowUpItem || $item->type !== FollowUpItemType::Photo) {
                return;
            }

            $item->loadMissing('uploads');
            $ids = $item->uploads
                ->filter(static fn (IntakeUpload $upload): bool => $upload->usability_verdict === null)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($ids === []) {
                return;
            }

            $composite = (string) $item->id;
            $this->setPendingIdsFor($composite, $ids);
            $this->uploadPhaseComposite = $composite;
            $this->setUploadPhase('assessing', 'Foto beoordelen…');
            $this->queuedUnassessedRecovery = true;
            $this->js('$wire.assessPendingUploads()');

            return;
        }

        $intake = $this->intake();
        /** @var Collection<int, IntakeUpload> $unassessed */
        $unassessed = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->whereNull('intake_follow_up_item_id')
            ->whereNull('usability_verdict')
            ->orderBy('id')
            ->get();

        if ($unassessed->isEmpty()) {
            return;
        }

        $step = $this->currentStep();
        $preferred = null;

        if ($step !== null) {
            $preferredComposite = VisibilityResolver::compositeKey(
                $step['question_key'],
                $step['section_instance_key'],
            );
            $matching = $unassessed->filter(
                static fn (IntakeUpload $upload): bool => VisibilityResolver::compositeKey(
                    $upload->question_key,
                    $upload->section_instance_key,
                ) === $preferredComposite,
            );

            if ($matching->isNotEmpty()) {
                $preferred = $matching;
            }
        }

        $group = $preferred ?? $unassessed->groupBy(
            static fn (IntakeUpload $upload): string => VisibilityResolver::compositeKey(
                $upload->question_key,
                $upload->section_instance_key,
            ),
        )->first();

        if (! $group instanceof Collection || $group->isEmpty()) {
            return;
        }

        /** @var IntakeUpload $first */
        $first = $group->first();
        $composite = VisibilityResolver::compositeKey(
            $first->question_key,
            $first->section_instance_key,
        );
        $ids = $group->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        $this->setPendingIdsFor($composite, $ids);
        $this->uploadPhaseComposite = $composite;
        $this->setUploadPhase('assessing', 'Foto beoordelen…');
        $this->queuedUnassessedRecovery = true;
        $this->js('$wire.assessPendingUploads()');
    }

    /**
     * @param  list<string>  $previousTaskKeys
     * @param  array{
     *     percent: int,
     *     answered_required: int,
     *     total_required: int,
     *     missing_required: list<array{question_key: string, section_instance_key: string|null, label: string|null}>,
     *     task_keys: list<string>
     * }  $progressAfter
     */
    private function setProgressExtraNoteFromNewTasks(array $previousTaskKeys, array $progressAfter): void
    {
        $newLabels = app(ProgressCalculator::class)->newTaskLabels($previousTaskKeys, $progressAfter);

        if ($newLabels === []) {
            return;
        }

        $this->progressExtraNote = count($newLabels) === 1
            ? 'Na je foto hebben we nog één vraag: '.$newLabels[0]
            : 'Na je foto hebben we nog een paar vragen: '.implode('; ', $newLabels);
    }

    /**
     * Eerlijke klanttekst bij timeout vs. overige fouten; altijd na report($e).
     */
    private function customerThrowableMessage(\Throwable $exception, string $context): string
    {
        $message = strtolower($exception->getMessage());
        $isTimeout = $exception instanceof ConnectionException
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'max_execution_time');

        if ($context === 'assess') {
            return $isTimeout
                ? 'Beoordelen duurde te lang. Je foto is wel opgeslagen — tik op Opnieuw proberen.'
                : 'Beoordelen mislukt. Je foto is wel opgeslagen — tik op Opnieuw proberen.';
        }

        return $isTimeout
            ? 'Upload duurde te lang. Je eerdere antwoorden blijven bewaard — probeer het opnieuw.'
            : 'Upload mislukt. Je eerdere antwoorden blijven bewaard — probeer het opnieuw.';
    }

    /**
     * Upload each selected file independently so one failure does not block the rest (BL-021).
     * Round-trip 1: opslaan + uploadPhase=assessing. Round-trip 2: assessPendingUploads().
     *
     * @param  list<TemporaryUploadedFile>  $files
     */
    private function uploadPhotosForComposite(string $composite, array $files): void
    {
        $maxKb = (int) config('intake.uploads.max_kilobytes', 5120);
        [$questionKey, $instanceKey] = $this->splitComposite($composite);
        $intake = $this->intake();

        // Alleen deze composite resetten; pending van andere vragen blijft staan.
        $this->progressExtraNote = '';
        $this->clearPendingIdsFor($composite);
        if ($this->uploadPhaseComposite === $composite) {
            $this->uploadPhase = '';
            $this->uploadPhaseMessage = '';
        }
        $this->uploadPhaseComposite = $composite;

        $stored = 0;
        $duplicateNotice = false;
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<int> $storedUploadIds */
        $storedUploadIds = [];

        foreach ($files as $file) {
            try {
                Validator::make(
                    ['photo' => $file],
                    ['photo' => ['required', 'file', 'max:'.$maxKb]],
                    [],
                    ['photo' => 'foto'],
                )->validate();

                $upload = app(StoreIntakeUpload::class)->handle(
                    $intake,
                    $questionKey,
                    $instanceKey,
                    $file,
                );

                if (! $upload->wasRecentlyCreated) {
                    $duplicateNotice = true;

                    if ($upload->usability_verdict === null) {
                        $storedUploadIds[] = $upload->id;
                    }

                    continue;
                }

                $this->rememberStoredUpload($upload);
                $stored++;
                $storedUploadIds[] = $upload->id;

                // BL-025: invalidate request-cache after the upload changed intake state.
                $this->forgetIntakeDerivedCaches();
            } catch (ValidationException $e) {
                $errors[] = $e->errors()['photo'][0]
                    ?? $e->errors()['photoFiles.'.$composite][0]
                    ?? 'Upload mislukt. Probeer het opnieuw.';
            } catch (\Throwable $exception) {
                report($exception);
                $errors[] = $this->customerThrowableMessage($exception, 'upload');
            }
        }

        // Alleen deze foto-composite verversen — volledige hydrate wist niet-opgeslagen velden.
        $this->photoFiles[$composite] = [];
        $this->refreshAnswerInForm($composite);
        $this->showMissing = false;
        $this->resetErrorBag('photoFiles.'.$composite);

        if ($errors !== []) {
            $this->addError('photoFiles.'.$composite, implode(' ', array_values(array_unique($errors))));
        }

        $storedUploadIds = array_values(array_unique($storedUploadIds));

        if ($storedUploadIds !== []) {
            $this->setPendingIdsFor($composite, $storedUploadIds);
            $this->setUploadPhase('assessing', 'Foto beoordelen…');
            $this->saveMessage = $duplicateNotice && $stored === 0
                ? 'Deze foto staat er al'
                : ($stored === 1 ? 'Foto opgeslagen' : ($stored > 1 ? $stored." foto's opgeslagen" : 'Deze foto staat er al'));
            $this->js('$wire.assessPendingUploads()');

            return;
        }

        if ($duplicateNotice) {
            $this->clearUploadPhase();
            $this->saveMessage = 'Deze foto staat er al';
        } elseif ($errors !== []) {
            $this->setUploadPhase('failed', 'Uploaden mislukt. Je eerdere antwoorden blijven bewaard.');
            $this->saveMessage = '';
        } else {
            $this->clearUploadPhase();
            $this->saveMessage = '';
        }
    }

    private function photoRetakeHint(PhotoUsabilityVerdict $verdict, string $questionKey): ?string
    {
        $qualityHint = $verdict->customerHint();

        if ($qualityHint === null) {
            return null;
        }

        foreach ($this->version()->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key !== $questionKey) {
                    continue;
                }

                $instruction = trim((string) $question->photo_instructions);

                if ($instruction === '') {
                    return $qualityHint;
                }

                return $qualityHint.' Zorg dat dit opnieuw duidelijk in beeld staat: '
                    .rtrim($instruction, '.').'.';
            }
        }

        return $qualityHint;
    }

    /** @param Collection<int, IntakeUpload> $uploads */
    private function persistentIntakePhotoHint(
        Intake $intake,
        IntakeQuestion $question,
        Collection $uploads,
    ): ?string {
        $hints = [];

        foreach ($uploads as $upload) {
            $verdict = $upload->usability_verdict;

            if ($verdict instanceof PhotoUsabilityVerdict) {
                $hint = $this->photoRetakeHint($verdict, $question->key);

                if ($hint !== null) {
                    $hints[] = $hint;
                }
            }
        }

        // Mismatch-/retake-tekst alleen uit opgeslagen content_assessment (één bron).
        foreach ($uploads as $upload) {
            $assessment = $upload->contentAssessment();

            if ($assessment !== null
                && $assessment->status() !== PhotoContentAssessment::STATUS_OK
                && ($msg = $assessment->customerMessage()) !== null) {
                $hints[] = $msg;
            }
        }

        return $hints === [] ? null : implode(' ', array_values(array_unique($hints)));
    }

    private function persistentFollowUpPhotoHint(IntakeFollowUpItem $item): ?string
    {
        $hints = [];

        foreach ($item->uploads as $upload) {
            $assessment = $upload->contentAssessment();

            if ($assessment instanceof PhotoContentAssessment
                && $assessment->status() !== PhotoContentAssessment::STATUS_OK) {
                $contentHint = $assessment->customerMessage();

                if ($contentHint !== null) {
                    $hints[] = $contentHint;
                }
            }

            $verdict = $upload->usability_verdict;
            $qualityHint = $verdict instanceof PhotoUsabilityVerdict
                ? $verdict->customerHint()
                : null;

            if ($qualityHint !== null) {
                $hints[] = $qualityHint.' Zorg dat dit opnieuw duidelijk in beeld staat: '
                    .rtrim($item->prompt, '.').'.';
            }
        }

        return $hints === [] ? null : implode(' ', array_values(array_unique($hints)));
    }

    /**
     * Runs whatever photo-analysis profile the question opted into via `meta.photo_analysis`.
     * The fusebox keeps its dedicated action — it derives phase alongside the free-group answer,
     * which does not fit the generic single-shape profile.
     * Customer-facing mismatch/retake text comes only from stored content_assessment.
     */
    private function runPhotoDerivation(string $questionKey, ?string $instanceKey): void
    {
        $profileName = $this->photoAnalysisProfileName($questionKey);

        if ($profileName === null) {
            return;
        }

        $correlationId = $this->latestUploadCorrelationId($questionKey, $instanceKey);

        if ($profileName === 'fusebox') {
            if ($instanceKey === null) {
                app(AssessFuseboxPhotos::class)->handle($this->intake(), correlationId: $correlationId);
                $this->applyFuseboxAssessment();
            }

            return;
        }

        $profile = PhotoDerivationProfile::find($profileName);

        if (! $profile instanceof PhotoDerivationProfile) {
            return;
        }

        app(DerivePhotoAnswers::class)->handle(
            $this->intake(),
            $questionKey,
            $instanceKey,
            $profile,
            correlationId: $correlationId,
        );
        $this->applyPhotoDerivation($instanceKey, $profile);
    }

    private function invalidatePhotoDerivation(string $questionKey, ?string $instanceKey): void
    {
        $profileName = $this->photoAnalysisProfileName($questionKey);

        if ($profileName === null) {
            return;
        }

        if ($profileName === 'fusebox') {
            if ($instanceKey === null) {
                app(AssessFuseboxPhotos::class)->invalidateDerivedState($this->intake());
            }

            return;
        }

        $profile = PhotoDerivationProfile::find($profileName);

        if ($profile instanceof PhotoDerivationProfile) {
            app(DerivePhotoAnswers::class)->invalidateDerivedState(
                $this->intake(),
                $questionKey,
                $instanceKey,
                $profile,
            );
        }
    }

    private function photoAnalysisProfileName(string $questionKey): ?string
    {
        foreach ($this->version()->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key !== $questionKey) {
                    continue;
                }

                $profileName = $question->meta['photo_analysis'] ?? null;

                return is_string($profileName) && $profileName !== '' ? $profileName : null;
            }
        }

        return null;
    }

    private function applyPhotoDerivation(
        ?string $instanceKey,
        PhotoDerivationProfile $profile,
    ): void {
        foreach ($profile->questionKeys() as $questionKey) {
            $composite = VisibilityResolver::compositeKey($questionKey, $instanceKey);
            $this->refreshAnswerInForm($composite);

            $answer = $this->intake()->answers()
                ->where('question_key', $questionKey)
                ->when(
                    $instanceKey === null,
                    static fn ($query) => $query->whereNull('section_instance_key'),
                    static fn ($query) => $query->where('section_instance_key', $instanceKey),
                )
                ->first();

            if (PrefillSources::isPhotoSuggestion($answer?->prefill_source)) {
                $this->prefillNotice[$composite] = 'We hebben dit uit je foto gehaald — klopt het?';
            } else {
                unset($this->prefillNotice[$composite]);
            }
        }
    }

    private function applyFuseboxAssessment(): void
    {
        foreach (['free_group_known', 'fusebox_clarity'] as $questionKey) {
            $composite = VisibilityResolver::compositeKey($questionKey, null);
            $this->refreshAnswerInForm($composite);
            unset($this->prefillNotice[$composite]);
        }
    }

    /**
     * Soft continue: customer accepts a wrong-subject photo and moves on.
     * Marks the upload(s); installer attention comes from CompletenessChecker + fotobadge.
     */
    public function acceptPhotoMismatch(): void
    {
        if ($this->completed) {
            return;
        }

        $step = $this->currentStep();
        if ($step === null) {
            return;
        }

        $question = app(IntakeStepBuilder::class)->questionForStep(
            $this->version(),
            $step['section_key'],
            $step['question_key'],
        );

        if (! $question instanceof IntakeQuestion || $question->type !== QuestionType::Photo) {
            return;
        }

        $intake = $this->intake();
        $intake->loadMissing('uploads');

        $uploads = $intake->uploads->filter(static function (IntakeUpload $upload) use ($step, $question): bool {
            if ($upload->question_key !== $question->key) {
                return false;
            }

            return $step['section_instance_key'] === null
                ? $upload->section_instance_key === null
                : $upload->section_instance_key === $step['section_instance_key'];
        });

        $acceptedAny = false;

        foreach ($uploads as $upload) {
            $assessment = $upload->contentAssessment();

            if (! $assessment instanceof PhotoContentAssessment
                || $assessment->status() !== PhotoContentAssessment::STATUS_WRONG_SUBJECT
                || $assessment->customerAcceptedMismatch()) {
                continue;
            }

            $upload->storeContentAssessment($assessment->withCustomerAcceptedMismatch());
            $acceptedAny = true;
        }

        if (! $acceptedAny) {
            return;
        }

        $composite = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
        $this->photoHint[$composite] = null;
        $this->forgetIntakeDerivedCaches();
        $this->showMissing = false;
        $this->saveMessage = '';

        $this->next();
    }

    /**
     * Echte vervanging: verwijder onopgeloste wrong_subject-uploads en open de file picker.
     */
    public function replaceMismatchedPhoto(): void
    {
        if ($this->completed) {
            return;
        }

        $step = $this->currentStep();
        if ($step === null) {
            return;
        }

        $question = app(IntakeStepBuilder::class)->questionForStep(
            $this->version(),
            $step['section_key'],
            $step['question_key'],
        );

        if (! $question instanceof IntakeQuestion || $question->type !== QuestionType::Photo) {
            return;
        }

        $intake = $this->intake();
        $intake->loadMissing('uploads');

        $removed = false;

        foreach ($intake->uploads as $upload) {
            if ($upload->question_key !== $question->key) {
                continue;
            }

            if ($step['section_instance_key'] === null
                ? $upload->section_instance_key !== null
                : $upload->section_instance_key !== $step['section_instance_key']) {
                continue;
            }

            $assessment = $upload->contentAssessment();

            if (! $assessment instanceof PhotoContentAssessment
                || $assessment->status() !== PhotoContentAssessment::STATUS_WRONG_SUBJECT
                || $assessment->customerAcceptedMismatch()) {
                continue;
            }

            app(DeleteIntakeUpload::class)->handle($intake, $upload);
            $removed = true;
        }

        if (! $removed) {
            return;
        }

        $this->invalidatePhotoDerivation($question->key, $step['section_instance_key']);
        $this->runPhotoDerivation($question->key, $step['section_instance_key']);
        $this->forgetIntakeDerivedCaches();

        $composite = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
        $this->photoHint[$composite] = null;
        $this->refreshAnswerInForm($composite);
        $this->showMissing = false;
        $this->saveMessage = '';

        $inputId = 'photo-input-'.str_replace(['.', ' '], '-', $composite);
        $this->js('document.getElementById('.json_encode($inputId).')?.click()');
    }

    /**
     * @return array<string, Collection<int, IntakeUpload>>
     */
    private function uploadsForStep(?string $sectionInstanceKey): array
    {
        $intake = $this->intake();
        $intake->loadMissing('uploads');

        $grouped = [];

        foreach ($intake->uploads as $upload) {
            if ($upload->section_instance_key !== $sectionInstanceKey) {
                continue;
            }

            $grouped[$upload->question_key][] = $upload;
        }

        $result = [];

        foreach ($grouped as $questionKey => $items) {
            $result[$questionKey] = collect($items)->sortBy('sort_order')->values();
        }

        return $result;
    }

    public function saveCurrentStep(): void
    {
        if ($this->completed) {
            return;
        }

        $step = $this->currentStep();
        if ($step === null || ($step['kind'] ?? 'question') === 'known_summary') {
            return;
        }

        $question = app(IntakeStepBuilder::class)->questionForStep(
            $this->version(),
            $step['section_key'],
            $step['question_key'],
        );

        if (! $question instanceof IntakeQuestion || $question->type === QuestionType::Photo) {
            return;
        }

        $composite = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
        $this->persistComposite($composite);
        $this->saveMessage = 'Opgeslagen';
    }

    /**
     * Laat een eerder overgeslagen bekend veld opnieuw als klantvraag zien (wijzigen).
     * Bron blijft staan tot de opgeslagen waarde echt verandert.
     */
    public function editKnownAnswer(string $questionKey, ?string $sectionInstanceKey = null): void
    {
        if ($this->completed || $questionKey === '' || $questionKey === '_known_summary') {
            return;
        }

        $question = null;
        foreach ($this->version()->sections as $section) {
            foreach ($section->questions as $candidate) {
                if ($candidate->key === $questionKey) {
                    $question = $candidate;
                    break 2;
                }
            }
        }

        if (! $question instanceof IntakeQuestion || ! KnownSummaryCatalog::allows($question)) {
            return;
        }

        $answer = $this->intake()->answers()
            ->where('question_key', $questionKey)
            ->when(
                $sectionInstanceKey === null,
                static fn ($query) => $query->whereNull('section_instance_key'),
                static fn ($query) => $query->where('section_instance_key', $sectionInstanceKey),
            )
            ->first();

        if ($answer === null || ! KnownSummaryCatalog::allowsSource($answer->prefill_source)) {
            return;
        }

        $composite = VisibilityResolver::compositeKey($questionKey, $sectionInstanceKey);
        if (! in_array($composite, $this->forceShowKnown, true)) {
            $this->forceShowKnown[] = $composite;
        }
        $this->pendingKnownEdits[$composite] = [
            'prefill_source' => (string) $answer->prefill_source,
            'value' => is_array($answer->value) ? $answer->value : null,
        ];
        $this->forgetIntakeDerivedCaches();

        $steps = $this->steps();
        $index = app(IntakeStepBuilder::class)->indexForCursor(
            $steps,
            null,
            $questionKey,
            $sectionInstanceKey,
        );
        $this->stepIndex = $index;
        $this->syncActiveStepKey($steps);
        $this->rememberCurrentCursor();
        $this->hydrateFormFromAnswers();
        $this->saveMessage = '';
    }

    public function complete(): void
    {
        if ($this->completed) {
            return;
        }

        $this->saveCurrentStep();

        if (! $this->currentStepRequiredSatisfied()) {
            $this->showMissing = true;
            $this->saveMessage = '';

            return;
        }

        $intake = $this->intake();

        $version = $this->version();
        $check = app(CompletenessChecker::class)->check($intake, $version);

        if (! $check['is_complete']) {
            $this->completionMissing = $check['missing'];
            $this->showMissing = true;
            $this->saveMessage = '';

            return;
        }

        try {
            app(CompleteIntake::class)->handle($intake);
            $this->forgetIntakeDerivedCaches();
            $this->completed = true;
            $this->completionMissing = [];
            $this->showMissing = false;
            $this->saveMessage = '';
        } catch (ValidationException $e) {
            $this->addError('completeness', $e->errors()['completeness'][0] ?? 'Afronden mislukt.');
        }
    }

    public function next(): void
    {
        if ($this->completed) {
            return;
        }

        $this->progressExtraNote = '';

        $currentKey = $this->activeStepKey !== ''
            ? $this->activeStepKey
            : ($this->steps()[$this->stepIndex]['key'] ?? null);
        $currentStep = $this->currentStep();
        $leavingForcedEdit = false;
        if ($currentStep !== null && ($currentStep['kind'] ?? 'question') !== 'known_summary') {
            $leavingForcedEdit = in_array(
                VisibilityResolver::compositeKey(
                    $currentStep['question_key'],
                    $currentStep['section_instance_key'],
                ),
                $this->forceShowKnown,
                true,
            );
        }

        $this->saveCurrentStep();

        if (! $this->currentStepRequiredSatisfied()) {
            $this->showMissing = true;
            $this->saveMessage = '';

            return;
        }

        $this->showMissing = false;
        $this->completionMissing = [];

        // Na Wijzigen: terug naar overzicht, niet vooruit in de flow.
        if ($leavingForcedEdit) {
            $this->leaveForcedKnownEdit();

            return;
        }

        $steps = $this->steps();
        $currentIndex = app(IntakeStepBuilder::class)->indexForStepKey($steps, $currentKey) ?? $this->stepIndex;

        if ($currentIndex < count($steps) - 1) {
            $this->stepIndex = $currentIndex + 1;
            $this->syncActiveStepKey($steps);
            $this->rememberCurrentCursor();
            $this->hydrateFormFromAnswers();
            $this->applyPrefillForActiveStep();
            $this->saveMessage = '';
        }
    }

    /**
     * After a single_choice/boolean save: advance one step when still on that question (BL-023).
     * Skips multi_choice / text / photo. Does not auto-complete the last step.
     * realignToActiveStep() must run first so a newly visible follow-up is never skipped.
     */
    private function maybeAutoAdvanceAfterChoice(string $composite, string $field): void
    {
        if (! in_array($field, ['value', 'bool'], true)) {
            return;
        }

        $step = $this->currentStep();
        if ($step === null) {
            return;
        }

        $stepComposite = VisibilityResolver::compositeKey(
            $step['question_key'],
            $step['section_instance_key'],
        );

        // realign moved us off this question — stay put (e.g. current became hidden).
        if ($stepComposite !== $composite) {
            return;
        }

        $question = app(IntakeStepBuilder::class)->questionForStep(
            $this->version(),
            $step['section_key'],
            $step['question_key'],
        );

        if (! $question instanceof IntakeQuestion) {
            return;
        }

        if (! in_array($question->type, [QuestionType::SingleChoice, QuestionType::Boolean], true)) {
            return;
        }

        if (! $this->currentStepRequiredSatisfied()) {
            return;
        }

        $steps = $this->steps();
        if ($this->stepIndex >= count($steps) - 1) {
            return;
        }

        $this->next();
        // Brief confirmation on the following screen.
        $this->saveMessage = 'Opgeslagen';
    }

    public function previous(): void
    {
        if ($this->stepIndex <= 0) {
            return;
        }

        $this->progressExtraNote = '';

        $currentStep = $this->currentStep();
        $leavingForcedEdit = false;
        if ($currentStep !== null && ($currentStep['kind'] ?? 'question') !== 'known_summary') {
            $leavingForcedEdit = in_array(
                VisibilityResolver::compositeKey(
                    $currentStep['question_key'],
                    $currentStep['section_instance_key'],
                ),
                $this->forceShowKnown,
                true,
            );
        }

        $this->saveCurrentStep();

        // Geforceerde known-edit: terug naar overzicht, niet naar de stap ervoor.
        if ($leavingForcedEdit) {
            $this->leaveForcedKnownEdit();
            $this->saveMessage = '';
            $this->showMissing = false;

            return;
        }

        $currentKey = $this->activeStepKey !== ''
            ? $this->activeStepKey
            : ($this->steps()[$this->stepIndex]['key'] ?? null);

        $steps = $this->steps();
        $currentIndex = app(IntakeStepBuilder::class)->indexForStepKey($steps, $currentKey) ?? $this->stepIndex;
        $this->stepIndex = max(0, $currentIndex - 1);
        $this->syncActiveStepKey($steps);
        $this->rememberCurrentCursor();
        $this->hydrateFormFromAnswers();
        $this->applyPrefillForActiveStep();
        $this->saveMessage = '';
        $this->showMissing = false;
    }

    public function goToStep(int $index): void
    {
        $steps = $this->steps();
        if ($index < 0 || $index >= count($steps)) {
            return;
        }

        $targetKey = $steps[$index]['key'];
        $this->saveCurrentStep();
        $this->finalizeForcedKnownEditIfOnForcedStep();

        $steps = $this->steps();
        if ($steps === []) {
            $this->stepIndex = 0;
        } else {
            $resolved = app(IntakeStepBuilder::class)->indexForStepKey($steps, $targetKey);
            $this->stepIndex = $resolved ?? max(0, min($index, count($steps) - 1));
        }
        $this->syncActiveStepKey($steps);
        $this->rememberCurrentCursor();
        $this->hydrateFormFromAnswers();
        $this->applyPrefillForActiveStep();
        $this->saveMessage = '';
        $this->showMissing = false;
    }

    /**
     * Jump to a missing required question from the completion alert (BL-022).
     */
    public function goToMissing(string $questionKey, ?string $sectionInstanceKey = null): void
    {
        $steps = $this->steps();

        foreach ($steps as $index => $step) {
            if ($step['question_key'] !== $questionKey) {
                continue;
            }

            if ($step['section_instance_key'] !== $sectionInstanceKey) {
                continue;
            }

            $this->goToStep($index);

            return;
        }
    }

    private function intake(): Intake
    {
        if ($this->resolvedIntake === null) {
            $this->resolvedIntake = Intake::query()
                ->with(['answers', 'uploads'])
                ->findOrFail($this->intakeId);
        }

        return $this->resolvedIntake;
    }

    private function version(): IntakeTemplateVersion
    {
        if ($this->resolvedVersion === null) {
            $this->resolvedVersion = $this->intake()
                ->templateVersion()
                ->with(['sections.questions.options', 'sections.questions.rules'])
                ->firstOrFail();
        }

        return $this->resolvedVersion;
    }

    /**
     * @return list<array{
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
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string,
     *     }>
     * }>
     */
    private function steps(): array
    {
        $signature = $this->liveAnswersSignature().'|'.implode(',', $this->forceShowKnown);

        if ($this->resolvedSteps !== null && $this->resolvedStepsFormSignature === $signature) {
            return $this->resolvedSteps;
        }

        $steps = app(IntakeStepBuilder::class)->build(
            $this->intake(),
            $this->version(),
            $this->liveAnswers(),
            $this->forceShowKnown,
        );

        $this->resolvedSteps = $steps;
        $this->resolvedStepsFormSignature = $signature;

        return $this->resolvedSteps;
    }

    /**
     * Drop caches that depend on intake row / answers / uploads (BL-025).
     * Template version graph stays cached — it does not change mid-request.
     */
    private function forgetIntakeDerivedCaches(): void
    {
        $this->resolvedIntake = null;
        $this->resolvedSteps = null;
        $this->resolvedStepsFormSignature = null;
    }

    private function liveAnswersSignature(): string
    {
        return hash('xxh3', (string) json_encode(
            $this->liveAnswers(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private function liveAnswers(): array
    {
        $answers = [];

        foreach ($this->form as $key => $value) {
            if (is_array($value)) {
                $answers[$key] = $value;
            }
        }

        return $answers;
    }

    /**
     * Zorgt dat de formulierstaat van de actieve vraag al de juiste vorm heeft.
     *
     * `hydrateFormFromAnswers()` maakt alleen entries voor vragen die al beantwoord zijn.
     * Bij een nog onbeantwoorde `multi_choice` bestaat `form.<composite>.values` daardoor
     * niet, en dan bindt Livewire de hele checkboxgroep aan één scalair — één vinkje zet
     * de waarde op `true` en alle vakjes lijken aangevinkt.
     */
    private function ensureAnswerShape(IntakeQuestion $question, ?string $sectionInstanceKey): void
    {
        if ($question->type !== QuestionType::MultiChoice) {
            return;
        }

        $composite = VisibilityResolver::compositeKey($question->key, $sectionInstanceKey);
        $current = $this->form[$composite] ?? [];

        if (! is_array($current)) {
            $current = [];
        }

        if (! is_array($current['values'] ?? null)) {
            $current['values'] = [];
        }

        $this->form[$composite] = $current;
    }

    private function hydrateFormFromAnswers(): void
    {
        $intake = $this->intake();
        $intake->loadMissing('answers');
        $form = [];
        $notices = [];

        foreach ($intake->answers as $answer) {
            $composite = VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key);
            $form[$composite] = $answer->value ?? [];

            // A prefill remains editable and is only authoritative after customer confirmation.
            if ($answer->prefill_source === 'installer') {
                $notices[$composite] = 'Je installateur heeft dit alvast ingevuld — klopt het?';
            } elseif (PrefillSources::isPhotoSuggestion($answer->prefill_source)) {
                $notices[$composite] = 'We hebben dit uit je foto gehaald — klopt het?';
            }
        }

        $this->form = $form;
        $this->prefillNotice = $notices;
    }

    /**
     * Offer a repeatable-instance prefill for the active step as an editable voorzet (BL-016).
     * Only fills when the step has no value yet; the value is persisted when the applicant advances.
     */
    private function applyPrefillForActiveStep(): void
    {
        $step = $this->currentStep();

        if ($step === null) {
            return;
        }

        $composite = VisibilityResolver::compositeKey($step['question_key'], $step['section_instance_key']);

        $existing = $this->form[$composite] ?? null;
        if (is_array($existing) && $existing !== []) {
            return;
        }

        $suggestion = app(IntakePrefillResolver::class)->suggestionFor(
            $this->intake(),
            $this->version(),
            $step['question_key'],
            $step['section_instance_key'],
        );

        if ($suggestion === null) {
            return;
        }

        $this->form[$composite] = $suggestion['value'];
        $this->prefillNotice[$composite] = 'Overgenomen van '.$suggestion['source_label'].' — klopt het zo?';
    }

    private function refreshAnswerInForm(string $composite): void
    {
        [$questionKey, $instanceKey] = $this->splitComposite($composite);

        $query = $this->intake()->answers()
            ->where('question_key', $questionKey);

        if ($instanceKey === null) {
            $query->whereNull('section_instance_key');
        } else {
            $query->where('section_instance_key', $instanceKey);
        }

        $answer = $query->first();
        $this->form[$composite] = $answer === null ? [] : ($answer->value ?? []);
    }

    private function persistComposite(string $composite): void
    {
        [$questionKey, $instanceKey] = $this->splitComposite($composite);
        $payload = $this->form[$composite] ?? [];

        if (! is_array($payload)) {
            $payload = [];
        }

        app(SaveIntakeAnswer::class)->handle(
            $this->intake(),
            $questionKey,
            $instanceKey,
            $payload,
        );
        $this->forgetIntakeDerivedCaches();

        // Een vraag kan via meta.text_analysis latere vragen beantwoorden — de reden van de
        // aanvraag noemt vaak al de ruimtes en of het om koelen of verwarmen gaat.
        // Known-summary staat direct na request_reason; next() landt erop zonder sprong.
        if ($instanceKey === null && $this->hasTextAnalysis($questionKey)) {
            app(DeriveIntentFromRequest::class)->handle($this->intake());
            $this->forgetIntakeDerivedCaches();
        }
    }

    /**
     * Na Wijzigen via Volgende/Enter/Vorige: forced edit afronden en terug naar known-summary.
     */
    private function leaveForcedKnownEdit(): void
    {
        $this->finalizeForcedKnownEditIfOnForcedStep();
        $this->returnToKnownSummary();
    }

    /**
     * Forced known-edit afronden (zonder navigatie). Gebruikt door leaveForcedKnownEdit en goToStep.
     */
    private function finalizeForcedKnownEditIfOnForcedStep(): void
    {
        $currentStep = $this->currentStep();
        if ($currentStep === null || ($currentStep['kind'] ?? 'question') === 'known_summary') {
            return;
        }

        $questionKey = $currentStep['question_key'];
        $instanceKey = $currentStep['section_instance_key'];
        $composite = VisibilityResolver::compositeKey($questionKey, $instanceKey);
        if (! in_array($composite, $this->forceShowKnown, true)) {
            return;
        }

        $saved = $this->answerForQuestion($questionKey, $instanceKey);
        $this->finalizeForcedKnownEdit(
            $questionKey,
            $instanceKey,
            is_array($saved?->value) ? $saved->value : [],
        );
    }

    private function answerForQuestion(string $questionKey, ?string $instanceKey): ?IntakeAnswer
    {
        return $this->intake()->answers()
            ->where('question_key', $questionKey)
            ->when(
                $instanceKey === null,
                static fn ($query) => $query->whereNull('section_instance_key'),
                static fn ($query) => $query->where('section_instance_key', $instanceKey),
            )
            ->first();
    }

    /**
     * @param  array<string, mixed>  $normalizedSavedValue  waarde zoals SaveIntakeAnswer die opsloeg
     */
    private function finalizeForcedKnownEdit(
        string $questionKey,
        ?string $instanceKey,
        array $normalizedSavedValue,
    ): void {
        $composite = VisibilityResolver::compositeKey($questionKey, $instanceKey);
        $pending = $this->pendingKnownEdits[$composite] ?? null;

        $this->forceShowKnown = array_values(array_filter(
            $this->forceShowKnown,
            static fn (string $key): bool => $key !== $composite,
        ));
        unset($this->pendingKnownEdits[$composite]);

        if (! is_array($pending)) {
            return;
        }

        $unchanged = $this->answerValuesEqual($pending['value'] ?? null, $normalizedSavedValue);
        if (! $unchanged) {
            // SaveIntakeAnswer already cleared prefill_source — value really changed.
            return;
        }

        $answer = $this->answerForQuestion($questionKey, $instanceKey);

        if ($answer !== null) {
            $answer->update(['prefill_source' => $pending['prefill_source']]);
            $this->forgetIntakeDerivedCaches();
        }
    }

    private function returnToKnownSummary(): void
    {
        $steps = $this->steps();
        foreach ($steps as $index => $step) {
            if (($step['kind'] ?? 'question') === 'known_summary') {
                $this->stepIndex = $index;
                $this->syncActiveStepKey($steps);
                $this->rememberCurrentCursor();
                $this->hydrateFormFromAnswers();

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $left
     * @param  array<string, mixed>|null  $right
     */
    private function answerValuesEqual(?array $left, ?array $right): bool
    {
        return json_encode($left ?? []) === json_encode($right ?? []);
    }

    private function hasTextAnalysis(string $questionKey): bool
    {
        foreach ($this->version()->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return ($question->meta['text_analysis'] ?? null) === 'request_intent';
                }
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitComposite(string $composite): array
    {
        if (str_contains($composite, '__')) {
            [$instance, $questionKey] = explode('__', $composite, 2);

            return [$questionKey, $instance];
        }

        return [$composite, null];
    }

    /**
     * @param  Collection<int, IntakeQuestion>  $questions
     * @return array<string, array{visible: bool, required: bool}>
     */
    private function visibilityForQuestions(Collection $questions, ?string $sectionInstanceKey): array
    {
        $intake = $this->intake();
        $version = $this->version();
        $answers = [];

        foreach ($intake->answers as $answer) {
            $answers[VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key)] = $answer->value;
        }

        // Merge in-memory form values for live conditional UI
        foreach ($this->form as $key => $value) {
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
                $allQuestions->push($question);
            }
        }

        $targets = [];
        foreach ($questions as $question) {
            $targets[] = [
                'question_key' => $question->key,
                'section_instance_key' => $sectionInstanceKey,
            ];
        }

        return app(VisibilityResolver::class)->resolve(
            $allQuestions,
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
            customerMode: true,
        );
    }

    private function currentStepRequiredSatisfied(): bool
    {
        $step = $this->currentStep();
        if ($step === null) {
            return true;
        }

        $question = app(IntakeStepBuilder::class)->questionForStep(
            $this->version(),
            $step['section_key'],
            $step['question_key'],
        );

        if (! $question instanceof IntakeQuestion) {
            return true;
        }

        $visibility = $this->visibilityForQuestions(
            collect([$question]),
            $step['section_instance_key'],
        );
        $key = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
        $state = $visibility[$key] ?? ['visible' => false, 'required' => false];

        if (! $state['visible'] || ! $state['required']) {
            return true;
        }

        $reader = app(AnswerValueReader::class);
        $value = is_array($this->form[$key] ?? null) ? $this->form[$key] : null;

        if (! $reader->isFilled($value, $question->type)) {
            return false;
        }

        if ($question->type === QuestionType::Photo) {
            return $this->photoStepContentSatisfied($question->key, $step['section_instance_key']);
        }

        return true;
    }

    private function photoStepContentSatisfied(string $questionKey, ?string $sectionInstanceKey): bool
    {
        return PhotoContentSatisfaction::isSatisfied($this->intake(), $questionKey, $sectionInstanceKey);
    }

    /**
     * @return array{
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
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string,
     *     }>
     * }|null
     */
    private function currentStep(): ?array
    {
        $steps = $this->steps();

        if ($this->activeStepKey !== '') {
            $index = app(IntakeStepBuilder::class)->indexForStepKey($steps, $this->activeStepKey);
            if ($index !== null) {
                return $steps[$index];
            }
        }

        return $steps[$this->stepIndex] ?? null;
    }

    private function rememberCurrentCursor(): void
    {
        $step = $this->currentStep();
        if ($step === null) {
            return;
        }

        $this->intake()->update([
            'current_section_key' => $step['section_key'],
            'current_question_key' => $step['question_key'],
            'current_section_instance_key' => $step['section_instance_key'],
        ]);
    }

    /**
     * After an answer changes visibility, keep the wizard on the same question when possible.
     * When the active question disappears, compare with the previous step list and move to
     * the first remaining real task after the old step.
     */
    private function realignToActiveStep(): void
    {
        $previousKeys = $this->knownStepKeys;
        $steps = $this->steps();
        if ($steps === []) {
            $this->stepIndex = 0;
            $this->activeStepKey = '';
            $this->knownStepKeys = [];

            return;
        }

        $newKeys = array_map(
            static fn (array $step): string => $step['key'],
            $steps,
        );

        $preferredKey = $this->activeStepKey !== ''
            ? $this->activeStepKey
            : ($previousKeys[$this->stepIndex] ?? ($newKeys[$this->stepIndex] ?? null));

        $targetKey = null;

        if (is_string($preferredKey) && in_array($preferredKey, $newKeys, true)) {
            $targetKey = $preferredKey;
        } elseif (is_string($preferredKey) && $previousKeys !== []) {
            $oldIndex = array_search($preferredKey, $previousKeys, true);

            if ($oldIndex !== false) {
                for ($i = $oldIndex + 1; $i < count($previousKeys); $i++) {
                    if (in_array($previousKeys[$i], $newKeys, true)) {
                        $targetKey = $previousKeys[$i];
                        break;
                    }
                }
            }
        }

        if ($targetKey === null && $previousKeys !== []) {
            // Geen opvolger: neem de laatste eerdere stap die nog bestaat.
            for ($i = count($previousKeys) - 1; $i >= 0; $i--) {
                if (in_array($previousKeys[$i], $newKeys, true)) {
                    $targetKey = $previousKeys[$i];
                    break;
                }
            }
        }

        $index = $targetKey === null
            ? null
            : app(IntakeStepBuilder::class)->indexForStepKey($steps, $targetKey);

        $this->stepIndex = $index ?? min(max(0, $this->stepIndex), count($steps) - 1);
        $this->clampStepIndex($steps);
        $this->syncActiveStepKey($steps);
        $this->knownStepKeys = $newKeys;
        $this->rememberCurrentCursor();
    }

    /**
     * @param  list<array{key: string}>  $steps
     */
    private function syncActiveStepKey(array $steps): void
    {
        $this->activeStepKey = $steps[$this->stepIndex]['key'] ?? '';
    }

    /**
     * @param  list<array{key: string}>  $steps
     */
    private function clampStepIndex(array $steps): void
    {
        if ($steps === []) {
            $this->stepIndex = 0;

            return;
        }

        $this->stepIndex = min(max(0, $this->stepIndex), count($steps) - 1);
    }

    public function recordNetworkUploadTiming(int $uploadId, int $ms): void
    {
        if ($ms < 0 || $uploadId <= 0) {
            return;
        }

        $upload = IntakeUpload::query()
            ->where('intake_id', $this->intake()->id)
            ->find($uploadId);

        if (! $upload instanceof IntakeUpload) {
            return;
        }

        app(AiTraceRecorder::class)->recordNetworkUploadMs($upload, $ms);
    }

    private function rememberStoredUpload(IntakeUpload $upload): void
    {
        $this->dispatch('ai-upload-stored', uploadId: $upload->id);
    }

    private function correlationIdForUpload(IntakeUpload $upload): string
    {
        $timings = $upload->processing_timings ?? [];
        if (is_string($timings['correlation_id'] ?? null) && $timings['correlation_id'] !== '') {
            return (string) $timings['correlation_id'];
        }

        $id = (string) Str::uuid();
        $timings['correlation_id'] = $id;
        $upload->update(['processing_timings' => $timings]);

        return $id;
    }

    private function latestUploadCorrelationId(string $questionKey, ?string $instanceKey): ?string
    {
        if ($questionKey === 'fusebox_photo') {
            $upload = IntakeUpload::query()
                ->where('intake_id', $this->intake()->id)
                ->whereIn('question_key', ['fusebox_photo', 'fusebox_photo_extra'])
                ->whereNull('section_instance_key')
                ->latest('id')
                ->first();

            return $upload instanceof IntakeUpload ? $this->correlationIdForUpload($upload) : null;
        }

        $query = IntakeUpload::query()
            ->where('intake_id', $this->intake()->id)
            ->where('question_key', $questionKey)
            ->latest('id');

        if ($instanceKey === null) {
            $query->whereNull('section_instance_key');
        } else {
            $query->where('section_instance_key', $instanceKey);
        }

        $upload = $query->first();

        return $upload instanceof IntakeUpload
            ? $this->correlationIdForUpload($upload)
            : null;
    }
}
