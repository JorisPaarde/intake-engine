<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Services\RequestPrefillContextBuilder;
use App\Domains\AI\Services\TemplateQuestionCatalogBuilder;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vult templatevragen vanuit bekende context via AI die de volledige vraagenset kent (ADR-0013).
 */
final class PrefillAnswersFromKnownContext
{
    public const SOURCE_DERIVED = 'ai';

    public const SOURCE_SUGGESTED = 'ai_suggestion';

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly TemplateQuestionCatalogBuilder $catalogBuilder,
        private readonly RequestPrefillContextBuilder $contextBuilder,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
    ) {}

    public function handle(Intake $intake): ?AiRun
    {
        if (! in_array($intake->status, [
            IntakeStatus::Draft,
            IntakeStatus::Sent,
            IntakeStatus::InProgress,
        ], true)) {
            return null;
        }

        if (! (bool) config('ai.text_inference.enabled', false)) {
            return null;
        }

        $catalog = $this->catalogBuilder->build($intake);
        $context = $this->contextBuilder->build($intake);
        $reason = $context['request_reason'];

        if (! is_string($reason) || mb_strlen(trim($reason)) < 10) {
            return null;
        }

        $promptName = (string) config('ai.request_prefill_prompt', 'request_prefill');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);

        $input = [
            'task' => 'prefill_from_known_context',
            'known_context' => $context,
            'question_catalog' => $catalog,
        ];
        $inputHash = hash('sha256', (string) json_encode([
            'prompt_version' => $promptVersion,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));

        $existing = AiRun::query()
            ->where('intake_id', $intake->id)
            ->where('type', AiRunType::RequestIntent)
            ->where('input_hash', $inputHash)
            ->where('status', AiRunStatus::Succeeded)
            ->latest('id')
            ->first();

        if ($existing instanceof AiRun) {
            return $existing;
        }

        $run = AiRun::query()->create([
            'intake_id' => $intake->id,
            'type' => AiRunType::RequestIntent,
            'provider' => (string) config('ai.provider', 'null'),
            'model' => null,
            'prompt_version' => $promptVersion,
            'input_hash' => $inputHash,
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
            );

            $output = $this->validateOutput($result->output, $catalog);
            $applied = $this->apply($intake, $output);

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output + ['applied_question_keys' => $applied],
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'request_prefill_derived',
                'properties' => [
                    'ai_run_id' => $run->id,
                    'provider' => $run->provider,
                    'prompt_version' => $promptVersion,
                    'question_keys' => $applied,
                ],
                'created_at' => now(),
            ]);

            return $run;
        } catch (Throwable $exception) {
            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            return $run->fresh() ?? $run;
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function validateOutput(array $output, array $catalog): array
    {
        $validator = Validator::make($output, [
            'evidence' => ['required', 'string', 'min:3', 'max:500'],
            'fills' => ['present', 'array', 'max:40'],
            'fills.*.question_key' => ['required', 'string', 'max:120'],
            'fills.*.section_instance_key' => ['nullable', 'string', 'max:80'],
            'fills.*.confidence' => ['required', Rule::in(['high', 'medium', 'low'])],
            'fills.*.value' => ['required', 'array'],
            'fills.*.evidence' => ['nullable', 'string', 'max:300'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{evidence: string, fills: list<array<string, mixed>>} $validated */
        $validated = $validator->validated();
        $index = $this->catalogIndex($catalog);
        $fills = [];

        foreach ($validated['fills'] as $fill) {
            $key = (string) $fill['question_key'];
            $question = $index[$key] ?? null;

            if ($question === null) {
                continue;
            }

            if ($question['type'] === QuestionType::Photo->value) {
                continue;
            }

            $normalized = $this->normalizeValue($question, $fill['value']);

            if ($normalized === null) {
                continue;
            }

            $instanceKey = $fill['section_instance_key'] ?? null;
            if (! $question['is_repeatable'] && $instanceKey !== null) {
                continue;
            }

            if ($question['is_repeatable'] && ($instanceKey === null || $instanceKey === '')) {
                continue;
            }

            $fills[] = [
                'question_key' => $key,
                'section_instance_key' => is_string($instanceKey) ? $instanceKey : null,
                'confidence' => $fill['confidence'],
                'value' => $normalized,
                'evidence' => $fill['evidence'] ?? null,
            ];
        }

        return [
            'evidence' => $validated['evidence'],
            'fills' => $fills,
        ];
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, array{type: string, options: list<string>, is_repeatable: bool}>
     */
    private function catalogIndex(array $catalog): array
    {
        $index = [];

        foreach ($catalog['sections'] as $section) {
            $repeatable = (bool) ($section['is_repeatable'] ?? false);

            foreach ($section['questions'] as $question) {
                $options = [];
                foreach ($question['options'] ?? [] as $option) {
                    if (is_string($option['value'] ?? null)) {
                        $options[] = $option['value'];
                    }
                }

                $index[$question['key']] = [
                    'type' => (string) $question['type'],
                    'options' => $options,
                    'is_repeatable' => $repeatable,
                ];
            }
        }

        return $index;
    }

    /**
     * @param  array{type: string, options: list<string>, is_repeatable: bool}  $question
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function normalizeValue(array $question, array $value): ?array
    {
        return match ($question['type']) {
            QuestionType::SingleChoice->value => $this->normalizeChoice($question['options'], $value),
            QuestionType::MultiChoice->value => $this->normalizeMultiChoice($question['options'], $value),
            QuestionType::Number->value => isset($value['number']) && is_numeric($value['number'])
                ? ['number' => (str_contains((string) $value['number'], '.')
                    ? (float) $value['number']
                    : (int) $value['number'])]
                : null,
            QuestionType::ShortText->value, QuestionType::LongText->value => isset($value['text']) && is_string($value['text']) && trim($value['text']) !== ''
                ? ['text' => trim($value['text'])]
                : null,
            QuestionType::Boolean->value => array_key_exists('bool', $value) && is_bool($value['bool'])
                ? ['bool' => $value['bool']]
                : null,
            default => null,
        };
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, mixed>  $value
     * @return array{value: string}|null
     */
    private function normalizeChoice(array $options, array $value): ?array
    {
        $choice = $value['value'] ?? null;

        if (! is_string($choice) || ! in_array($choice, $options, true)) {
            return null;
        }

        return ['value' => $choice];
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, mixed>  $value
     * @return array{values: list<string>}|null
     */
    private function normalizeMultiChoice(array $options, array $value): ?array
    {
        $values = $value['values'] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $normalized = [];
        foreach ($values as $item) {
            if (! is_string($item) || ! in_array($item, $options, true)) {
                return null;
            }
            $normalized[] = $item;
        }

        return ['values' => array_values(array_unique($normalized))];
    }

    /**
     * @param  array{evidence: string, fills: list<array<string, mixed>>}  $output
     * @return list<string>
     */
    private function apply(Intake $intake, array $output): array
    {
        $applied = [];

        foreach ($output['fills'] as $fill) {
            $confidence = (string) $fill['confidence'];

            if ($confidence === 'low') {
                continue;
            }

            $questionKey = (string) $fill['question_key'];
            $instanceKey = $fill['section_instance_key'] ?? null;
            $instanceKey = is_string($instanceKey) ? $instanceKey : null;

            if (! $this->mayWrite($intake, $questionKey, $instanceKey)) {
                continue;
            }

            $source = $confidence === 'high' ? self::SOURCE_DERIVED : self::SOURCE_SUGGESTED;
            $this->saveIntakeAnswer->handle(
                $intake,
                $questionKey,
                $instanceKey,
                $fill['value'],
                $source,
            );

            $applied[] = $instanceKey === null ? $questionKey : $questionKey.'@'.$instanceKey;
        }

        return $applied;
    }

    private function mayWrite(Intake $intake, string $questionKey, ?string $sectionInstanceKey): bool
    {
        $existing = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey)
            ->when(
                $sectionInstanceKey === null,
                static fn ($query) => $query->whereNull('section_instance_key'),
                static fn ($query) => $query->where('section_instance_key', $sectionInstanceKey),
            )
            ->first();

        if (! $existing instanceof IntakeAnswer) {
            return true;
        }

        return in_array($existing->prefill_source, [
            self::SOURCE_DERIVED,
            self::SOURCE_SUGGESTED,
            DeriveIntentFromRequest::SOURCE_REQUEST_TEXT,
        ], true);
    }
}
