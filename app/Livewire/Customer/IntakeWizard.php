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
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Enums\QuestionType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, TemporaryUploadedFile|null> */
    public array $photoFiles = [];

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
        $this->stepIndex = app(IntakeStepBuilder::class)->indexForSectionKey(
            $steps,
            $intake->current_section_key,
        );
        $this->stepIndex = min($this->stepIndex, max(0, count($steps) - 1));
    }

    public function render(): View
    {
        $intake = $this->intake();
        $version = $this->version();
        $steps = $this->steps();
        $step = $steps[$this->stepIndex] ?? null;
        $progress = app(ProgressCalculator::class)->calculate($intake, $version);

        $questions = collect();
        $visibility = [];
        $uploadsByQuestion = [];

        if ($step !== null && ! $this->completed) {
            $questions = app(IntakeStepBuilder::class)
                ->questionsForStep($version, $step['section_key']);

            $visibility = $this->visibilityForQuestions($questions, $step['section_instance_key']);
            $uploadsByQuestion = $this->uploadsForStep($step['section_instance_key']);
        }

        return view('livewire.customer.intake-wizard', [
            'intake' => $intake,
            'steps' => $steps,
            'step' => $step,
            'questions' => $questions,
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
        if ($key === null || $key === '' || ! $value instanceof TemporaryUploadedFile) {
            return;
        }

        $this->uploadPhotoForComposite($key);
    }

    public function removePhoto(int $uploadId): void
    {
        $upload = IntakeUpload::query()->findOrFail($uploadId);

        try {
            app(DeleteIntakeUpload::class)->handle($this->intake(), $upload);
            $this->hydrateFormFromAnswers();
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
            $this->persistComposite($matches[1]);
            $this->saveMessage = 'Opgeslagen';
            $this->showMissing = false;

            return;
        }

        if (preg_match('/^(.*)\.values(?:\.\d+)?$/', $remainder, $matches) === 1) {
            $this->persistComposite($matches[1]);
            $this->saveMessage = 'Opgeslagen';
            $this->showMissing = false;
        }
    }

    private function uploadPhotoForComposite(string $composite): void
    {
        $maxKb = (int) config('intake.uploads.max_kilobytes', 5120);

        $this->validate([
            'photoFiles.'.$composite => [
                'required',
                'image',
                'max:'.$maxKb,
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ], [], [
            'photoFiles.'.$composite => 'foto',
        ]);

        $file = $this->photoFiles[$composite] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        [$questionKey, $instanceKey] = $this->splitComposite($composite);

        try {
            app(StoreIntakeUpload::class)->handle(
                $this->intake(),
                $questionKey,
                $instanceKey,
                $file,
            );
            $this->photoFiles[$composite] = null;
            $this->hydrateFormFromAnswers();
            $this->saveMessage = 'Foto opgeslagen';
            $this->showMissing = false;
            $this->resetErrorBag('photoFiles.'.$composite);
        } catch (ValidationException $e) {
            $this->photoFiles[$composite] = null;
            throw $e;
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

        $step = $this->steps()[$this->stepIndex] ?? null;
        if ($step === null) {
            return;
        }

        foreach ($this->visibleQuestionsOnStep($step) as $question) {
            if ($question->type === QuestionType::Photo) {
                continue;
            }

            $composite = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
            $this->persistComposite($composite);
        }

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

        $this->saveCurrentStep();

        if (! $this->currentStepRequiredSatisfied()) {
            $this->showMissing = true;
            $this->saveMessage = '';

            return;
        }

        $this->showMissing = false;
        $this->completionMissing = [];
        $steps = $this->steps();

        if ($this->stepIndex < count($steps) - 1) {
            $this->stepIndex++;
            $this->rememberCurrentSection();
            $this->hydrateFormFromAnswers();
            $this->saveMessage = '';
        }
    }

    public function previous(): void
    {
        if ($this->stepIndex > 0) {
            $this->saveCurrentStep();
            $this->stepIndex--;
            $this->rememberCurrentSection();
            $this->hydrateFormFromAnswers();
            $this->saveMessage = '';
            $this->showMissing = false;
        }
    }

    public function goToStep(int $index): void
    {
        $steps = $this->steps();
        if ($index < 0 || $index >= count($steps)) {
            return;
        }

        $this->saveCurrentStep();
        $this->stepIndex = $index;
        $this->rememberCurrentSection();
        $this->hydrateFormFromAnswers();
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
     * @return list<array{key: string, section_key: string, section_instance_key: string|null, title: string, description: string|null, is_repeatable: bool}>
     */
    private function steps(): array
    {
        return app(IntakeStepBuilder::class)->build($this->intake(), $this->version());
    }

    private function hydrateFormFromAnswers(): void
    {
        $intake = $this->intake();
        $intake->loadMissing('answers');
        $form = [];

        foreach ($intake->answers as $answer) {
            $composite = VisibilityResolver::compositeKey($answer->question_key, $answer->section_instance_key);
            $form[$composite] = $answer->value ?? [];
        }

        $this->form = $form;
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
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $sectionsByQuestionKey[$question->key] = $section;
                $question->setRelation('section', $section);
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
            $questions,
            $answers,
            $questionTypes,
            $sectionsByQuestionKey,
            $targets,
        );
    }

    /**
     * @param  array{section_key: string, section_instance_key: string|null}  $step
     * @return Collection<int, IntakeQuestion>
     */
    private function visibleQuestionsOnStep(array $step): Collection
    {
        $questions = app(IntakeStepBuilder::class)
            ->questionsForStep($this->version(), $step['section_key']);

        $visibility = $this->visibilityForQuestions($questions, $step['section_instance_key']);

        return $questions->filter(function (IntakeQuestion $question) use ($visibility, $step): bool {
            $key = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);

            return ($visibility[$key]['visible'] ?? false) === true;
        })->values();
    }

    private function currentStepRequiredSatisfied(): bool
    {
        $step = $this->steps()[$this->stepIndex] ?? null;
        if ($step === null) {
            return true;
        }

        $questions = app(IntakeStepBuilder::class)
            ->questionsForStep($this->version(), $step['section_key']);

        $visibility = $this->visibilityForQuestions($questions, $step['section_instance_key']);
        $reader = app(AnswerValueReader::class);

        foreach ($questions as $question) {
            $key = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
            $state = $visibility[$key] ?? ['visible' => false, 'required' => false];

            if (! $state['visible'] || ! $state['required']) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                $value = is_array($this->form[$key] ?? null) ? $this->form[$key] : null;
                if (! $reader->isFilled($value, QuestionType::Photo)) {
                    return false;
                }

                continue;
            }

            $value = is_array($this->form[$key] ?? null) ? $this->form[$key] : null;

            if (! $reader->isFilled($value, $question->type)) {
                return false;
            }
        }

        return true;
    }

    private function rememberCurrentSection(): void
    {
        $step = $this->steps()[$this->stepIndex] ?? null;
        if ($step === null) {
            return;
        }

        $this->intake()->update([
            'current_section_key' => $step['section_key'],
        ]);
    }
}
