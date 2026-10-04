<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Services\AnswerValueReader;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Support\FactAcceptance;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveIntakeAnswer
{
    public function __construct(
        private readonly AnswerValueReader $answerValueReader,
        private readonly ProgressCalculator $progressCalculator,
        private readonly DossierManager $dossierManager,
    ) {}

    /**
     * @param  array<string, mixed>|null  $value
     * @param  string|null  $prefillSource  BL-016: 'installer' when the installer pre-fills at
     *                                      creation; null for a normal answer, which also clears
     *                                      any prior prefill flag (the applicant confirmed/edited).
     * @param  FactProvenance|string|null  $factProvenance  stated|inferred|unknown for AI fills
     * @param  int|string|float|null  $factConfidence  0–100 of high/medium/low / 0.0–1.0
     * @param  string|null  $factEvidence  korte quote of bewijsregel
     * @param  FactSource|string|null  $factSource  klantantwoord|foto|afgeleid
     */
    public function handle(
        Intake $intake,
        string $questionKey,
        ?string $sectionInstanceKey,
        ?array $value,
        ?string $prefillSource = null,
        FactProvenance|string|null $factProvenance = null,
        int|string|float|null $factConfidence = null,
        ?string $factEvidence = null,
        FactSource|string|null $factSource = null,
    ): IntakeAnswer {
        $question = $this->findQuestion($intake, $questionKey);

        if ($question->type === QuestionType::Photo) {
            throw ValidationException::withMessages([
                'value' => 'Foto-upload volgt in een latere stap.',
            ]);
        }

        $normalized = $this->normalizeValue($question->type, $value);
        $provenanceValue = $this->normalizeProvenance($factProvenance, $prefillSource);
        $confidenceValue = $this->normalizeConfidence($factConfidence, $prefillSource);
        $evidenceValue = $this->normalizeEvidence($factEvidence, $prefillSource);
        $sourceValue = $this->normalizeFactSource(
            $factSource,
            $prefillSource,
            FactProvenance::tryFromMixed($provenanceValue),
        );

        if ($question->is_required && ! $this->answerValueReader->isFilled($normalized, $question->type)) {
            // Allow clearing optional; required empty saves are rejected on "next", but autosave of empty optional is ok.
            // For required fields, still persist partial drafts if user typed then cleared — progress will reflect.
        }

        $answer = DB::transaction(function () use (
            $intake,
            $questionKey,
            $sectionInstanceKey,
            $normalized,
            $prefillSource,
            $provenanceValue,
            $confidenceValue,
            $evidenceValue,
            $sourceValue,
        ): IntakeAnswer {
            $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            $allowedStatuses = $prefillSource === null
                ? [IntakeStatus::Sent, IntakeStatus::InProgress]
                : [IntakeStatus::Draft, IntakeStatus::Sent, IntakeStatus::InProgress];

            if (! in_array($lockedIntake->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'value' => 'Deze opname kan niet meer worden gewijzigd.',
                ]);
            }

            $query = IntakeAnswer::query()
                ->where('intake_id', $intake->id)
                ->where('question_key', $questionKey);

            if ($sectionInstanceKey === null) {
                $query->whereNull('section_instance_key');
            } else {
                $query->where('section_instance_key', $sectionInstanceKey);
            }

            $payload = [
                'value' => $normalized,
                'prefill_source' => $prefillSource,
                'fact_provenance' => $provenanceValue,
                'fact_confidence' => $confidenceValue,
                'fact_evidence' => $evidenceValue,
                'fact_source' => $sourceValue,
                'answered_at' => now(),
            ];

            $answer = $query->first();

            if ($answer === null) {
                $answer = IntakeAnswer::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => $questionKey,
                    'section_instance_key' => $sectionInstanceKey,
                ] + $payload);
            } else {
                $answer->update($payload);
            }

            // An installer prefill at creation must not "start" the intake for the customer.
            $this->touchProgress($intake, $prefillSource === null);

            if ($prefillSource === null) {
                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'customer',
                    'actor_id' => null,
                    'event' => 'answer_saved',
                    'properties' => [
                        'question_key' => $questionKey,
                        'section_instance_key' => $sectionInstanceKey,
                    ],
                    'created_at' => now(),
                ]);
            }

            return $answer;
        });

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        if (in_array($questionKey, ['room_length_m', 'room_width_m'], true)) {
            app(PrefillAnswersFromKnownContext::class)
                ->recalculateDerivedDimensions($intake->fresh() ?? $intake);
        }

        return $answer;
    }

    private function normalizeProvenance(
        FactProvenance|string|null $factProvenance,
        ?string $prefillSource,
    ): ?string {
        if ($prefillSource === null) {
            // Klantbevestiging / eigen invoer = stated.
            return FactProvenance::Stated->value;
        }

        if ($factProvenance instanceof FactProvenance) {
            return $factProvenance->value;
        }

        return FactProvenance::tryFromMixed($factProvenance)?->value;
    }

    private function normalizeConfidence(int|string|float|null $factConfidence, ?string $prefillSource): ?int
    {
        if ($prefillSource === null) {
            return 100;
        }

        $normalized = FactAcceptance::normalizeConfidence($factConfidence);
        if ($normalized !== null) {
            return $normalized;
        }

        if (PrefillSources::isSuggestion($prefillSource)) {
            return FactAcceptance::LEVEL_MEDIUM;
        }

        if (PrefillSources::isStrongAi($prefillSource)
            || $prefillSource === PrefillSources::REQUEST_TEXT
            || $prefillSource === PrefillSources::DERIVED_LXW
            || $prefillSource === 'installer') {
            return FactAcceptance::LEVEL_HIGH;
        }

        return null;
    }

    private function normalizeEvidence(?string $factEvidence, ?string $prefillSource): ?string
    {
        if ($prefillSource === null) {
            return null;
        }

        if (! is_string($factEvidence)) {
            return null;
        }

        $trimmed = trim($factEvidence);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 500);
    }

    private function normalizeFactSource(
        FactSource|string|null $factSource,
        ?string $prefillSource,
        ?FactProvenance $provenance,
    ): string {
        if ($prefillSource === null) {
            return FactSource::CustomerAnswer->value;
        }

        if ($factSource instanceof FactSource) {
            return $factSource->value;
        }

        if (is_string($factSource)) {
            $explicit = FactSource::tryFrom(strtolower(trim($factSource)));
            if ($explicit instanceof FactSource) {
                return $explicit->value;
            }
        }

        return FactAcceptance::sourceFrom($prefillSource, $provenance)->value;
    }

    private function touchProgress(Intake $intake, bool $allowStatusStart = true): void
    {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
        $progress = $this->progressCalculator->calculate($intake, $version);

        $updates = [
            'progress_percent' => $progress['percent'],
        ];

        if ($allowStatusStart && $intake->status === IntakeStatus::Sent) {
            $updates['status'] = IntakeStatus::InProgress;
            $updates['started_at'] = $intake->started_at ?? now();
        }

        if ($allowStatusStart && $intake->status === IntakeStatus::InProgress && $intake->started_at === null) {
            $updates['started_at'] = now();
        }

        $intake->update($updates);
    }

    private function findQuestion(Intake $intake, string $questionKey): IntakeQuestion
    {
        $intake->loadMissing(['templateVersion.sections.questions']);

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $question;
                }
            }
        }

        throw ValidationException::withMessages([
            'question_key' => 'Onbekende vraag.',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>
     */
    private function normalizeValue(QuestionType $type, ?array $value): array
    {
        $value ??= [];

        return match ($type) {
            QuestionType::ShortText, QuestionType::LongText => [
                'text' => isset($value['text']) ? trim((string) $value['text']) : '',
            ],
            QuestionType::Number => [
                'number' => $this->normalizeNumber($value['number'] ?? null),
            ],
            QuestionType::SingleChoice => [
                'value' => isset($value['value']) ? (string) $value['value'] : '',
            ],
            QuestionType::MultiChoice => [
                'values' => array_values(array_map('strval', is_array($value['values'] ?? null) ? $value['values'] : [])),
            ],
            QuestionType::Boolean => [
                'bool' => array_key_exists('bool', $value)
                    ? filter_var($value['bool'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
            ],
            QuestionType::Photo => [
                'upload_ids' => [],
            ],
        };
    }

    private function normalizeNumber(mixed $number): int|float|null
    {
        if ($number === null || $number === '') {
            return null;
        }

        if (! is_numeric($number)) {
            return null;
        }

        return str_contains((string) $number, '.') ? (float) $number : (int) $number;
    }
}
