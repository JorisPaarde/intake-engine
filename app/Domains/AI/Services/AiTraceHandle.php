<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Models\AiTraceStep;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiTraceStatus;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mutable handle for one AI correlation trace.
 *
 * Other feature streams should attach work via {@see self::step()}:
 *
 *   $trace->step('normalize', ['defaults' => [...]], durationMs: 12);
 *
 * Trace failures are reported and swallowed — they never break the business flow.
 * Use {@see self::beginBuffer()}/{@see self::flushBuffer()} around dossier transactions.
 */
final class AiTraceHandle
{
    private int $sequence = 0;

    private float $processStartedAt;

    private bool $buffering = false;

    /** @var array<string, mixed> */
    private array $pendingAttributes = [];

    /**
     * @var list<array{step_key: string, payload: array<string, mixed>|null, duration_ms: int|null}>
     */
    private array $pendingSteps = [];

    public function __construct(
        private AiTrace $trace,
        private readonly AiTraceRedactor $redactor,
        private readonly bool $noop = false,
    ) {
        $this->processStartedAt = microtime(true);
        if (! $this->noop && $this->trace->exists) {
            $this->sequence = (int) ($this->trace->steps()->max('sequence') ?? 0);
        }
    }

    public static function disabled(AiTrace $placeholder, AiTraceRedactor $redactor): self
    {
        return new self($placeholder, $redactor, noop: true);
    }

    public function traceId(): string
    {
        return (string) ($this->trace->trace_id ?? '');
    }

    public function model(): AiTrace
    {
        return $this->trace;
    }

    public function isNoop(): bool
    {
        return $this->noop;
    }

    /**
     * Collect writes in memory; flush after the dossier transaction commits.
     */
    public function beginBuffer(): self
    {
        $this->buffering = true;

        return $this;
    }

    public function flushBuffer(): self
    {
        if ($this->noop) {
            $this->buffering = false;
            $this->pendingAttributes = [];
            $this->pendingSteps = [];

            return $this;
        }

        $this->buffering = false;

        try {
            if ($this->pendingAttributes !== []) {
                $this->trace->fill($this->pendingAttributes);
                $this->trace->save();
                $this->pendingAttributes = [];
            }

            foreach ($this->pendingSteps as $step) {
                $this->sequence++;
                AiTraceStep::query()->create([
                    'ai_trace_id' => $this->trace->id,
                    'step_key' => $step['step_key'],
                    'sequence' => $this->sequence,
                    'payload' => $step['payload'],
                    'duration_ms' => $step['duration_ms'],
                    'recorded_at' => now(),
                ]);
            }
            $this->pendingSteps = [];
        } catch (Throwable $exception) {
            report($exception);
            $this->pendingAttributes = [];
            $this->pendingSteps = [];
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function step(string $key, array $payload = [], ?int $durationMs = null): self
    {
        return $this->safe(function () use ($key, $payload, $durationMs): void {
            $row = [
                'step_key' => Str::limit($key, 80, ''),
                'payload' => $payload === [] ? null : $this->redactor->redact($payload),
                'duration_ms' => $durationMs,
            ];

            if ($this->buffering) {
                $this->pendingSteps[] = $row;

                return;
            }

            $this->sequence++;
            AiTraceStep::query()->create([
                'ai_trace_id' => $this->trace->id,
                'step_key' => $row['step_key'],
                'sequence' => $this->sequence,
                'payload' => $row['payload'],
                'duration_ms' => $row['duration_ms'],
                'recorded_at' => now(),
            ]);
        });
    }

    public function linkUpload(?IntakeUpload $upload): self
    {
        if ($upload === null) {
            return $this;
        }

        return $this->safe(function () use ($upload): void {
            $timings = $upload->processing_timings ?? [];
            $persistMs = array_key_exists('persist_ms', $timings)
                ? $this->intOrNull($timings['persist_ms'])
                : (array_key_exists('upload_ms', $timings) ? $this->intOrNull($timings['upload_ms']) : $this->trace->persist_ms);
            $preprocessMs = array_key_exists('preprocess_ms', $timings)
                ? $this->intOrNull($timings['preprocess_ms'])
                : $this->trace->preprocess_ms;
            $networkMs = array_key_exists('network_upload_ms', $timings)
                ? $this->intOrNull($timings['network_upload_ms'])
                : $this->trace->network_upload_ms;

            $this->assign([
                'upload_id' => $upload->id,
                'persist_ms' => $persistMs,
                'preprocess_ms' => $preprocessMs,
                'network_upload_ms' => $networkMs,
                'subject_type' => $this->trace->subject_type ?? 'question',
                'subject_id' => $this->trace->subject_id ?? $upload->question_key.($upload->section_instance_key ? '|'.$upload->section_instance_key : ''),
            ]);

            $this->step('upload', [
                'upload_id' => $upload->id,
                'question_key' => $upload->question_key,
                'section_instance_key' => $upload->section_instance_key,
                'mime_type' => $upload->mime_type,
                'size_bytes' => $upload->size_bytes,
                'analysis_size_bytes' => $upload->analysis_size_bytes,
                'checksum' => $upload->checksum,
                'analysis_checksum' => $upload->analysis_checksum,
                'sort_order' => $upload->sort_order,
                'path_ref' => 'intake_upload:'.$upload->id,
                'timings' => $timings,
            ], durationMs: $persistMs);

            if ($preprocessMs !== null) {
                $this->step('preprocess', [
                    'upload_id' => $upload->id,
                    'analysis_path_ref' => 'intake_upload_analysis:'.$upload->id,
                ], durationMs: $preprocessMs);
            }

            if ($networkMs !== null) {
                $this->step('network_upload', [
                    'upload_id' => $upload->id,
                ], durationMs: $networkMs);
            }
        });
    }

    public function linkAiRun(?AiRun $run): self
    {
        if ($run === null) {
            return $this;
        }

        return $this->safe(function () use ($run): void {
            $this->assign([
                'ai_run_id' => $run->id,
                'provider' => $run->provider ?: $this->trace->provider,
                'model' => $run->model ?? $this->trace->model,
                'prompt_version' => $run->prompt_version ?: $this->trace->prompt_version,
            ]);
            $this->step('ai_run', ['ai_run_id' => $run->id, 'type' => $run->type->value]);
        });
    }

    /**
     * @param  array<string, mixed>  $systemAndUser
     * @param  list<array<string, mixed>>  $photoRefs
     * @param  array<string, mixed>  $modelParameters
     */
    public function recordRequest(
        array $systemAndUser,
        array $photoRefs = [],
        ?string $promptVersion = null,
        ?string $schemaVersion = null,
        array $modelParameters = [],
        bool $fallbackUsed = false,
        int $retryCount = 0,
    ): self {
        return $this->safe(function () use ($systemAndUser, $photoRefs, $promptVersion, $schemaVersion, $modelParameters, $fallbackUsed, $retryCount): void {
            $safeParams = $modelParameters;
            unset($safeParams['base_url']);

            $this->assign([
                'request_snapshot' => $this->redactor->redact($systemAndUser),
                'photo_refs' => $photoRefs === [] ? null : ($this->redactor->redact(['refs' => $photoRefs])['refs'] ?? $photoRefs),
                'prompt_version' => $promptVersion ?? $this->trace->prompt_version,
                'schema_version' => $schemaVersion ?? $this->trace->schema_version,
                'model_parameters' => $safeParams === [] ? $this->trace->model_parameters : $safeParams,
                'fallback_used' => $fallbackUsed,
                'retry_count' => max(0, $retryCount),
            ]);
            $this->step('request', [
                'prompt_version' => $promptVersion,
                'schema_version' => $schemaVersion,
                'photo_count' => count($photoRefs),
                'fallback_used' => $fallbackUsed,
                'retry_count' => $retryCount,
            ]);
        });
    }

    public function recordProviderResult(AiCompletionResult $result): self
    {
        return $this->safe(function () use ($result): void {
            $raw = $result->rawResponse;
            $safeRaw = is_string($raw) ? $this->redactor->redactString($raw) : null;
            if (is_string($safeRaw) && strlen($safeRaw) > 200_000) {
                $safeRaw = substr($safeRaw, 0, 200_000).'…[truncated]';
            }

            $params = $result->modelParameters;
            unset($params['base_url']);

            $this->assign([
                'provider' => $result->provider,
                'model' => $result->model,
                'raw_response' => $safeRaw,
                'finish_reason' => $result->finishReason,
                'provider_ms' => $result->providerMs ?? $this->trace->provider_ms,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'total_tokens' => $result->totalTokens,
                'estimated_cost_cents' => $result->estimatedCostCents,
                'parsed_response' => $this->redactor->redact($result->output),
                'model_parameters' => $params === [] ? $this->trace->model_parameters : $params,
            ]);
            $this->step('provider', [
                'provider' => $result->provider,
                'model' => $result->model,
                'finish_reason' => $result->finishReason,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'image_count' => $result->imageCount,
            ], durationMs: $result->providerMs);

            // process_ms is exclusive of provider wait.
            $this->markProcessStarted();
        });
    }

    public function recordProviderFailure(Throwable $exception): self
    {
        return $this->safe(function () use ($exception): void {
            $providerMs = $exception instanceof AiClientException ? $exception->providerMs : null;
            if ($providerMs !== null) {
                $this->assign(['provider_ms' => $providerMs]);
                $this->step('provider', [
                    'failed' => true,
                    'error' => Str::limit($this->redactor->redactString($exception->getMessage()), 300, ''),
                ], durationMs: $providerMs);
                $this->markProcessStarted();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $validationErrors
     * @param  list<array<string, mixed>>  $normalizations
     */
    public function recordParsed(
        array $parsed,
        array $validationErrors = [],
        array $normalizations = [],
    ): self {
        return $this->safe(function () use ($parsed, $validationErrors, $normalizations): void {
            $this->assign([
                'parsed_response' => $this->redactor->redact($parsed),
                'validation_errors' => $validationErrors === [] ? null : $this->redactor->redact($validationErrors),
                'normalizations' => $normalizations === [] ? null : ($this->redactor->redact(['items' => $normalizations])['items'] ?? $normalizations),
            ]);
            $this->step('parse', [
                'validation_error_count' => count($validationErrors),
                'normalization_count' => count($normalizations),
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     */
    public function recordFieldOutcomes(array $outcomes): self
    {
        return $this->safe(function () use ($outcomes): void {
            $safe = array_map(fn (array $row): array => $this->redactor->redact($row), $outcomes);
            $this->assign(['field_outcomes' => $safe]);
            $this->step('field_outcomes', ['count' => count($safe)]);
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<array<string, mixed>>  $changedFields
     */
    public function recordDossierSnapshots(array $before, array $after, array $changedFields = []): self
    {
        return $this->safe(function () use ($before, $after, $changedFields): void {
            $afterWithDiff = $after;
            if ($changedFields !== []) {
                $afterWithDiff['changed_fields'] = $changedFields;
            }

            $this->assign([
                'dossier_before' => $this->redactor->redact($before),
                'dossier_after' => $this->redactor->redact($afterWithDiff),
            ]);
            $this->step('dossier_update', [
                'answers_before' => $before['answer_count'] ?? null,
                'answers_after' => $after['answer_count'] ?? null,
                'changed_field_count' => count($changedFields),
            ]);
        });
    }

    /**
     * @param  array{questions: list<array<string, mixed>>, next_step: array<string, mixed>|null, visible_count: int, hidden_count: int}  $before
     * @param  array{questions: list<array<string, mixed>>, next_step: array<string, mixed>|null, visible_count: int, hidden_count: int}  $after
     */
    public function recordRemainingQuestions(array $before, array $after): self
    {
        return $this->safe(function () use ($before, $after): void {
            $this->assign([
                'remaining_questions_before' => $before,
                'remaining_questions_after' => $after,
            ]);
            $this->step('customer_steps', [
                'visible_before' => $before['visible_count'] ?? null,
                'visible_after' => $after['visible_count'] ?? null,
                'hidden_before' => $before['hidden_count'] ?? null,
                'hidden_after' => $after['hidden_count'] ?? null,
                'next_step_before' => $before['next_step']['question_key'] ?? null,
                'next_step_after' => $after['next_step']['question_key'] ?? null,
            ]);
        });
    }

    public function markProcessStarted(): self
    {
        $this->processStartedAt = microtime(true);

        return $this;
    }

    public function succeed(?string $message = null): AiTrace
    {
        $this->safe(function () use ($message): void {
            if ($this->buffering) {
                $this->flushBuffer();
            }

            $processMs = (int) round((microtime(true) - $this->processStartedAt) * 1000);
            $this->assign([
                'status' => AiTraceStatus::Succeeded,
                'process_ms' => $this->trace->process_ms ?? $processMs,
                'error_message' => $message,
                'finished_at' => now(),
            ]);
            $this->step('succeed', [], durationMs: $processMs);
        });

        return $this->trace;
    }

    /**
     * Persist failure metadata. Never throws — original business exceptions stay intact.
     */
    public function fail(string $errorMessage, ?Throwable $providerException = null): AiTrace
    {
        $this->safe(function () use ($errorMessage, $providerException): void {
            if ($providerException !== null) {
                $this->recordProviderFailure($providerException);
            }

            if ($this->buffering) {
                $this->flushBuffer();
            }

            $processMs = (int) round((microtime(true) - $this->processStartedAt) * 1000);
            $safe = Str::limit($this->redactor->redactString($errorMessage), 2000, '');
            $this->assign([
                'status' => AiTraceStatus::Failed,
                'process_ms' => $this->trace->process_ms ?? $processMs,
                'error_message' => $safe,
                'finished_at' => now(),
            ]);
            $this->step('fail', ['error' => Str::limit($safe, 500, '')], durationMs: $processMs);
        });

        return $this->trace;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assign(array $attributes): void
    {
        if ($this->noop) {
            return;
        }

        foreach ($attributes as $key => $value) {
            $this->trace->setAttribute($key, $value);
        }

        if ($this->buffering) {
            $this->pendingAttributes = array_merge($this->pendingAttributes, $attributes);

            return;
        }

        $this->trace->fill($attributes);
        $this->trace->save();
    }

    /**
     * @param  callable(): void  $callback
     */
    private function safe(callable $callback): self
    {
        if ($this->noop) {
            return $this;
        }

        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
