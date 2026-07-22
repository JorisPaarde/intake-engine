<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\AssessPhotoUsability;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\DeleteFollowUpUpload;
use App\Domains\Intake\Actions\DeleteIntakeUpload;
use App\Domains\Intake\Actions\SaveFollowUpTextResponse;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AnswerValueReader;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\IntakePrefillResolver;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Enums\AiRunStatus;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\QuestionType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
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
     *     is_required: bool
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
        $this->applyPrefillForActiveStep();
    }

    public function render(): View
    {
        $intake = $this->intake();

        if ($this->followUpMode) {
            return $this->renderFollowUp($intake);
        }

        $version = $this->version();
        $steps = $this->steps();
        $this->clampStepIndex($steps);
        $step = $steps[$this->stepIndex] ?? null;
        $progress = app(ProgressCalculator::class)->calculate($intake, $version);

        $question = null;
        $visibility = [];
        $uploadsByQuestion = [];
        $displayPhotoHint = $this->photoHint;

        if ($step !== null && ! $this->completed) {
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
            'progressPercent' => $this->completed ? 100 : $progress['percent'],
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

            if ($upload->question_key === 'fusebox_photo' && $upload->section_instance_key === null) {
                $assessment = app(AssessFuseboxPhotos::class);
                $assessment->invalidateDerivedState($this->intake());
                $this->applyFuseboxAssessment($assessment->handle($this->intake()));
            }

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

        return view('livewire.customer.follow-up-wizard', [
            'intake' => $intake,
            'round' => $round,
            'items' => $items,
            'item' => $item,
            'isLastStep' => $this->followUpStepIndex >= $items->count() - 1,
            'followUpPhotoHint' => $item instanceof IntakeFollowUpItem
                && $item->type === FollowUpItemType::Photo
                ? $this->persistentFollowUpPhotoHint($item)
                : null,
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

        if ($item->type === FollowUpItemType::Text) {
            $response = trim((string) ($this->followUpResponses[$item->id] ?? ''));

            if ($response === '') {
                $this->addError('follow_up', 'Vul eerst een antwoord in.');

                return false;
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

        return true;
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
        $this->persistComposite($composite);
        $this->saveMessage = 'Opgeslagen';
        $this->showMissing = false;
        $this->realignToActiveStep();
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
        $property = $type === FollowUpItemType::Photo
            ? 'followUpPhotoFiles'
            : 'followUpDocumentFiles';
        $errorBagKey = $property.'.'.$key;
        $this->resetErrorBag($errorBagKey);
        $files = $this->normalizeUploadFiles($this->{$property}[$itemId] ?? $value);

        if ($files === []) {
            return;
        }

        $item = $this->followUpItem($itemId);
        $stored = 0;
        $error = null;

        foreach ($files as $file) {
            try {
                $upload = app(StoreFollowUpUpload::class)->handle($this->intake(), $item, $file);
                $stored++;

                if ($type === FollowUpItemType::Photo) {
                    app(AssessPhotoUsability::class)->handle($upload);
                }
            } catch (ValidationException $exception) {
                $error = $exception->errors()['upload'][0]
                    ?? $exception->errors()['photo'][0]
                    ?? 'Bestand uploaden mislukt.';
            }
        }

        $this->{$property}[$itemId] = null;

        if ($type === FollowUpItemType::Photo) {
            $this->saveMessage = $stored === 1 ? 'Foto opgeslagen' : ($stored > 1 ? "{$stored} foto's opgeslagen" : '');
        } else {
            $this->saveMessage = $stored === 1 ? 'Document opgeslagen' : ($stored > 1 ? "{$stored} documenten opgeslagen" : '');
        }

        if ($error !== null) {
            $this->addError($errorBagKey, $error);
        }
    }

    /**
     * Upload each selected file independently so one failure does not block the rest (BL-021).
     *
     * @param  list<TemporaryUploadedFile>  $files
     */
    private function uploadPhotosForComposite(string $composite, array $files): void
    {
        $maxKb = (int) config('intake.uploads.max_kilobytes', 5120);
        [$questionKey, $instanceKey] = $this->splitComposite($composite);

        $stored = 0;
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<string> $hints */
        $hints = [];

        foreach ($files as $file) {
            try {
                Validator::make(
                    ['photo' => $file],
                    ['photo' => ['required', 'file', 'max:'.$maxKb]],
                    [],
                    ['photo' => 'foto'],
                )->validate();

                $upload = app(StoreIntakeUpload::class)->handle(
                    $this->intake(),
                    $questionKey,
                    $instanceKey,
                    $file,
                );
                $stored++;

                // BL-007: non-blocking local usability check — a hint, never a block.
                $verdict = app(AssessPhotoUsability::class)->handle($upload);
                $retakeHint = $this->photoRetakeHint($verdict, $questionKey);

                if ($retakeHint !== null) {
                    $hints[] = $retakeHint;
                }

                // BL-025: invalidate request-cache after the upload changed intake state.
                $this->forgetIntakeDerivedCaches();
            } catch (ValidationException $e) {
                $errors[] = $e->errors()['photo'][0]
                    ?? $e->errors()['photoFiles.'.$composite][0]
                    ?? 'Upload mislukt. Probeer het opnieuw.';
            }
        }

        if ($stored > 0 && $questionKey === 'fusebox_photo' && $instanceKey === null) {
            $assessmentHint = $this->applyFuseboxAssessment(
                app(AssessFuseboxPhotos::class)->handle($this->intake()),
            );

            if ($assessmentHint !== null) {
                $hints[] = $assessmentHint;
            }
        }

        // Alleen deze foto-composite verversen — volledige hydrate wist niet-opgeslagen velden.
        $this->photoFiles[$composite] = [];
        $this->refreshAnswerInForm($composite);
        $this->showMissing = false;
        $this->resetErrorBag('photoFiles.'.$composite);

        $this->photoHint[$composite] = $hints === [] ? null : implode(' ', array_values(array_unique($hints)));

        if ($stored === 1) {
            $this->saveMessage = 'Foto opgeslagen';
        } elseif ($stored > 1) {
            $this->saveMessage = $stored." foto's opgeslagen";
        } else {
            $this->saveMessage = '';
        }

        if ($errors !== []) {
            $unique = array_values(array_unique($errors));
            $this->addError('photoFiles.'.$composite, implode(' ', $unique));
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

        if ($question->key === 'fusebox_photo') {
            $fact = $intake->externalFacts()
                ->where('fact_key', 'fusebox_photo_assessment')
                ->where('source', AssessFuseboxPhotos::SOURCE)
                ->latest('id')
                ->first();
            $instruction = $fact?->value['retake_instruction'] ?? null;

            if (is_string($instruction) && trim($instruction) !== '') {
                $hints[] = 'Voor een betere beoordeling: '.trim($instruction);
            }
        }

        return $hints === [] ? null : implode(' ', array_values(array_unique($hints)));
    }

    private function persistentFollowUpPhotoHint(IntakeFollowUpItem $item): ?string
    {
        $hints = [];

        foreach ($item->uploads as $upload) {
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

    private function applyFuseboxAssessment(?AiRun $run): ?string
    {
        $composite = VisibilityResolver::compositeKey('free_group_known', null);
        $this->refreshAnswerInForm($composite);

        $answer = $this->intake()->answers()
            ->where('question_key', 'free_group_known')
            ->whereNull('section_instance_key')
            ->first();

        if ($answer?->prefill_source === 'ai') {
            $this->prefillNotice[$composite] = 'Ingeschat op basis van uw meterkastfoto — klopt deze keuze?';
        } else {
            unset($this->prefillNotice[$composite]);
        }

        if ($run?->status !== AiRunStatus::Succeeded || ! is_array($run->output)) {
            return null;
        }

        $instruction = $run->output['retake_instruction'] ?? null;

        if (is_string($instruction) && trim($instruction) !== '') {
            return 'Voor een betere beoordeling: '.trim($instruction);
        }

        return null;
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
        if ($step === null) {
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

        $currentKey = $this->activeStepKey !== ''
            ? $this->activeStepKey
            : ($this->steps()[$this->stepIndex]['key'] ?? null);
        $this->saveCurrentStep();

        if (! $this->currentStepRequiredSatisfied()) {
            $this->showMissing = true;
            $this->saveMessage = '';

            return;
        }

        $this->showMissing = false;
        $this->completionMissing = [];

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

        $currentKey = $this->activeStepKey !== ''
            ? $this->activeStepKey
            : ($this->steps()[$this->stepIndex]['key'] ?? null);
        $this->saveCurrentStep();

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

        $this->saveCurrentStep();
        $this->stepIndex = $index;
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
     *     is_required: bool
     * }>
     */
    private function steps(): array
    {
        $signature = $this->liveAnswersSignature();

        if ($this->resolvedSteps !== null && $this->resolvedStepsFormSignature === $signature) {
            return $this->resolvedSteps;
        }

        $this->resolvedSteps = app(IntakeStepBuilder::class)->build(
            $this->intake(),
            $this->version(),
            $this->liveAnswers(),
        );
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
                $notices[$composite] = 'Alvast ingevuld door uw installateur — controleer en pas aan indien nodig';
            } elseif ($answer->prefill_source === 'ai') {
                $notices[$composite] = 'Ingeschat op basis van uw meterkastfoto — klopt deze keuze?';
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
        $this->prefillNotice[$composite] = 'Overgenomen van '.$suggestion['source_label'].' — controleer en pas aan indien nodig';
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

        return $reader->isFilled($value, $question->type);
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
     *     is_required: bool
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
     */
    private function realignToActiveStep(): void
    {
        $steps = $this->steps();
        if ($steps === []) {
            $this->stepIndex = 0;
            $this->activeStepKey = '';

            return;
        }

        $preferredKey = $this->activeStepKey !== ''
            ? $this->activeStepKey
            : ($steps[$this->stepIndex]['key'] ?? null);

        $this->stepIndex = app(IntakeStepBuilder::class)->indexForStepKey($steps, $preferredKey)
            ?? min($this->stepIndex, count($steps) - 1);
        $this->clampStepIndex($steps);
        $this->syncActiveStepKey($steps);
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
}
