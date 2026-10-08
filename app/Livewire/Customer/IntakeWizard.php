<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\AssessPhotoUsability;
use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceRequestIdResolver;
use App\Domains\AI\Services\PhotoAssessmentLifecycle;
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
use App\Domains\Intake\Services\PublicDemoSession;
use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Domains\Intake\Support\KnownSummaryCatalog;
use App\Domains\Intake\Support\MustAcceptQuestions;
use App\Domains\Intake\Support\OutdoorPhotoReuse;
use App\Domains\Intake\Support\PhotoAssessmentSoftTimeout;
use App\Domains\Intake\Support\PhotoContentSatisfaction;
use App\Domains\Intake\Support\PhotoCustomerStatus;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Domains\Intake\Support\PhotoUploadLimits;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\QuestionType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
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
     * Composite key → client-reported original capture dimensions (BL-128).
     * Filled by browser downscale before Livewire upload; FIFO per file in the batch.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $photoClientOriginals = [];

    /**
     * Follow-up item id → client-reported original capture dimensions.
     * Same role as {@see $photoClientOriginals} for the main wizard.
     *
     * @var array<array-key, array<int, array<string, mixed>>>
     */
    public array $followUpPhotoClientOriginals = [];

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
     * Only shown when {@see $photoHintScope} still matches the active upload + analysis.
     *
     * @var array<string, string|null>
     */
    public array $photoHint = [];

    /**
     * Scopes ephemeral photo feedback to the upload(s) + analysis run that produced it (BL-131).
     * Cleared on delete/replace; ignored when the active uploads no longer match.
     *
     * @var array<string, array<string, mixed>|null>
     */
    public array $photoHintScope = [];

    /**
     * Upload-ids that produced the current {@see $progressExtraNote} (BL-131).
     *
     * @var list<int>
     */
    public array $progressExtraNoteUploadIds = [];

    public string $saveMessage = '';

    public bool $showMissing = false;

    public bool $completed = false;

    /**
     * Foto die net is weggehaald en 8 s ongedaan gemaakt kan worden (BL-147, UX #16.4).
     *
     * @var array{item_id: int, upload_id: int, answered_at: string|null}|null
     */
    public ?array $pendingFollowUpRemoval = null;

    public bool $followUpMode = false;

    /**
     * Wacht op async request-prefill (keuze 2A) vóór de steplijst wordt gebouwd.
     */
    #[Locked]
    public bool $waitingForPrefill = false;

    /** Unix-timestamp waarop de wizard-wacht begon. */
    #[Locked]
    public ?int $prefillWaitStartedAt = null;

    #[Locked]
    public int $followUpRoundId = 0;

    /**
     * Highest customer-wizard progress % shown this Livewire session (BL-123).
     * Prevents the bar from dropping when the step list grows after photo analysis.
     */
    #[Locked]
    public int $progressHighWater = 0;

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

    /** Unix-timestamp waarop assessing begon (UI soft-timeout). */
    public ?int $uploadPhaseStartedAt = null;

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

    /** @var list<string> */
    public array $assessmentUiReleased = [];

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
     *     kind?: 'question'|'known_summary'|'question_group'|'closing_wishes',
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string
     *     }>,
     *     group_key?: string,
     *     group_question_keys?: list<string>,
     *     bundle_question_keys?: list<string>
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

        // Volgend bezoek aan de klantlink: foto's die nog in de prullenbak staan
        // (tabblad binnen 8 s gesloten) gaan nu echt weg (BL-147, UX #16.4).
        app(DeleteFollowUpUpload::class)->purgePendingFor($intake);

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
            $this->restoreFollowUpStepIndex($round);

            return;
        }

        // Async prefill nog bezig → kalm wachtscherm; steplijst pas ná afronden (BL-140).
        // Geen wacht als de klant al begonnen is (cursor/eigen antwoord).
        if (! (bool) config('ai.request_prefill.sync_on_create', false)
            && DeriveIntentFromRequestJob::hasRecentPending($intake->id)
            && ! app(DeriveIntentFromRequest::class)->customerHasStarted($intake)) {
            $this->waitingForPrefill = true;
            $this->prefillWaitStartedAt = now()->getTimestamp();
            $this->resolvedIntake = $intake->loadMissing(['answers', 'uploads']);

            return;
        }

        $this->beginWizardAfterPrefill($intake);
    }

    /**
     * Poll tijdens prefill-wacht: klaar of timeout → lokale parse + steplijst.
     */
    public function pollPrefillWait(): void
    {
        if (! $this->waitingForPrefill) {
            return;
        }

        $maxWait = max(0, (int) config('ai.request_prefill.wizard_wait_seconds', 20));
        $started = $this->prefillWaitStartedAt ?? now()->getTimestamp();
        $timedOut = (now()->getTimestamp() - $started) >= $maxWait;
        $stillPending = DeriveIntentFromRequestJob::hasRecentPending($this->intakeId);

        if ($stillPending && ! $timedOut) {
            return;
        }

        $this->waitingForPrefill = false;
        $this->prefillWaitStartedAt = null;
        $this->beginWizardAfterPrefill($this->intake());
    }

    private function beginWizardAfterPrefill(Intake $intake): void
    {
        // Herstelt ook eerder aangemaakte opnames waarvan de installateur de openingszin
        // al invulde. Alleen de lokale, evidente parser draait hier; een externe call
        // hoort niet stil bij iedere geopende klantlink te starten.
        // skipIfCustomerStarted: mount mag late prefill niet herhalen na klantstart.
        app(DeriveIntentFromRequest::class)->handle(
            $intake,
            allowExternal: false,
            skipIfCustomerStarted: true,
        );
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

        if ($this->waitingForPrefill) {
            return view('livewire.customer.intake-wizard', [
                'intake' => $intake,
                'token' => $this->token,
                'waitingForPrefill' => true,
                'completed' => false,
                'demoShortCustomer' => false,
                'demoCustomerPath' => false,
                'demoInstallerReturnUrl' => null,
                'progressPercent' => 0,
                'progressAnswered' => 0,
                'progressTotal' => 0,
                'progressExtraNote' => '',
                'step' => null,
                'steps' => [],
                'question' => null,
                'groupQuestions' => [],
                'bundleQuestions' => [],
                'visibility' => [],
                'uploadsByQuestion' => [],
                'displayPhotoHint' => [],
                'photoMismatchAssessment' => null,
                'photoNeedsOverride' => false,
                'photoNeedsQualityHint' => false,
                'stepDisplayNumber' => 0,
                'stepDisplayTotal' => 0,
                'saveMessage' => '',
                'isLastStep' => false,
                'isKnownSummary' => false,
                'missingRequired' => [],
                'uploadPhase' => '',
                'uploadPhaseMessage' => '',
                'uploadPhaseComposite' => '',
                'pendingAssessUploadIds' => [],
                'assessmentUiReleased' => [],
                'maxUploadKb' => (int) ceil(PhotoUploadLimits::hardMaxBytes() / 1024),
                'uploadHardMaxBytes' => PhotoUploadLimits::hardMaxBytes(),
                'uploadHardMaxMegapixels' => PhotoUploadLimits::hardMaxMegapixels(),
                'uploadTooLargeMessage' => PhotoUploadLimits::tooLargeMessage(),
            ]);
        }

        $version = $this->version();
        $steps = $this->steps();
        // Display and validation share one resolver: stable step key, never a bare index.
        $step = $this->resolveDisplayedStep($steps);
        if ($step === null && $steps !== []) {
            $this->realignToActiveStep();
            $steps = $this->steps();
            $step = $this->resolveDisplayedStep($steps);
        }
        // Progress / "Vraag X van Y" use the same index as the resolved step (BL-129 UX).
        $displayIndex = $this->stepIndex;
        // Banner-variant for the primary demo customer wizard (not a shortened allowlist).
        $demoCustomerPath = $intake->is_demo && ! $this->followUpMode;

        $question = null;
        $groupQuestions = [];
        $bundleQuestions = [];
        $visibility = [];
        $uploadsByQuestion = [];
        $displayPhotoHint = [];
        $photoMismatchAssessment = null;
        $photoNeedsOverride = false;
        $photoNeedsQualityHint = false;
        $stepKind = is_array($step) ? ($step['kind'] ?? 'question') : 'question';

        if ($step !== null && ! $this->completed && $stepKind === 'closing_wishes') {
            $bundleKeys = $step['bundle_question_keys'] ?? IntakeStepBuilder::CLOSING_WISH_KEYS;
            foreach ($bundleKeys as $bundleKey) {
                $bundleQuestion = app(IntakeStepBuilder::class)->questionForStep(
                    $version,
                    $step['section_key'],
                    $bundleKey,
                );
                if ($bundleQuestion instanceof IntakeQuestion) {
                    $this->ensureAnswerShape($bundleQuestion, null);
                    $bundleQuestions[] = $bundleQuestion;
                }
            }
        } elseif ($step !== null && ! $this->completed && $stepKind !== 'known_summary') {
            $question = app(IntakeStepBuilder::class)->questionForStep(
                $version,
                $step['section_key'],
                $step['question_key'],
            );

            $questionsForVisibility = collect();
            if ($question instanceof IntakeQuestion) {
                $questionsForVisibility->push($question);
            }

            if ($stepKind === 'question_group') {
                foreach ($step['group_question_keys'] ?? [] as $groupKey) {
                    $groupQuestion = app(IntakeStepBuilder::class)->questionForStep(
                        $version,
                        $step['section_key'],
                        $groupKey,
                    );
                    if ($groupQuestion instanceof IntakeQuestion) {
                        $groupQuestions[] = $groupQuestion;
                        if (! $questionsForVisibility->contains(
                            static fn (IntakeQuestion $q): bool => $q->key === $groupQuestion->key,
                        )) {
                            $questionsForVisibility->push($groupQuestion);
                        }
                    }
                }
            }

            if ($questionsForVisibility->isNotEmpty()) {
                $visibility = $this->visibilityForQuestions(
                    $questionsForVisibility,
                    $step['section_instance_key'],
                );
                $uploadsByQuestion = $this->uploadsForStep($step['section_instance_key']);

                foreach ($questionsForVisibility as $visibleQuestion) {
                    $this->ensureAnswerShape($visibleQuestion, $step['section_instance_key']);
                }

                /** @var list<IntakeQuestion> $photoQuestionsForStep */
                $photoQuestionsForStep = [];
                if ($question instanceof IntakeQuestion && $question->type === QuestionType::Photo) {
                    $photoQuestionsForStep[] = $question;
                }
                if ($stepKind === 'question_group') {
                    foreach ($groupQuestions as $groupQuestion) {
                        if ($groupQuestion->type === QuestionType::Photo) {
                            $photoQuestionsForStep[] = $groupQuestion;
                        }
                    }
                }

                foreach ($photoQuestionsForStep as $photoQuestion) {
                    $composite = VisibilityResolver::compositeKey(
                        $photoQuestion->key,
                        $step['section_instance_key'],
                    );
                    $stepUploads = $uploadsByQuestion[$photoQuestion->key] ?? collect();

                    $scopedHint = $this->scopedPhotoHintMessage($composite, $stepUploads);
                    if ($scopedHint !== null) {
                        $displayPhotoHint[$composite] = $scopedHint;
                    } else {
                        $persistentHint = $this->persistentIntakePhotoHint(
                            $intake,
                            $photoQuestion,
                            $stepUploads,
                        );

                        if ($persistentHint !== null) {
                            $displayPhotoHint[$composite] = $persistentHint;
                        }
                    }

                    // Banner zolang de foto een expliciete override nodig heeft.
                    if (PhotoOverridePolicy::hasUnresolvedOverride($stepUploads)) {
                        $photoNeedsOverride = true;
                        $photoMismatchAssessment = PhotoContentSatisfaction::unresolvedWrongSubject(
                            $stepUploads,
                        );
                        // Dedup: één unieke hinttekst (categorie óf kwaliteit, niet beide).
                        $uniqueHints = PhotoOverridePolicy::uniqueCustomerFeedback($stepUploads);
                        if ($uniqueHints !== []) {
                            $displayPhotoHint[$composite] = $uniqueHints[0];
                        }
                    }

                    // Oranje kwaliteitshint alleen zonder override-panel (anders dubbel).
                    $photoNeedsQualityHint = ! $photoNeedsOverride
                        && $this->uploadsNeedQualityHint($stepUploads);
                }
            }
        }

        $stepTotal = count($steps);
        $progressPercent = $this->resolveStepProgressPercent($steps);
        // Zelfde bron als de %-balk: afgeronde stappen / totaal (huidige open telt niet mee).
        $progressAnswered = $this->completed
            ? $stepTotal
            : $this->countDoneSteps($steps);

        return view('livewire.customer.intake-wizard', [
            'intake' => $intake,
            'steps' => $steps,
            'step' => $step,
            'question' => $question,
            'groupQuestions' => $groupQuestions,
            'bundleQuestions' => $bundleQuestions,
            'visibility' => $visibility,
            'uploadsByQuestion' => $uploadsByQuestion,
            'displayPhotoHint' => $displayPhotoHint,
            'photoMismatchAssessment' => $photoMismatchAssessment,
            'photoNeedsOverride' => $photoNeedsOverride,
            'photoNeedsQualityHint' => $photoNeedsQualityHint,
            // Prop name kept for BL-076 banner sibling; value means "primary customer path".
            'demoShortCustomer' => $demoCustomerPath,
            'demoInstallerReturnUrl' => $demoCustomerPath
                ? route('intakes.show', $intake)
                : null,
            'progressPercent' => $progressPercent,
            'progressAnswered' => $progressAnswered,
            'progressTotal' => $stepTotal,
            'stepDisplayNumber' => $this->completed ? $stepTotal : ($displayIndex + 1),
            'stepDisplayTotal' => max(1, $stepTotal),
            'progressExtraNote' => $this->progressExtraNote,
            'uploadPhase' => $this->uploadPhase,
            'uploadPhaseMessage' => $this->uploadPhaseMessage,
            'uploadPhaseComposite' => $this->uploadPhaseComposite,
            'pendingAssessUploadIds' => $this->pendingAssessUploadIds,
            'assessmentUiReleased' => $this->assessmentUiReleased,
            'missingRequired' => $this->completionMissing,
            'isLastStep' => $displayIndex >= $stepTotal - 1,
            'isKnownSummary' => $stepKind === 'known_summary',
            'maxUploadKb' => (int) ceil(PhotoUploadLimits::hardMaxBytes() / 1024),
            'uploadHardMaxBytes' => PhotoUploadLimits::hardMaxBytes(),
            'uploadHardMaxMegapixels' => PhotoUploadLimits::hardMaxMegapixels(),
            'uploadTooLargeMessage' => PhotoUploadLimits::tooLargeMessage(),
        ]);
    }

    /**
     * Fresh signed URL for retrying a Livewire temp upload after an empty/invalid 200 (BL-128).
     */
    #[Renderless]
    public function freshSignedUploadUrl(): string
    {
        return (new GenerateSignedUploadUrl)->forLocal();
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    private function uploadsNeedQualityHint(Collection $uploads): bool
    {
        foreach ($uploads as $upload) {
            $verdict = $upload->usability_verdict;
            if ($verdict instanceof PhotoUsabilityVerdict && $verdict->customerHint() !== null) {
                return true;
            }
        }

        return false;
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
        $this->finalizePendingFollowUpRemoval();

        $item = $this->followUpItem($itemId);
        $upload = IntakeUpload::query()->findOrFail($uploadId);
        $action = app(DeleteFollowUpUpload::class);

        try {
            if ($item->type === FollowUpItemType::Photo) {
                // Eerst alleen verbergen; echt wissen na 8 s of bij Volgende (UX #16.4).
                $previousAnsweredAt = $action->softRemove($this->intake(), $item, $upload);
                $this->pendingFollowUpRemoval = [
                    'item_id' => $item->id,
                    'upload_id' => $upload->id,
                    'answered_at' => $previousAnsweredAt?->toIso8601String(),
                ];
                $this->saveMessage = '';
            } else {
                $action->handle($this->intake(), $item, $upload);
                $this->saveMessage = 'Document verwijderd';
            }
            $this->forgetIntakeDerivedCaches();
            $this->resetErrorBag('follow_up');
        } catch (ValidationException $exception) {
            $this->addError('follow_up', $exception->errors()['upload'][0]
                ?? $exception->errors()['photo'][0]
                ?? 'Verwijderen mislukt.');
        }
    }

    /**
     * “Ongedaan maken”: zet de foto en de beoordeling terug.
     */
    public function undoFollowUpUploadRemoval(): void
    {
        $pending = $this->pendingFollowUpRemoval;
        $this->pendingFollowUpRemoval = null;

        if ($pending === null) {
            return;
        }

        try {
            app(DeleteFollowUpUpload::class)->restore(
                $this->intake(),
                $this->followUpItem((int) $pending['item_id']),
                (int) $pending['upload_id'],
                is_string($pending['answered_at']) ? Carbon::parse($pending['answered_at']) : null,
            );
            $this->forgetIntakeDerivedCaches();
            $this->resetErrorBag('follow_up');
        } catch (ValidationException $exception) {
            $this->addError('follow_up', $exception->errors()['upload'][0] ?? 'Ongedaan maken mislukt.');
        }
    }

    /**
     * Echt wissen van de weggehaalde foto (na 8 s vanuit de melding, of bij navigatie).
     */
    public function finalizePendingFollowUpRemoval(?int $uploadId = null): void
    {
        $pending = $this->pendingFollowUpRemoval;

        // A late timer from an earlier toast must not wipe a newer pending removal.
        if ($pending === null || ($uploadId !== null && (int) $pending['upload_id'] !== $uploadId)) {
            return;
        }

        $this->pendingFollowUpRemoval = null;

        app(DeleteFollowUpUpload::class)->finalize($this->intake(), (int) $pending['upload_id']);
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
            $this->clearPhotoFeedbackForComposite($composite);
            $this->clearProgressExtraNoteIfRelatedToUpload($upload->id);
            $this->refreshAnswerInForm($composite);
            $this->saveMessage = 'Foto verwijderd.';
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
        $this->finalizePendingFollowUpRemoval();

        if (! $this->currentFollowUpSatisfied()) {
            return;
        }

        $count = $this->followUpRound()->items->count();
        $this->followUpStepIndex = min($this->followUpStepIndex + 1, max(0, $count - 1));
        $this->saveMessage = '';
        $this->persistFollowUpStepIndex();
    }

    public function previousFollowUp(): void
    {
        $this->finalizePendingFollowUpRemoval();

        $this->followUpStepIndex = max(0, $this->followUpStepIndex - 1);
        $this->saveMessage = '';
        $this->persistFollowUpStepIndex();
    }

    public function completeFollowUp(): void
    {
        $this->finalizePendingFollowUpRemoval();

        if (! $this->currentFollowUpSatisfied()) {
            return;
        }

        // Hard gate: elke niet-goede foto vereist Vervang of Toch doorgaan.
        if ($this->followUpRoundHasUnresolvedOverride()) {
            $this->addError(
                'follow_up',
                PhotoOverridePolicy::OVERRIDE_MESSAGE,
            );

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
        $followUpOverrideUpload = $item instanceof IntakeFollowUpItem
            && $item->type === FollowUpItemType::Photo
            ? PhotoOverridePolicy::unresolvedOverride($item->uploads)
            : null;
        $followUpMismatch = $followUpOverrideUpload instanceof IntakeUpload
            ? $followUpOverrideUpload->contentAssessment()
            : null;
        // Alleen wrong_subject-assessment voor mismatch-bannertekst; overige issues via hint/panel.
        if ($followUpMismatch instanceof PhotoContentAssessment
            && $followUpMismatch->status() !== PhotoContentAssessment::STATUS_WRONG_SUBJECT) {
            $followUpMismatch = null;
        }
        $followUpNeedsOverride = $followUpOverrideUpload instanceof IntakeUpload;
        $followUpFeedbackHints = $item instanceof IntakeFollowUpItem
            && $item->type === FollowUpItemType::Photo
            ? PhotoOverridePolicy::uniqueCustomerFeedback($item->uploads)
            : [];

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
            'pendingAssessUploadIds' => $this->pendingAssessUploadIds,
            'assessmentUiReleased' => $this->assessmentUiReleased,
            'followUpPhotoHint' => $followUpFeedbackHints[0] ?? null,
            'followUpMismatchAssessment' => $followUpMismatch,
            'followUpNeedsOverride' => $followUpNeedsOverride,
            'followUpThankYouMessage' => PhotoOverridePolicy::THANK_YOU_COPY,
            'followUpDemoReturnUrl' => app(PublicDemoSession::class)->workspaceReturnUrl(request(), $intake),
            'choiceOptions' => $item instanceof IntakeFollowUpItem
                && $item->type === FollowUpItemType::Choice
                ? $this->followUpChoiceOptions($item)
                : [],
            'maxUploadKb' => (int) ceil(PhotoUploadLimits::hardMaxBytes() / 1024),
            'uploadHardMaxBytes' => PhotoUploadLimits::hardMaxBytes(),
            'uploadHardMaxMegapixels' => PhotoUploadLimits::hardMaxMegapixels(),
            'uploadTooLargeMessage' => PhotoUploadLimits::tooLargeMessage(),
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

        // Soft-timeout matches the client (ui_soft_timeout_seconds): after that the
        // customer may continue; pending assessment finishes in the background.
        if ($item->type === FollowUpItemType::Photo
            && $item->uploads->contains(
                static fn (IntakeUpload $upload): bool => PhotoAssessmentSoftTimeout::blocksCustomerProgress($upload),
            )) {
            $this->addError('follow_up', 'Even geduld: we beoordelen je foto nog.');

            return false;
        }

        // Known photo issues: block next/complete unless explicitly overridden.
        if ($item->type === FollowUpItemType::Photo
            && PhotoOverridePolicy::hasUnresolvedOverride($item->uploads)) {
            $this->addError(
                'follow_up',
                PhotoOverridePolicy::OVERRIDE_MESSAGE,
            );

            return false;
        }

        return true;
    }

    private function followUpRoundHasUnresolvedOverride(): bool
    {
        foreach ($this->followUpRound()->items as $item) {
            if ($item->type !== FollowUpItemType::Photo) {
                continue;
            }

            if (PhotoOverridePolicy::hasUnresolvedOverride($item->uploads)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Soft continue for follow-up: customer explicitly overrides a non-good photo
     * (wrong subject, low resolution, unusable, not_assessed).
     * Task may then be sent; installer still sees that review is needed.
     */
    public function acceptFollowUpPhotoMismatch(): void
    {
        if ($this->completed || ! $this->followUpMode) {
            return;
        }

        $item = $this->followUpRound()->items->get($this->followUpStepIndex);

        if (! $item instanceof IntakeFollowUpItem || $item->type !== FollowUpItemType::Photo) {
            return;
        }

        $item->loadMissing('uploads');
        $acceptedAny = PhotoOverridePolicy::acceptOverrides($item->uploads) > 0;

        if (! $acceptedAny) {
            return;
        }

        $this->resetErrorBag('follow_up');
        $this->forgetIntakeDerivedCaches();
        $this->saveMessage = '';
    }

    /**
     * Replace non-good follow-up photos and open the file picker.
     */
    public function replaceFollowUpMismatchedPhoto(): void
    {
        $this->finalizePendingFollowUpRemoval();

        if ($this->completed || ! $this->followUpMode) {
            return;
        }

        $item = $this->followUpRound()->items->get($this->followUpStepIndex);

        if (! $item instanceof IntakeFollowUpItem || $item->type !== FollowUpItemType::Photo) {
            return;
        }

        $item->loadMissing('uploads');
        $intake = $this->intake();
        $removed = false;

        foreach ($item->uploads as $upload) {
            if (! PhotoOverridePolicy::needsOverride($upload)) {
                continue;
            }

            app(DeleteFollowUpUpload::class)->handle($intake, $item, $upload);
            $removed = true;
        }

        if (! $removed) {
            return;
        }

        $this->resetErrorBag('follow_up');
        $this->forgetIntakeDerivedCaches();
        $this->saveMessage = '';
        $inputId = 'follow-up-photo-input-'.$item->id;
        $this->js('document.getElementById('.json_encode($inputId).')?.click()');
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
        $this->finalizePendingFollowUpRemoval();

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
        $this->clearProgressExtraNote();
        $this->clearPendingIdsFor($composite);
        // New upload always gets a fresh soft-timeout clock (even when switching composite).
        $this->assessmentUiReleased = array_values(array_filter(
            $this->assessmentUiReleased,
            static fn (string $key): bool => $key !== $composite,
        ));
        $this->uploadPhase = '';
        $this->uploadPhaseMessage = '';
        $this->uploadPhaseStartedAt = null;

        $item = $this->followUpItem($itemId);
        $intake = $this->intake();
        $this->uploadPhaseComposite = $composite;

        $stored = 0;
        $error = null;
        $duplicateNotice = false;
        /** @var list<string> $skippedNames */
        $skippedNames = [];
        /** @var list<int> $storedUploadIds */
        $storedUploadIds = [];
        /** @var array<int, array<string, mixed>> $clientOriginals */
        $clientOriginals = $this->followUpPhotoClientOriginals[$composite] ?? [];

        foreach ($files as $index => $file) {
            try {
                if ($type === FollowUpItemType::Photo && ! $this->temporaryUploadLooksLikePhoto($file)) {
                    $skippedNames[] = $file->getClientOriginalName() ?: 'bestand';

                    continue;
                }

                $clientMeta = is_array($clientOriginals[$index] ?? null) ? $clientOriginals[$index] : [];
                $rawWidth = $clientMeta['width'] ?? null;
                $rawHeight = $clientMeta['height'] ?? null;
                $clientWidth = is_numeric($rawWidth) ? (int) $rawWidth : null;
                $clientHeight = is_numeric($rawHeight) ? (int) $rawHeight : null;

                $upload = app(StoreFollowUpUpload::class)->handle(
                    $intake,
                    $item,
                    $file,
                    clientOriginalWidth: $clientWidth,
                    clientOriginalHeight: $clientHeight,
                );

                if (! $upload->wasRecentlyCreated) {
                    $duplicateNotice = true;

                    if ($type === FollowUpItemType::Photo) {
                        $needsWork = $upload->usability_verdict === null
                            || ! app(PhotoAssessmentLifecycle::class)->isTerminal($upload);

                        if ($needsWork) {
                            $storedUploadIds[] = $upload->id;
                        }
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
        $remainingOriginals = $this->followUpPhotoClientOriginals;
        unset($remainingOriginals[$composite]);
        $this->followUpPhotoClientOriginals = $remainingOriginals;
        $storedUploadIds = array_values(array_unique($storedUploadIds));

        if ($skippedNames !== []) {
            $skipMessage = collect($skippedNames)
                ->unique()
                ->map(static fn (string $name): string => $name.' is geen foto en is niet meegenomen.')
                ->implode(' ');
            $this->addError($errorBagKey, $skipMessage);
        }

        if ($storedUploadIds !== [] && $type === FollowUpItemType::Photo) {
            // BL-143: variants + usability + AI via ProcessIntakePhotoVariantsJob.
            // Always enter assessing so pollPendingAssessments applies quality hints
            // even when the sync queue already reached a terminal status.
            $this->setPendingIdsFor($composite, $storedUploadIds);
            $this->setUploadPhase('assessing', PhotoCustomerStatus::LOOKING);
            // UX: no top "Foto opgeslagen" banner while assessment is still running.
            $this->saveMessage = $duplicateNotice && $stored === 0
                ? 'Deze foto staat er al'
                : '';
            $this->persistFollowUpStepIndex();

            // Sync queue (tests / local): variants may already be terminal — resolve
            // hints/override UI in this same request instead of waiting for wire:poll.
            $this->pollPendingAssessments();

            return;
        }

        if ($stored > 0) {
            $this->clearUploadPhase();
            $this->saveMessage = $type === FollowUpItemType::Photo
                ? ($stored === 1 ? 'Foto opgeslagen' : "{$stored} foto's opgeslagen")
                : ($stored === 1 ? 'Document opgeslagen' : "{$stored} documenten opgeslagen");
            $this->persistFollowUpStepIndex();
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
     * Poll: AI-beoordeling gebeurt in AssessUploadedPhotoJob; hier alleen resultaten ophalen.
     * Blijft beschikbaar als assessPendingUploads voor Alpine-timeout / legacy-tests.
     */
    public function assessPendingUploads(): void
    {
        $this->pollPendingAssessments();
    }

    /**
     * @param  string|null  $composite  Wizard step composite (wire:poll passes this). Defaults to uploadPhaseComposite.
     */
    public function pollPendingAssessments(?string $composite = null): void
    {
        $composite = is_string($composite) && $composite !== ''
            ? $composite
            : $this->uploadPhaseComposite;

        if ($composite === '' || $this->pendingIdsFor($composite) === []) {
            // Geen pending ids maar wel assessing op deze composite → vastgelopen fase opruimen.
            // Do not clear a failed panel via poll (failed is not a poll trigger).
            if ($composite !== ''
                && $composite === $this->uploadPhaseComposite
                && $this->uploadPhase === 'assessing') {
                $this->clearUploadPhase();
            }

            return;
        }

        if ($this->followUpMode) {
            $this->pollPendingFollowUpAssessments($composite);

            return;
        }

        $uploadIds = $this->pendingIdsFor($composite);
        $intake = $this->intake();
        [$questionKey, $instanceKey] = $this->splitComposite($composite);
        $lifecycle = app(PhotoAssessmentLifecycle::class);

        $stillPending = [];
        $hints = [];
        $previousKeys = app(ProgressCalculator::class)->calculate($intake, $this->version())['task_keys'];

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

            // Wacht tot assessment_status terminaal is (assessed / heuristic_rejected / not_assessed / reused).
            if (! $lifecycle->isTerminal($upload)) {
                $stillPending[] = $upload->id;

                continue;
            }

            $retakeHint = $upload->usability_verdict instanceof PhotoUsabilityVerdict
                ? $this->photoRetakeHint($upload->usability_verdict, $upload->question_key)
                : null;
            if ($retakeHint !== null) {
                $hints[] = $retakeHint;
            }

            $assessment = $upload->contentAssessment();
            if ($assessment instanceof PhotoContentAssessment
                && ($msg = $assessment->customerMessage()) !== null) {
                $hints[] = $msg;
            }
        }

        if ($stillPending !== []) {
            $softReleased = in_array($composite, $this->assessmentUiReleased, true);

            if ($this->assessmentSoftTimedOut($composite) && ! $softReleased) {
                $this->softReleasePendingAssessment(
                    $composite,
                    PhotoCustomerStatus::SOFT_TIMEOUT,
                );

                return;
            }

            // After soft-release: quiet poll (no assessing busy UI) until terminal
            // or absolute max wait, then drop pending ids (watchdog still covers DB).
            if ($softReleased) {
                if ($this->assessmentQuietPollExhausted($composite)) {
                    $this->clearPendingIdsFor($composite);
                    if ($this->uploadPhaseComposite === $composite) {
                        $this->uploadPhaseComposite = '';
                    }

                    return;
                }

                $this->setPendingIdsFor($composite, $stillPending);

                return;
            }

            $this->setPendingIdsFor($composite, $stillPending);
            // Re-anchor to oldest pending created_at (not now()) when poll switches composite.
            $this->beginAssessingFromPendingCreatedAt($composite, $stillPending);

            return;
        }

        // Prefill/afgeleide antwoorden door AI-job; ververs formulier.
        if ($questionKey !== '') {
            $this->applyPhotoDerivationResults($questionKey, $instanceKey);
        }

        $this->forgetIntakeDerivedCaches();
        $this->realignToActiveStep();

        $progressAfter = app(ProgressCalculator::class)->calculate($this->intake(), $this->version());
        $this->setProgressExtraNoteFromNewTasks($previousKeys, $progressAfter, $uploadIds);

        $this->storeScopedPhotoHint($composite, $uploadIds, $hints);

        $this->clearPendingIdsFor($composite);
        if ($this->uploadPhaseComposite === $composite) {
            $this->clearUploadPhase();
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
        $this->assessmentUiReleased = array_values(array_filter(
            $this->assessmentUiReleased,
            static fn (string $key): bool => $key !== $composite,
        ));
        // Explicit retry = new assessing clock.
        $this->uploadPhaseStartedAt = null;
        $this->redispatchPendingAssessments($composite);
        $this->setUploadPhase('assessing', PhotoCustomerStatus::LOOKING);
    }

    /**
     * Beoordeel alleen de pending follow-upfoto's van de gepollde composite.
     */
    private function pollPendingFollowUpAssessments(string $composite): void
    {
        $uploadIds = $this->pendingIdsFor($composite);
        $intake = $this->intake();
        $lifecycle = app(PhotoAssessmentLifecycle::class);

        $stillPending = [];

        foreach ($uploadIds as $uploadId) {
            $upload = IntakeUpload::query()
                ->where('intake_id', $intake->id)
                ->whereKey($uploadId)
                ->first();

            if (! $upload instanceof IntakeUpload) {
                continue;
            }

            if (! $lifecycle->isTerminal($upload)) {
                $stillPending[] = $upload->id;
            }
        }

        if ($stillPending !== []) {
            $softReleased = in_array($composite, $this->assessmentUiReleased, true);

            if ($this->assessmentSoftTimedOut($composite) && ! $softReleased) {
                $this->softReleasePendingAssessment(
                    $composite,
                    PhotoCustomerStatus::SOFT_TIMEOUT,
                );

                return;
            }

            if ($softReleased) {
                if ($this->assessmentQuietPollExhausted($composite)) {
                    $this->clearPendingIdsFor($composite);
                    if ($this->uploadPhaseComposite === $composite) {
                        $this->uploadPhaseComposite = '';
                    }

                    return;
                }

                $this->setPendingIdsFor($composite, $stillPending);

                return;
            }

            $this->setPendingIdsFor($composite, $stillPending);
            // Re-anchor to oldest pending created_at (not now()) when poll switches composite.
            $this->beginAssessingFromPendingCreatedAt($composite, $stillPending);

            return;
        }

        // Override-/mismatch-panel (PhotoOverridePolicy) is the single place for
        // quality + wrong_subject copy in follow-up — no duplicate Livewire error bag.
        $this->clearPendingIdsFor($composite);
        if ($this->uploadPhaseComposite === $composite) {
            $this->clearUploadPhase();
        }
    }

    private function assessmentSoftTimedOut(string $composite): bool
    {
        $limit = PhotoAssessmentSoftTimeout::seconds();

        $startedAt = $this->uploadPhaseStartedAt;
        if ($startedAt !== null && $composite === $this->uploadPhaseComposite) {
            // Phase clock is authoritative while assessing is active for this composite.
            return (now()->getTimestamp() - (int) $startedAt) >= $limit;
        }

        // Fallback: oudste pending upload.created_at (not assessment_queued_at — that resets).
        foreach ($this->pendingIdsFor($composite) as $uploadId) {
            $createdAt = IntakeUpload::query()->whereKey($uploadId)->value('created_at');
            if ($createdAt !== null && now()->subSeconds($limit)->gte($createdAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Absolute ceiling for quiet background poll after soft-release (~10 min), per composite.
     * Uses created_at so pipeline resets of assessment_queued_at cannot stall forever.
     */
    private function assessmentQuietPollExhausted(string $composite): bool
    {
        $maxSeconds = 600;

        foreach ($this->pendingIdsFor($composite) as $uploadId) {
            $createdAt = IntakeUpload::query()->whereKey($uploadId)->value('created_at');
            if ($createdAt !== null && now()->subSeconds($maxSeconds)->gte($createdAt)) {
                return true;
            }
        }

        return false;
    }

    private function softReleasePendingAssessment(string $composite, string $message): void
    {
        // Keep pending ids so quiet wire:poll can pick up the terminal result
        // (staging intake 82: backend done at ~80s but UI stayed on Ontvangen).
        if (! in_array($composite, $this->assessmentUiReleased, true)) {
            $this->assessmentUiReleased[] = $composite;
        }

        // Clear busy UI only — do not clear pending ids.
        $this->uploadPhase = '';
        $this->uploadPhaseMessage = '';
        $this->uploadPhaseStartedAt = null;
        // Keep uploadPhaseComposite so quiet poll stays scoped.
        if ($this->uploadPhaseComposite === '') {
            $this->uploadPhaseComposite = $composite;
        }
        $this->saveMessage = $message;

        if (! $this->followUpMode) {
            $this->photoHint[$composite] = $message;
        }
    }

    private function setUploadPhase(string $phase, string $message): void
    {
        $this->uploadPhase = $phase;
        $this->uploadPhaseMessage = $message;

        if ($phase === 'assessing' && $this->uploadPhaseStartedAt === null) {
            $this->uploadPhaseStartedAt = now()->getTimestamp();
        }

        if ($phase !== 'assessing') {
            $this->uploadPhaseStartedAt = null;
        }
    }

    private function clearUploadPhase(): void
    {
        $this->uploadPhase = '';
        $this->uploadPhaseMessage = '';
        $this->uploadPhaseComposite = '';
        $this->uploadPhaseStartedAt = null;
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
     * Vangnet: usability_verdict mag nooit NULL blijven (voorkomt recovery-lus).
     *
     * @param  list<int>  $uploadIds
     */
    private function ensurePendingUploadsHaveUsabilityVerdict(array $uploadIds): void
    {
        foreach ($uploadIds as $uploadId) {
            $upload = IntakeUpload::query()
                ->where('intake_id', $this->intake()->id)
                ->whereKey($uploadId)
                ->first();

            if (! $upload instanceof IntakeUpload) {
                continue;
            }

            if ($upload->usability_verdict === null) {
                AssessPhotoUsability::persistFallbackVerdict($upload);
            }
        }
    }

    /**
     * @param  list<int>  $uploadIds
     */
    private function redispatchPendingAssessments(string $composite, ?array $uploadIds = null): void
    {
        $ids = $uploadIds ?? $this->pendingIdsFor($composite);
        $lifecycle = app(PhotoAssessmentLifecycle::class);

        foreach ($ids as $uploadId) {
            $upload = IntakeUpload::query()
                ->where('intake_id', $this->intake()->id)
                ->whereKey($uploadId)
                ->first();

            if (! $upload instanceof IntakeUpload) {
                continue;
            }

            // Forceer herbeoordeling: wis not_assessed zodat de job opnieuw mag draaien.
            $assessment = $upload->contentAssessment();
            if ($assessment instanceof PhotoContentAssessment && $assessment->needsReassessment()) {
                $upload->forceFill([
                    'content_assessment' => null,
                    'assessment_status' => PhotoAssessmentStatus::Pending,
                    'assessment_queued_at' => now(),
                ])->save();
            }

            if ($lifecycle->isTerminal($upload->fresh() ?? $upload)
                && ! ($upload->fresh()?->contentAssessment()?->needsReassessment() ?? false)) {
                continue;
            }

            $lifecycle->dispatch($upload->fresh() ?? $upload, $this->correlationIdForUpload($upload));
        }

        $this->setPendingIdsFor($composite, $ids);
        // Do NOT reset uploadPhaseStartedAt here — Alpine "Opnieuw beoordelen" /
        // quiet redispatch must not postpone the soft-timeout indefinitely
        // (demotest 8 okt taak 4).
    }

    /**
     * Na reload/timeout: uploads zonder usability of zonder AI-verdict opnieuw in de wachtrij.
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

            $item->unsetRelation('uploads');
            $item->load('uploads');
            $ids = $item->uploads
                ->filter(static function (IntakeUpload $upload): bool {
                    if ($upload->usability_verdict === null) {
                        return true;
                    }

                    $status = $upload->assessment_status;

                    // Alleen niet-terminale status (pending). not_assessed is klaar tot expliciete retry.
                    return ! ($status instanceof PhotoAssessmentStatus && $status->isTerminal());
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($ids === []) {
                return;
            }

            $composite = (string) $item->id;
            if (in_array($composite, $this->assessmentUiReleased, true) && ! $force) {
                return;
            }
            foreach ($ids as $uploadId) {
                $upload = IntakeUpload::query()->find($uploadId);
                if ($upload instanceof IntakeUpload && $upload->usability_verdict === null) {
                    app(AssessPhotoUsability::class)->handle(
                        $upload,
                        correlationId: $this->correlationIdForUpload($upload),
                    );
                }
            }
            $this->ensurePendingUploadsHaveUsabilityVerdict($ids);
            $this->redispatchPendingAssessments($composite, $ids);
            $this->beginAssessingFromPendingCreatedAt($composite, $ids);
            $this->queuedUnassessedRecovery = true;

            return;
        }

        $intake = $this->intake();
        /** @var Collection<int, IntakeUpload> $unassessed */
        $unassessed = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->whereNull('intake_follow_up_item_id')
            ->where('question_key', '!=', 'installer_evidence')
            ->orderBy('id')
            ->get()
            ->filter(function (IntakeUpload $upload): bool {
                if ($upload->usability_verdict === null) {
                    return true;
                }

                if ($this->photoAnalysisProfileName($upload->question_key) === null) {
                    // Geen AI: zorg dat status terminaal is.
                    if (! app(PhotoAssessmentLifecycle::class)->isTerminal($upload)) {
                        app(PhotoAssessmentLifecycle::class)->markAssessed($upload);
                    }

                    return false;
                }

                $status = $upload->assessment_status;

                // Alleen pending — not_assessed/heuristic_rejected/assessed/reused niet auto-herstellen.
                return ! ($status instanceof PhotoAssessmentStatus && $status->isTerminal());
            })
            ->values();

        if ($unassessed->isEmpty()) {
            return;
        }

        $step = $this->currentStep();
        $preferred = null;

        if ($step !== null) {
            $photoQuestion = $this->photoQuestionForStep($step);
            $preferredKey = $photoQuestion instanceof IntakeQuestion
                ? $photoQuestion->key
                : $step['question_key'];
            $preferredComposite = VisibilityResolver::compositeKey(
                $preferredKey,
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

        if (in_array($composite, $this->assessmentUiReleased, true) && ! $force) {
            return;
        }
        $ids = $group->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        foreach ($ids as $uploadId) {
            $upload = IntakeUpload::query()->find($uploadId);
            if ($upload instanceof IntakeUpload && $upload->usability_verdict === null) {
                app(AssessPhotoUsability::class)->handle(
                    $upload,
                    correlationId: $this->correlationIdForUpload($upload),
                );
            }
        }
        $this->ensurePendingUploadsHaveUsabilityVerdict($ids);
        $this->redispatchPendingAssessments($composite, $ids);
        $this->beginAssessingFromPendingCreatedAt($composite, $ids);
        $this->queuedUnassessedRecovery = true;
    }

    /**
     * Reload/recover: seed the UI soft-timeout clock from the oldest pending created_at
     * so a long-pending photo soft-releases immediately instead of restarting at now().
     *
     * @param  list<int>  $uploadIds
     */
    private function beginAssessingFromPendingCreatedAt(string $composite, array $uploadIds): void
    {
        $oldest = IntakeUpload::query()
            ->whereIn('id', $uploadIds)
            ->orderBy('created_at')
            ->value('created_at');

        $this->uploadPhaseComposite = $composite;
        $this->uploadPhaseStartedAt = $oldest !== null
            ? Carbon::parse($oldest)->getTimestamp()
            : null;
        $this->setUploadPhase('assessing', PhotoCustomerStatus::LOOKING);
    }

    /**
     * @param  list<string>  $previousTaskKeys
     * @param  list<int>  $sourceUploadIds
     * @param  array{
     *     percent: int,
     *     answered_required: int,
     *     total_required: int,
     *     missing_required: list<array{question_key: string, section_instance_key: string|null, label: string|null}>,
     *     task_keys: list<string>
     * }  $progressAfter
     */
    private function setProgressExtraNoteFromNewTasks(
        array $previousTaskKeys,
        array $progressAfter,
        array $sourceUploadIds = [],
    ): void {
        $newLabels = app(ProgressCalculator::class)->newTaskLabels($previousTaskKeys, $progressAfter);

        if ($newLabels === []) {
            $this->clearProgressExtraNote();

            return;
        }

        $this->progressExtraNote = count($newLabels) === 1
            ? 'Na je foto hebben we nog één vraag: '.$newLabels[0]
            : 'Na je foto hebben we nog een paar vragen: '.implode('; ', $newLabels);
        $this->progressExtraNoteUploadIds = array_values(array_unique(array_map(
            static fn (int $id): int => $id,
            $sourceUploadIds,
        )));
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
     * Request: opslaan + lokale usability + queue AI-job. Resultaat via pollPendingAssessments.
     *
     * @param  list<TemporaryUploadedFile>  $files
     */
    private function uploadPhotosForComposite(string $composite, array $files): void
    {
        $maxKb = (int) ceil(PhotoUploadLimits::hardMaxBytes() / 1024);
        [$questionKey, $instanceKey] = $this->splitComposite($composite);
        $intake = $this->intake();

        // Alleen deze composite resetten; pending van andere vragen blijft staan.
        $this->clearProgressExtraNote();
        $this->clearPhotoFeedbackForComposite($composite);
        $this->clearPendingIdsFor($composite);
        // New upload always gets a fresh soft-timeout clock (even when switching composite).
        $this->assessmentUiReleased = array_values(array_filter(
            $this->assessmentUiReleased,
            static fn (string $key): bool => $key !== $composite,
        ));
        $this->uploadPhase = '';
        $this->uploadPhaseMessage = '';
        $this->uploadPhaseStartedAt = null;
        $this->uploadPhaseComposite = $composite;

        $stored = 0;
        $duplicateNotice = false;
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<int> $storedUploadIds */
        $storedUploadIds = [];
        /** @var array<int, array<string, mixed>> $clientOriginals */
        $clientOriginals = is_array($this->photoClientOriginals[$composite] ?? null)
            ? $this->photoClientOriginals[$composite]
            : [];

        foreach ($files as $index => $file) {
            try {
                Validator::make(
                    ['photo' => $file],
                    ['photo' => ['required', 'file', 'max:'.$maxKb]],
                    [],
                    ['photo' => 'foto'],
                )->validate();

                $clientMeta = is_array($clientOriginals[$index] ?? null) ? $clientOriginals[$index] : [];
                $rawWidth = $clientMeta['width'] ?? null;
                $rawHeight = $clientMeta['height'] ?? null;
                $clientWidth = is_numeric($rawWidth) ? (int) $rawWidth : null;
                $clientHeight = is_numeric($rawHeight) ? (int) $rawHeight : null;

                $upload = app(StoreIntakeUpload::class)->handle(
                    $intake,
                    $questionKey,
                    $instanceKey,
                    $file,
                    clientOriginalWidth: $clientWidth,
                    clientOriginalHeight: $clientHeight,
                );

                if (! $upload->wasRecentlyCreated) {
                    $duplicateNotice = true;

                    if ($upload->usability_verdict === null
                        || ! app(PhotoAssessmentLifecycle::class)->isTerminal($upload)
                        || ($this->photoAnalysisProfileName($upload->question_key) !== null
                            && $upload->contentAssessment()?->needsReassessment())) {
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
        unset($this->photoClientOriginals[$composite]);
        $this->refreshAnswerInForm($composite);
        $this->showMissing = false;
        $this->resetErrorBag('photoFiles.'.$composite);

        if ($errors !== []) {
            $this->addError('photoFiles.'.$composite, implode(' ', array_values(array_unique($errors))));
        }

        $storedUploadIds = array_values(array_unique($storedUploadIds));

        if ($storedUploadIds !== []) {
            // BL-143: heavy decode/resize/usability/AI runs in ProcessIntakePhotoVariantsJob
            // (ai-photo). The Livewire update only persisted source bytes.
            $this->setPendingIdsFor($composite, $storedUploadIds);
            $this->setUploadPhase('assessing', PhotoCustomerStatus::LOOKING);
            $this->forgetIntakeDerivedCaches();
            // UX: no top "Foto opgeslagen" banner while assessment is still running.
            $this->saveMessage = $duplicateNotice && $stored === 0
                ? 'Deze foto staat er al'
                : '';

            // Sync queue: resolve terminal variants in this request (quality hints / clear phase).
            $this->pollPendingAssessments();

            return;
        }

        if ($stored > 0) {
            // Variants/AI may already be terminal on sync queue; still clear cleanly.
            $this->clearUploadPhase();
            $this->forgetIntakeDerivedCaches();
            $this->saveMessage = $stored === 1 ? 'Foto opgeslagen' : $stored." foto's opgeslagen";
        } elseif ($duplicateNotice) {
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

    private function applyPhotoDerivationResults(string $questionKey, ?string $instanceKey): void
    {
        $profileName = $this->photoAnalysisProfileName($questionKey);

        if ($profileName === null) {
            return;
        }

        if ($profileName === 'fusebox') {
            if ($instanceKey === null) {
                $this->applyFuseboxAssessment();
            }

            return;
        }

        $profile = PhotoDerivationProfile::find($profileName);

        if ($profile instanceof PhotoDerivationProfile) {
            $this->applyPhotoDerivation($instanceKey, $profile);
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
     * Soft continue: customer accepts a non-good photo and moves on.
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

        $question = $this->photoQuestionForStep($step);
        if (! $question instanceof IntakeQuestion) {
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

        $acceptedAny = PhotoOverridePolicy::acceptOverrides($uploads) > 0;

        if (! $acceptedAny) {
            return;
        }

        $composite = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
        $this->clearPhotoFeedbackForComposite($composite);
        $this->forgetIntakeDerivedCaches();
        $this->showMissing = false;
        $this->saveMessage = '';

        $this->next();
    }

    /**
     * Echte vervanging: verwijder uploads die een override nodig hebben en open de file picker.
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

        $question = $this->photoQuestionForStep($step);
        if (! $question instanceof IntakeQuestion) {
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

            if (! PhotoOverridePolicy::needsOverride($upload)) {
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
        $this->clearPhotoFeedbackForComposite($composite);
        $this->clearProgressExtraNote();
        $this->refreshAnswerInForm($composite);
        $this->showMissing = false;
        $this->saveMessage = '';

        $inputId = 'photo-input-'.str_replace(['.', ' '], '-', $composite);
        $this->js('document.getElementById('.json_encode($inputId).')?.click()');
    }

    /**
     * Vervang één probleemfoto (per-thumbnail knop) en open de file picker.
     */
    public function replaceSinglePhoto(int $uploadId): void
    {
        if ($this->completed) {
            return;
        }

        $upload = IntakeUpload::query()->find($uploadId);
        if (! $upload instanceof IntakeUpload || $upload->intake_id !== $this->intake()->id) {
            return;
        }

        if (! PhotoOverridePolicy::needsOverride($upload)) {
            return;
        }

        $questionKey = $upload->question_key;
        $instanceKey = $upload->section_instance_key;

        app(DeleteIntakeUpload::class)->handle($this->intake(), $upload);

        $this->invalidatePhotoDerivation($questionKey, $instanceKey);
        $this->runPhotoDerivation($questionKey, $instanceKey);
        $this->forgetIntakeDerivedCaches();

        $composite = VisibilityResolver::compositeKey($questionKey, $instanceKey);
        $this->clearPhotoFeedbackForComposite($composite);
        $this->clearProgressExtraNote();
        $this->refreshAnswerInForm($composite);
        $this->showMissing = false;
        $this->saveMessage = '';

        $inputId = 'photo-input-'.str_replace(['.', ' '], '-', $composite);
        $this->js('document.getElementById('.json_encode($inputId).')?.click()');
    }

    /**
     * Vervang één follow-up probleemfoto en open de file picker.
     */
    public function replaceFollowUpSinglePhoto(int $itemId, int $uploadId): void
    {
        if ($this->completed || ! $this->followUpMode) {
            return;
        }

        $item = $this->followUpItem($itemId);
        if ($item->type !== FollowUpItemType::Photo) {
            return;
        }

        $upload = $item->uploads->firstWhere('id', $uploadId);
        if (! $upload instanceof IntakeUpload) {
            return;
        }

        if (! PhotoOverridePolicy::needsOverride($upload)) {
            return;
        }

        app(DeleteFollowUpUpload::class)->handle($this->intake(), $item, $upload);

        $this->resetErrorBag('follow_up');
        $this->forgetIntakeDerivedCaches();
        $this->saveMessage = '';

        $inputId = 'follow-up-photo-input-'.$item->id;
        $this->js('document.getElementById('.json_encode($inputId).')?.click()');
    }

    /**
     * Foto-vraag van de huidige stap — ook binnen question_group (group_question_keys).
     *
     * @param  array{
     *     kind?: string,
     *     section_key: string,
     *     question_key: string,
     *     group_question_keys?: list<string>
     * }  $step
     */
    private function photoQuestionForStep(array $step): ?IntakeQuestion
    {
        $version = $this->version();

        if (($step['kind'] ?? 'question') === 'question_group') {
            foreach ($step['group_question_keys'] ?? [] as $groupKey) {
                $groupQuestion = app(IntakeStepBuilder::class)->questionForStep(
                    $version,
                    $step['section_key'],
                    $groupKey,
                );
                if ($groupQuestion instanceof IntakeQuestion && $groupQuestion->type === QuestionType::Photo) {
                    return $groupQuestion;
                }
            }

            return null;
        }

        $question = app(IntakeStepBuilder::class)->questionForStep(
            $version,
            $step['section_key'],
            $step['question_key'],
        );

        return $question instanceof IntakeQuestion && $question->type === QuestionType::Photo
            ? $question
            : null;
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

        $this->saveStep($this->currentStep());
    }

    /**
     * @param  array{
     *     key?: string,
     *     section_key: string,
     *     section_instance_key: string|null,
     *     question_key: string,
     *     kind?: 'question'|'known_summary'|'question_group'|'closing_wishes',
     *     group_question_keys?: list<string>,
     *     bundle_question_keys?: list<string>
     * }|null  $step
     */
    private function saveStep(?array $step): void
    {
        if ($step === null || ($step['kind'] ?? 'question') === 'known_summary') {
            return;
        }

        if (($step['kind'] ?? 'question') === 'closing_wishes') {
            $keys = $step['bundle_question_keys'] ?? IntakeStepBuilder::CLOSING_WISH_KEYS;
            foreach ($keys as $questionKey) {
                $composite = VisibilityResolver::compositeKey($questionKey, null);
                if (! isset($this->form[$composite]) || ! is_array($this->form[$composite])) {
                    continue;
                }
                $this->persistComposite($composite);
            }
            $this->saveMessage = 'Opgeslagen';

            return;
        }

        if (($step['kind'] ?? 'question') === 'question_group') {
            foreach ($step['group_question_keys'] ?? [] as $groupKey) {
                $groupQuestion = app(IntakeStepBuilder::class)->questionForStep(
                    $this->version(),
                    $step['section_key'],
                    $groupKey,
                );
                if (! $groupQuestion instanceof IntakeQuestion || $groupQuestion->type === QuestionType::Photo) {
                    continue;
                }
                $composite = VisibilityResolver::compositeKey($groupQuestion->key, $step['section_instance_key']);
                $this->persistComposite($composite);
            }
            $this->saveMessage = 'Opgeslagen';

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
     * Optionele vraag overslaan (foto of short_text met allow_skip), zonder verplichte inhoud.
     */
    public function skipOptionalPhoto(): void
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

        if (! $question instanceof IntakeQuestion) {
            return;
        }

        $allowSkip = ($question->meta['allow_skip'] ?? false) === true;
        if (! $allowSkip && $step['is_required']) {
            return;
        }

        if ($question->type === QuestionType::Photo) {
            // Optioneel: Volgende zonder foto; geen antwoord forceren.
            $this->showMissing = false;
            $this->completionMissing = [];
            $this->next();

            return;
        }

        if ($question->type === QuestionType::ShortText && $allowSkip) {
            $skipValue = $question->meta['skip_value'] ?? 'Laat installateur kiezen';
            if (! is_string($skipValue) || trim($skipValue) === '') {
                $skipValue = 'Laat installateur kiezen';
            }
            $composite = VisibilityResolver::compositeKey($question->key, $step['section_instance_key'] ?? null);
            $this->form[$composite] = array_merge($this->form[$composite] ?? [], [
                'text' => $skipValue,
            ]);
            $this->persistComposite($composite);
            $this->showMissing = false;
            $this->completionMissing = [];
            $this->saveMessage = 'Opgeslagen';
            $this->next();
        }
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

        $this->clearProgressExtraNote();

        $knownBeforeSave = $this->knownStepKeys;
        $stepsBeforeSave = $this->steps();
        $currentKey = $this->displayedStepKey($stepsBeforeSave);
        $currentStep = null;
        if (is_string($currentKey) && $currentKey !== '') {
            $beforeIndex = app(IntakeStepBuilder::class)->indexForStepKey($stepsBeforeSave, $currentKey);
            $currentStep = $beforeIndex === null ? null : $stepsBeforeSave[$beforeIndex];
        }
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

        if ($currentStep !== null) {
            $this->saveStep($currentStep);
        }

        // Recompute after save; keep navigating by the key we showed, not a shifted index.
        $steps = $this->steps();
        $currentIndex = is_string($currentKey)
            ? app(IntakeStepBuilder::class)->indexForStepKey($steps, $currentKey)
            : null;

        if ($currentIndex === null && is_string($currentKey) && $currentKey !== '') {
            // The displayed question left the list (e.g. room_name after fill). Land on
            // the successor without validating a different question under a stale index.
            if ($knownBeforeSave !== []) {
                $this->knownStepKeys = $knownBeforeSave;
            }
            $this->activeStepKey = $currentKey;
            $this->resolvedSteps = null;
            $this->resolvedStepsFormSignature = null;
            $this->realignToActiveStep();
            $this->showMissing = false;
            $this->completionMissing = [];
            $this->hydrateFormFromAnswers();
            $this->applyPrefillForActiveStep();
            $this->saveMessage = '';

            return;
        }

        if ($currentIndex !== null) {
            $this->stepIndex = $currentIndex;
            $this->activeStepKey = $currentKey ?? '';
        }

        $stepToValidate = $currentIndex !== null ? ($steps[$currentIndex] ?? null) : null;
        if (! $this->stepRequiredSatisfied($stepToValidate)) {
            $this->showMissing = true;
            $this->completionMissing = [];
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

        if ($this->stepIndex < count($steps) - 1) {
            $this->stepIndex = $this->stepIndex + 1;
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

        // Groepscherm (maten, afvoer+foto): keuze mag de optionele foto niet overslaan.
        if (($step['kind'] ?? 'question') === 'question_group') {
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
        if ($this->stepIndex <= 0 && $this->activeStepKey === '') {
            return;
        }

        $this->clearProgressExtraNote();

        $stepsBeforeSave = $this->steps();
        $currentKey = $this->displayedStepKey($stepsBeforeSave);
        $currentStep = null;
        if (is_string($currentKey) && $currentKey !== '') {
            $beforeIndex = app(IntakeStepBuilder::class)->indexForStepKey($stepsBeforeSave, $currentKey);
            $currentStep = $beforeIndex === null ? null : $stepsBeforeSave[$beforeIndex];
        }
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

        if ($currentStep !== null) {
            $this->saveStep($currentStep);
        }

        // Geforceerde known-edit: terug naar overzicht, niet naar de stap ervoor.
        if ($leavingForcedEdit) {
            $this->leaveForcedKnownEdit();
            $this->saveMessage = '';
            $this->showMissing = false;

            return;
        }

        $steps = $this->steps();
        $currentIndex = is_string($currentKey)
            ? app(IntakeStepBuilder::class)->indexForStepKey($steps, $currentKey)
            : null;

        if ($currentIndex === null && is_string($currentKey) && $currentKey !== '') {
            $this->activeStepKey = $currentKey;
            $this->realignToActiveStep();
            $this->hydrateFormFromAnswers();
            $this->applyPrefillForActiveStep();
            $this->saveMessage = '';
            $this->showMissing = false;

            return;
        }

        $currentIndex ??= $this->stepIndex;
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
     *     kind?: 'question'|'known_summary'|'question_group'|'closing_wishes',
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string,
     *     }>,
     *     group_key?: string,
     *     group_question_keys?: list<string>,
     *     bundle_question_keys?: list<string>
     * }>
     */
    private function steps(): array
    {
        $stickyStepKeys = $this->activeStepKey !== '' ? [$this->activeStepKey] : [];
        $signature = $this->liveAnswersSignature()
            .'|'.implode(',', $this->forceShowKnown)
            .'|'.implode(',', $stickyStepKeys);

        if ($this->resolvedSteps !== null && $this->resolvedStepsFormSignature === $signature) {
            return $this->resolvedSteps;
        }

        $steps = app(IntakeStepBuilder::class)->build(
            $this->intake(),
            $this->version(),
            $this->liveAnswers(),
            $this->forceShowKnown,
            $stickyStepKeys,
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
        $version = $this->version();
        $version->loadMissing('sections.questions');

        $mustAcceptKeys = [];
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if (MustAcceptQuestions::requiresAcceptance($question)) {
                    $mustAcceptKeys[$question->key] = true;
                }
            }
        }

        $form = [];
        $notices = [];

        foreach ($intake->answers as $answer) {
            $composite = VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key);
            $isPrefill = $answer->prefill_source !== null && $answer->prefill_source !== '';

            // Must-accept never enters the form from a prefill — customer must tick explicitly.
            if ($isPrefill && isset($mustAcceptKeys[$answer->question_key])) {
                continue;
            }

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

        if ($step === null
            || ($step['kind'] ?? 'question') === 'known_summary'
            || ($step['kind'] ?? 'question') === 'closing_wishes') {
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
        return $this->stepRequiredSatisfied($this->currentStep());
    }

    /**
     * Validate the step that is (or will be) displayed — never a drifted array index.
     *
     * @param  array{
     *     key?: string,
     *     section_key: string,
     *     section_instance_key: string|null,
     *     question_key: string,
     *     is_required?: bool,
     *     kind?: 'question'|'known_summary'|'question_group'|'closing_wishes',
     *     group_question_keys?: list<string>,
     *     bundle_question_keys?: list<string>
     * }|null  $step
     */
    private function stepRequiredSatisfied(?array $step): bool
    {
        if ($step === null || ($step['kind'] ?? 'question') === 'known_summary') {
            return true;
        }

        $kind = $step['kind'] ?? 'question';
        if ($kind === 'closing_wishes') {
            return true;
        }

        if ($kind === 'question_group') {
            if (! $step['is_required']) {
                return true;
            }

            foreach ($step['group_question_keys'] ?? [] as $groupKey) {
                $groupQuestion = app(IntakeStepBuilder::class)->questionForStep(
                    $this->version(),
                    $step['section_key'],
                    $groupKey,
                );
                if (! $groupQuestion instanceof IntakeQuestion) {
                    continue;
                }

                $visibility = $this->visibilityForQuestions(
                    collect([$groupQuestion]),
                    $step['section_instance_key'],
                );
                $key = VisibilityResolver::compositeKey($groupQuestion->key, $step['section_instance_key']);
                $state = $visibility[$key] ?? ['visible' => false, 'required' => false];
                if (! $state['visible'] || ! $state['required']) {
                    continue;
                }

                $reader = app(AnswerValueReader::class);
                $value = is_array($this->form[$key] ?? null) ? $this->form[$key] : null;
                if (! $reader->isFilled($value, $groupQuestion->type)) {
                    return false;
                }
            }

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

        // Photos: trust DB uploads, not possibly stale Livewire form upload_ids (reload used to "fix" this).
        if ($question->type === QuestionType::Photo) {
            return $this->photoStepContentSatisfied($question->key, $step['section_instance_key']);
        }

        $reader = app(AnswerValueReader::class);
        $value = is_array($this->form[$key] ?? null) ? $this->form[$key] : null;

        if (! $reader->isFilled($value, $question->type)) {
            return false;
        }

        if (MustAcceptQuestions::requiresAcceptance($question)) {
            return MustAcceptQuestions::isAccepted($value);
        }

        return true;
    }

    private function photoStepContentSatisfied(string $questionKey, ?string $sectionInstanceKey): bool
    {
        if ($questionKey === OutdoorPhotoReuse::TARGET_KEY
            && OutdoorPhotoReuse::aroundHouseSatisfiedByReuse($this->intake())) {
            return true;
        }

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
     *     kind?: 'question'|'known_summary'|'question_group'|'closing_wishes',
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string,
     *     }>,
     *     group_key?: string,
     *     group_question_keys?: list<string>,
     *     bundle_question_keys?: list<string>
     * }|null
     */
    private function currentStep(): ?array
    {
        return $this->resolveDisplayedStep($this->steps());
    }

    /**
     * Resolve the step the customer sees. Prefer stable {@see $activeStepKey}; sync index from it.
     * Never return a different question via a stale {@see $stepIndex} when the key is set but gone.
     *
     * @param  list<array{
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
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string,
     *     }>,
     *     group_key?: string,
     *     group_question_keys?: list<string>,
     *     bundle_question_keys?: list<string>
     * }>  $steps
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
     *     kind?: 'question'|'known_summary'|'question_group'|'closing_wishes',
     *     known_items?: list<array{
     *         question_key: string,
     *         section_instance_key: string|null,
     *         label: string,
     *         display_value: string,
     *         prefill_source: string,
     *     }>,
     *     group_key?: string,
     *     group_question_keys?: list<string>,
     *     bundle_question_keys?: list<string>
     * }|null
     */
    private function resolveDisplayedStep(array $steps): ?array
    {
        if ($steps === []) {
            $this->stepIndex = 0;
            $this->activeStepKey = '';

            return null;
        }

        if ($this->activeStepKey !== '') {
            $index = app(IntakeStepBuilder::class)->indexForStepKey($steps, $this->activeStepKey);
            if ($index !== null) {
                $this->stepIndex = $index;

                return $steps[$index];
            }

            // Key missing: do not fall back to a drifted index (that is a different question).
            return null;
        }

        $this->clampStepIndex($steps);
        $step = $steps[$this->stepIndex] ?? null;
        if ($step !== null) {
            $this->activeStepKey = $step['key'];
        }

        return $step;
    }

    /**
     * @param  list<array{key: string}>  $steps
     */
    private function displayedStepKey(array $steps): ?string
    {
        if ($this->activeStepKey !== '') {
            return $this->activeStepKey;
        }

        return $steps[$this->stepIndex]['key'] ?? null;
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
        $previousKey = $this->activeStepKey;
        $this->activeStepKey = $steps[$this->stepIndex]['key'] ?? '';

        // Sticky step keys are derived from activeStepKey; drop the cached list when it changes
        // so the previous question can leave the wizard after Volgende.
        if ($previousKey !== $this->activeStepKey) {
            $this->resolvedSteps = null;
            $this->resolvedStepsFormSignature = null;
        }
    }

    /**
     * render() leidt de getoonde stap af uit activeStepKey; een losse stepIndex
     * moet die sleutel dus meenemen, anders springt de wizard terug.
     */
    public function updatedStepIndex(mixed $value): void
    {
        $steps = $this->steps();
        $this->clampStepIndex($steps);
        $this->syncActiveStepKey($steps);
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

    private function clearProgressExtraNote(): void
    {
        $this->progressExtraNote = '';
        $this->progressExtraNoteUploadIds = [];
    }

    private function clearProgressExtraNoteIfRelatedToUpload(int $uploadId): void
    {
        if ($this->progressExtraNoteUploadIds === [] || in_array($uploadId, $this->progressExtraNoteUploadIds, true)) {
            $this->clearProgressExtraNote();
        }
    }

    private function clearPhotoFeedbackForComposite(string $composite): void
    {
        $this->photoHint[$composite] = null;
        $this->photoHintScope[$composite] = null;
    }

    /**
     * @param  list<int>  $uploadIds
     * @param  list<string>  $hints
     */
    private function storeScopedPhotoHint(string $composite, array $uploadIds, array $hints): void
    {
        $uploadIds = array_values(array_unique(array_map(
            static fn (int $id): int => $id,
            $uploadIds,
        )));

        if ($hints === [] || $uploadIds === []) {
            $this->clearPhotoFeedbackForComposite($composite);

            return;
        }

        $token = $this->analysisTokenForUploadIds($uploadIds);
        $this->photoHint[$composite] = implode(' ', array_values(array_unique($hints)));
        $this->photoHintScope[$composite] = [
            'upload_ids' => $uploadIds,
            'analysis_token' => $token,
        ];
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    private function scopedPhotoHintMessage(string $composite, Collection $uploads): ?string
    {
        $message = $this->photoHint[$composite] ?? null;
        $scope = $this->photoHintScope[$composite] ?? null;

        if (! is_string($message) || $message === '' || ! is_array($scope)) {
            return null;
        }

        $scopedIds = $scope['upload_ids'] ?? null;
        $scopedToken = $scope['analysis_token'] ?? null;

        if (! is_array($scopedIds) || ! is_string($scopedToken) || $scopedToken === '') {
            $this->clearPhotoFeedbackForComposite($composite);

            return null;
        }

        /** @var list<int> $activeIds */
        $activeIds = $uploads->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $scopedSorted = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $scopedIds,
        )));
        sort($scopedSorted);

        if ($activeIds !== $scopedSorted) {
            $this->clearPhotoFeedbackForComposite($composite);

            return null;
        }

        if ($this->analysisTokenForUploadIds($activeIds) !== $scopedToken) {
            $this->clearPhotoFeedbackForComposite($composite);

            return null;
        }

        return $message;
    }

    /**
     * Stable fingerprint of the active upload set + their analysis/usability outcome (BL-131).
     *
     * @param  list<int>  $uploadIds
     */
    private function analysisTokenForUploadIds(array $uploadIds): string
    {
        if ($uploadIds === []) {
            return '';
        }

        $uploads = IntakeUpload::query()
            ->where('intake_id', $this->intake()->id)
            ->whereIn('id', $uploadIds)
            ->orderBy('id')
            ->get();

        $parts = [];

        foreach ($uploads as $upload) {
            $timings = is_array($upload->processing_timings) ? $upload->processing_timings : [];
            $correlation = is_string($timings['correlation_id'] ?? null)
                ? (string) $timings['correlation_id']
                : '';
            $verdictEnum = $upload->usability_verdict;
            $verdict = $verdictEnum instanceof PhotoUsabilityVerdict
                ? $verdictEnum->value
                : '';
            $assessment = is_array($upload->content_assessment)
                ? json_encode($upload->content_assessment, JSON_THROW_ON_ERROR)
                : '';

            $parts[] = $upload->id.'|'.$correlation.'|'.$verdict.'|'.hash('xxh3', $assessment);
        }

        return hash('xxh3', implode(';', $parts));
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
        return app(AiTraceRequestIdResolver::class)->resolveCorrelationIdForUpload($upload);
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

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function countDoneSteps(array $steps): int
    {
        $done = 0;

        foreach ($steps as $index => $step) {
            if ($this->stepCountsAsDone($step, $index)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Customer-facing bar % follows the same step list as "Vraag X van Y" (BL-123):
     * done steps / total visible steps. Passed/skipped steps and answered steps
     * (including "Weet ik niet") count as done. 100% only after afronden.
     * High-water keeps the bar from dropping when the list grows mid-session.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolveStepProgressPercent(array $steps): int
    {
        if ($this->completed) {
            return 100;
        }

        $raw = $this->computeRawStepProgressPercent($steps);
        if ($raw > $this->progressHighWater) {
            $this->progressHighWater = $raw;
        }

        return $this->progressHighWater;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function computeRawStepProgressPercent(array $steps): int
    {
        $total = count($steps);

        if ($total === 0) {
            return 0;
        }

        $done = $this->countDoneSteps($steps);
        $percent = (int) round(($done / $total) * 100);

        // 100% is reserved for a completed customer part (afronden).
        return max(0, min(99, $percent));
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepCountsAsDone(array $step, int $index): bool
    {
        // Already left this step (answered or optional skip via Volgende).
        if ($index < $this->stepIndex) {
            return true;
        }

        if (($step['kind'] ?? 'question') === 'known_summary') {
            return false;
        }

        return $this->stepHasPersistedAnswer($step);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepHasPersistedAnswer(array $step): bool
    {
        $questionKey = (string) ($step['question_key'] ?? '');
        if ($questionKey === '' || $questionKey === '_known_summary') {
            return false;
        }

        $question = app(IntakeStepBuilder::class)->questionForStep(
            $this->version(),
            (string) ($step['section_key'] ?? ''),
            $questionKey,
        );

        if (! $question instanceof IntakeQuestion) {
            return false;
        }

        $instanceKey = $step['section_instance_key'] ?? null;
        $instanceKey = is_string($instanceKey) ? $instanceKey : null;
        $composite = VisibilityResolver::compositeKey($questionKey, $instanceKey);
        $intake = $this->intake();
        $intake->loadMissing(['answers', 'uploads']);

        if ($question->type === QuestionType::Photo) {
            // Foto telt pas mee na opgeslagen + geaccepteerde/goedgekeurde beoordeling.
            return PhotoContentSatisfaction::isSatisfied($intake, $questionKey, $instanceKey);
        }

        $answer = $intake->answers->first(
            static function ($row) use ($questionKey, $instanceKey): bool {
                if ($row->question_key !== $questionKey) {
                    return false;
                }

                return $instanceKey === null
                    ? $row->section_instance_key === null
                    : $row->section_instance_key === $instanceKey;
            },
        );

        $value = is_array($answer?->value) ? $answer->value : null;

        // Also accept an unsaved-but-hydrated form value for the active step.
        if ($value === null && isset($this->form[$composite]) && is_array($this->form[$composite])) {
            $value = $this->form[$composite];
        }

        return app(AnswerValueReader::class)->isFilled($value, $question->type);
    }

    private function temporaryUploadLooksLikePhoto(TemporaryUploadedFile $file): bool
    {
        $mime = strtolower((string) $file->getMimeType());
        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true);
    }

    private function persistFollowUpStepIndex(): void
    {
        if (! $this->followUpMode || $this->followUpRoundId <= 0) {
            return;
        }

        $round = $this->followUpRound();
        $item = $round->items->get($this->followUpStepIndex);
        if (! $item instanceof IntakeFollowUpItem) {
            return;
        }

        session([
            $this->followUpStepSessionKey($round->id) => $item->id,
        ]);
    }

    private function restoreFollowUpStepIndex(IntakeFollowUpRound $round): void
    {
        $savedItemId = session($this->followUpStepSessionKey($round->id));
        if (! is_numeric($savedItemId)) {
            return;
        }

        $index = $round->items->values()->search(
            static fn (IntakeFollowUpItem $item): bool => $item->id === (int) $savedItemId,
        );

        if ($index === false) {
            return;
        }

        $this->followUpStepIndex = (int) $index;
    }

    private function followUpStepSessionKey(int $roundId): string
    {
        return 'follow_up_active_item.'.$roundId;
    }
}
