<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\AnswerValueReader;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Enums\QuestionType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.customer')]
class IntakeWizard extends Component
{
    #[Locked]
    public int $intakeId;

    #[Locked]
    public string $token = '';

    public int $stepIndex = 0;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $saveMessage = '';

    public bool $showMissing = false;

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

        if ($step !== null) {
            $questions = app(IntakeStepBuilder::class)
                ->questionsForStep($version, $step['section_key']);

            $visibility = $this->visibilityForQuestions($questions, $step['section_instance_key']);
        }

        return view('livewire.customer.intake-wizard', [
            'intake' => $intake,
            'steps' => $steps,
            'step' => $step,
            'questions' => $questions,
            'visibility' => $visibility,
            'progressPercent' => $progress['percent'],
            'missingRequired' => $progress['missing_required'],
            'isLastStep' => $this->stepIndex >= count($steps) - 1,
        ]);
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

    public function saveCurrentStep(): void
    {
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

    public function next(): void
    {
        $this->saveCurrentStep();

        if (! $this->currentStepRequiredSatisfied()) {
            $this->showMissing = true;
            $this->saveMessage = '';

            return;
        }

        $this->showMissing = false;
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
            if ($question->type === QuestionType::Photo) {
                continue;
            }

            $key = VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
            $state = $visibility[$key] ?? ['visible' => false, 'required' => false];

            if (! $state['visible'] || ! $state['required']) {
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
