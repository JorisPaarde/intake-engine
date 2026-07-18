<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\DeleteIntakeUpload;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
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

    public string $saveMessage = '';

    public bool $showMissing = false;

    public bool $completed = false;

    /** @var list<array{question_key: string, section_instance_key: string|null, reason: string, label?: string}> */
    public array $completionMissing = [];

    public function mount(string $token): void
    {
        $this->token = $token;

        $intake = request()->attributes->get('customer_intake');

        if (! $intake instanceof Intake) {
            $intake = app(ResolveIntakeByAccessToken::class)->handle($token);
        }

        $this->intakeId = $intake->id;
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
        $version = $this->version();
        $steps = $this->steps();
        $this->clampStepIndex($steps);
        $step = $steps[$this->stepIndex] ?? null;
        $progress = app(ProgressCalculator::class)->calculate($intake, $version);

        $question = null;
        $visibility = [];
        $uploadsByQuestion = [];

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
            }
        }

        return view('livewire.customer.intake-wizard', [
            'intake' => $intake,
            'steps' => $steps,
            'step' => $step,
            'question' => $question,
            'visibility' => $visibility,
            'uploadsByQuestion' => $uploadsByQuestion,
            'progressPercent' => $this->completed ? 100 : $progress['percent'],
            'missingRequired' => $this->completionMissing !== []
                ? $this->completionMissing
                : $progress['missing_required'],
            'isLastStep' => $this->stepIndex >= count($steps) - 1,
            'maxUploadKb' => (int) config('intake.uploads.max_kilobytes', 5120),
        ]);
    }

    public function updatedPhotoFiles(mixed $value, ?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $files = $this->normalizePhotoFiles($this->photoFiles[$key] ?? $value);

        if ($files === []) {
            return;
        }

        $this->uploadPhotosForComposite($key, $files);
    }

    public function removePhoto(int $uploadId): void
    {
        $upload = IntakeUpload::query()->findOrFail($uploadId);

        try {
            app(DeleteIntakeUpload::class)->handle($this->intake(), $upload);
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
        if (! str_starts_with($property, 'form.')) {
            return;
        }

        $remainder = substr($property, strlen('form.'));

        if (preg_match('/^(.*)\.(text|value|number|bool)$/', $remainder, $matches) === 1) {
            unset($this->prefillNotice[$matches[1]]);
            $this->persistComposite($matches[1]);
            $this->saveMessage = 'Opgeslagen';
            $this->showMissing = false;
            $this->realignToActiveStep();

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

    /**
     * @return list<TemporaryUploadedFile>
     */
    private function normalizePhotoFiles(mixed $raw): array
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

        foreach ($files as $file) {
            try {
                Validator::make(
                    ['photo' => $file],
                    ['photo' => ['required', 'file', 'max:'.$maxKb]],
                    [],
                    ['photo' => 'foto'],
                )->validate();

                app(StoreIntakeUpload::class)->handle(
                    $this->intake(),
                    $questionKey,
                    $instanceKey,
                    $file,
                );
                $stored++;
            } catch (ValidationException $e) {
                $errors[] = $e->errors()['photo'][0]
                    ?? $e->errors()['photoFiles.'.$composite][0]
                    ?? 'Upload mislukt. Probeer het opnieuw.';
            }
        }

        // Alleen deze foto-composite verversen — volledige hydrate wist niet-opgeslagen velden.
        $this->photoFiles[$composite] = [];
        $this->refreshAnswerInForm($composite);
        $this->showMissing = false;
        $this->resetErrorBag('photoFiles.'.$composite);

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

    private function intake(): Intake
    {
        return Intake::query()->findOrFail($this->intakeId);
    }

    private function version(): IntakeTemplateVersion
    {
        return $this->intake()
            ->templateVersion()
            ->with(['sections.questions.options', 'sections.questions.rules'])
            ->firstOrFail();
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
        return app(IntakeStepBuilder::class)->build(
            $this->intake(),
            $this->version(),
            $this->liveAnswers(),
        );
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

            // BL-016: an installer pre-fill the applicant has not confirmed yet.
            if ($answer->prefill_source === 'installer') {
                $notices[$composite] = 'Alvast ingevuld door uw installateur — controleer en pas aan indien nodig';
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
