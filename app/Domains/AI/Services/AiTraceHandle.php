<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Models\AiTraceStep;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiTraceStatus;
use Illuminate\Support\Str;

/**
 * Mutable handle for one AI correlation trace.
 *
 * Other feature streams should attach work via {@see self::step()}:
 *
 *   $trace->step('normalize', ['defaults' => [...]], durationMs: 12);
 *   $trace->step('customer_step', ['question_key' => 'room_photos']);
 *
 * Persist happens on each step and on succeed/fail so partial traces survive crashes.
 */
final class AiTraceHandle
{
    private int $sequence = 0;

    private float $processStartedAt;

    public function __construct(
        private AiTrace $trace,
        private readonly AiTraceRedactor $redactor,
    ) {
        $this->processStartedAt = microtime(true);
        $this->sequence = (int) $this->trace->steps()->max('sequence');
    }

    public function id(): int
    {
        return (int) $this->trace->id;
    }

    public function traceId(): string
    {
        return (string) $this->trace->trace_id;
    }

    public function model(): AiTrace
    {
        return $this->trace;
    }

    /**
     * Attach a named processing step (normalize, validate, apply, customer_step, …).
     *
     * @param  array<string, mixed>  $payload
     */
    public function step(string $key, array $payload = [], ?int $durationMs = null): self
    {
        $this->sequence++;

        AiTraceStep::query()->create([
            'ai_trace_id' => $this->trace->id,
            'step_key' => Str::limit($key, 80, ''),
            'sequence' => $this->sequence,
            'payload' => $payload === [] ? null : $this->redactor->redact($payload),
            'duration_ms' => $durationMs,
            'recorded_at' => now(),
        ]);

        return $this;
    }

    public function linkUpload(?IntakeUpload $upload): self
    {
        if ($upload === null) {
            return $this;
        }

        $timings = $upload->processing_timings ?? [];
        $uploadMs = array_key_exists('upload_ms', $timings) ? $this->intOrNull($timings['upload_ms']) : $this->trace->upload_ms;
        $preprocessMs = array_key_exists('preprocess_ms', $timings) ? $this->intOrNull($timings['preprocess_ms']) : $this->trace->preprocess_ms;

        $this->trace->update([
            'upload_id' => $upload->id,
            'upload_ms' => $uploadMs,
            'preprocess_ms' => $preprocessMs,
            'subject_type' => $this->trace->subject_type ?? 'question',
            'subject_id' => $this->trace->subject_id ?? $upload->question_key.($upload->section_instance_key ? '|'.$upload->section_instance_key : ''),
        ]);

        $this->trace = $this->trace->fresh() ?? $this->trace;

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
        ], durationMs: $uploadMs);

        if (array_key_exists('preprocess_ms', $timings)) {
            $this->step('preprocess', [
                'upload_id' => $upload->id,
                'analysis_path_ref' => 'intake_upload_analysis:'.$upload->id,
            ], durationMs: $preprocessMs);
        }

        return $this;
    }

    public function linkAiRun(?AiRun $run): self
    {
        if ($run === null) {
            return $this;
        }

        $this->trace->update([
            'ai_run_id' => $run->id,
            'provider' => $run->provider ?: $this->trace->provider,
            'model' => $run->model ?? $this->trace->model,
            'prompt_version' => $run->prompt_version ?: $this->trace->prompt_version,
        ]);

        $this->trace = $this->trace->fresh() ?? $this->trace;
        $this->step('ai_run', ['ai_run_id' => $run->id, 'type' => $run->type->value]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $systemAndUser
     * @param  list<array<string, mixed>>  $photoRefs  Never include base64 — only ids/checksums/dims
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
        $this->trace->update([
            'request_snapshot' => $this->redactor->redact($systemAndUser),
            'photo_refs' => $photoRefs === [] ? null : $this->redactor->redact(['refs' => $photoRefs])['refs'] ?? $photoRefs,
            'prompt_version' => $promptVersion ?? $this->trace->prompt_version,
            'schema_version' => $schemaVersion ?? $this->trace->schema_version,
            'model_parameters' => $modelParameters === [] ? $this->trace->model_parameters : $modelParameters,
            'fallback_used' => $fallbackUsed,
            'retry_count' => max(0, $retryCount),
        ]);

        $this->trace = $this->trace->fresh() ?? $this->trace;
        $this->step('request', [
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
            'photo_count' => count($photoRefs),
            'fallback_used' => $fallbackUsed,
            'retry_count' => $retryCount,
        ]);

        return $this;
    }

    public function recordProviderResult(AiCompletionResult $result, ?string $rawResponse = null): self
    {
        $raw = $rawResponse ?? $result->rawResponse;
        $safeRaw = is_string($raw) ? $this->redactor->redactString($raw) : null;

        if (is_string($safeRaw) && strlen($safeRaw) > 200_000) {
            $safeRaw = substr($safeRaw, 0, 200_000).'…[truncated]';
        }

        $this->trace->update([
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
        ]);

        $this->trace = $this->trace->fresh() ?? $this->trace;
        $this->step('provider', [
            'provider' => $result->provider,
            'model' => $result->model,
            'finish_reason' => $result->finishReason,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'image_count' => $result->imageCount,
        ], durationMs: $result->providerMs);

        return $this;
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
        $this->trace->update([
            'parsed_response' => $this->redactor->redact($parsed),
            'validation_errors' => $validationErrors === [] ? null : $this->redactor->redact($validationErrors),
            'normalizations' => $normalizations === [] ? null : $this->redactor->redact(['items' => $normalizations])['items'] ?? $normalizations,
        ]);

        $this->trace = $this->trace->fresh() ?? $this->trace;
        $this->step('parse', [
            'validation_error_count' => count($validationErrors),
            'normalization_count' => count($normalizations),
        ]);

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes  accepted/rejected fields with reason/confidence/source
     */
    public function recordFieldOutcomes(array $outcomes): self
    {
        $safe = array_map(fn (array $row): array => $this->redactor->redact($row), $outcomes);

        $this->trace->update(['field_outcomes' => $safe]);
        $this->trace = $this->trace->fresh() ?? $this->trace;
        $this->step('field_outcomes', ['count' => count($safe)]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordDossierSnapshots(array $before, array $after): self
    {
        $this->trace->update([
            'dossier_before' => $this->redactor->redact($before),
            'dossier_after' => $this->redactor->redact($after),
        ]);
        $this->trace = $this->trace->fresh() ?? $this->trace;
        $this->step('dossier_update', [
            'answers_before' => $before['answer_count'] ?? null,
            'answers_after' => $after['answer_count'] ?? null,
        ]);

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    public function recordRemainingQuestions(array $before, array $after): self
    {
        $this->trace->update([
            'remaining_questions_before' => $before,
            'remaining_questions_after' => $after,
        ]);
        $this->trace = $this->trace->fresh() ?? $this->trace;
        $this->step('customer_steps', [
            'before_count' => count($before),
            'after_count' => count($after),
        ]);

        return $this;
    }

    public function setTiming(string $phase, int $ms): self
    {
        $column = match ($phase) {
            'upload' => 'upload_ms',
            'preprocess' => 'preprocess_ms',
            'provider' => 'provider_ms',
            'process' => 'process_ms',
            default => null,
        };

        if ($column !== null) {
            $this->trace->update([$column => max(0, $ms)]);
            $this->trace = $this->trace->fresh() ?? $this->trace;
        }

        return $this;
    }

    public function markProcessStarted(): self
    {
        $this->processStartedAt = microtime(true);

        return $this;
    }

    public function succeed(?string $message = null): AiTrace
    {
        $processMs = (int) round((microtime(true) - $this->processStartedAt) * 1000);

        $this->trace->update([
            'status' => AiTraceStatus::Succeeded,
            'process_ms' => $this->trace->process_ms ?? $processMs,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        $this->step('succeed', [], durationMs: $processMs);

        return $this->trace->fresh() ?? $this->trace;
    }

    public function fail(string $errorMessage): AiTrace
    {
        $processMs = (int) round((microtime(true) - $this->processStartedAt) * 1000);

        $this->trace->update([
            'status' => AiTraceStatus::Failed,
            'process_ms' => $this->trace->process_ms ?? $processMs,
            'error_message' => Str::limit($this->redactor->redactString($errorMessage), 2000, ''),
            'finished_at' => now(),
        ]);

        $this->step('fail', ['error' => Str::limit($this->redactor->redactString($errorMessage), 500, '')], durationMs: $processMs);

        return $this->trace->fresh() ?? $this->trace;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
