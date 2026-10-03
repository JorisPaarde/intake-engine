<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiTrace;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use Illuminate\Support\Str;
use Throwable;

/**
 * Factory for AI correlation traces (klanttest 2026-10-02 logging eisen).
 *
 * Usage for parallel streams after merge:
 *
 *   $trace = app(AiTraceRecorder::class)->start($intake, AiTraceCallType::PhotoDerive, [
 *       'subject_type' => 'room',
 *       'subject_id' => $sectionInstanceKey,
 *   ]);
 *   $trace->step('normalize', [...]);
 *   $trace->succeed();
 *
 * When {@see config('ai.tracing.enabled')} is false, {@see start()} returns a no-op handle
 * that writes nothing. Persistence is deferred until succeed()/fail().
 *
 * Traces denormalise intake_ref_id + is_demo so they survive demo intake purge
 * (intake_id nullOnDelete). Retention is only via ai:purge-traces.
 */
final class AiTraceRecorder
{
    public function __construct(
        private readonly AiTraceRedactor $redactor,
        private readonly AiTraceRequestIdResolver $requestIdResolver,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ai.tracing.enabled', true);
    }

    /**
     * @param  array{
     *     subject_type?: string|null,
     *     subject_id?: string|null,
     *     upload_id?: int|null,
     *     ai_run_id?: int|null,
     *     correlation_id?: string|null,
     *     parent_trace_id?: string|null,
     *     request_id?: string|null,
     *     provider?: string|null,
     *     prompt_version?: string|null,
     *     model_parameters?: array<string, mixed>|null,
     *     persist_ms?: int|null,
     *     preprocess_ms?: int|null,
     *     network_upload_ms?: int|null,
     *     fallback_used?: bool|null,
     *     retry_count?: int|null,
     * }  $attributes
     */
    public function start(Intake $intake, AiTraceCallType $callType, array $attributes = []): AiTraceHandle
    {
        $redactor = $this->redactor->withKnownPii([
            'customer_name' => $intake->customer_name,
            'customer_email' => $intake->customer_email,
            'customer_phone' => $intake->customer_phone ?? null,
            'address_line' => $intake->address_line,
        ]);

        if (! $this->enabled()) {
            $placeholder = new AiTrace([
                'trace_id' => '',
                'intake_id' => $intake->id,
                'intake_ref_id' => $intake->id,
                'is_demo' => (bool) $intake->is_demo,
                'call_type' => $callType,
                'status' => AiTraceStatus::Pending,
            ]);

            return AiTraceHandle::disabled($placeholder, $redactor);
        }

        try {
            $correlationId = $attributes['correlation_id'] ?? (string) Str::uuid();
            $explicitRequestId = $attributes['request_id'] ?? null;
            $requestId = $this->requestIdResolver->resolve(
                is_string($explicitRequestId) && $explicitRequestId !== '' ? $explicitRequestId : null,
            );

            $trace = new AiTrace([
                'trace_id' => (string) Str::uuid(),
                'correlation_id' => $correlationId,
                'request_id' => $requestId,
                'parent_trace_id' => $attributes['parent_trace_id'] ?? null,
                'intake_id' => $intake->id,
                'intake_ref_id' => $intake->id,
                'is_demo' => (bool) $intake->is_demo,
                'ai_run_id' => $attributes['ai_run_id'] ?? null,
                'upload_id' => $attributes['upload_id'] ?? null,
                'subject_type' => $attributes['subject_type'] ?? null,
                'subject_id' => $attributes['subject_id'] ?? null,
                'call_type' => $callType,
                'status' => AiTraceStatus::Pending,
                'provider' => $attributes['provider'] ?? null,
                'prompt_version' => $attributes['prompt_version'] ?? null,
                'model_parameters' => $attributes['model_parameters'] ?? null,
                'persist_ms' => $attributes['persist_ms'] ?? null,
                'preprocess_ms' => $attributes['preprocess_ms'] ?? null,
                'network_upload_ms' => $attributes['network_upload_ms'] ?? null,
                'fallback_used' => (bool) ($attributes['fallback_used'] ?? false),
                'retry_count' => max(0, (int) ($attributes['retry_count'] ?? 0)),
                'started_at' => now(),
            ]);

            $handle = new AiTraceHandle($trace, $redactor);
            $handle->step('start', [
                'call_type' => $callType->value,
                'intake_id' => $intake->id,
                'intake_ref_id' => $intake->id,
                'is_demo' => (bool) $intake->is_demo,
                'correlation_id' => $correlationId,
                'request_id' => $requestId,
                'tracing_enabled' => true,
            ]);

            return $handle;
        } catch (Throwable $exception) {
            report($exception);

            $placeholder = new AiTrace([
                'trace_id' => '',
                'intake_id' => $intake->id,
                'intake_ref_id' => $intake->id,
                'is_demo' => (bool) $intake->is_demo,
                'call_type' => $callType,
                'status' => AiTraceStatus::Pending,
            ]);

            return AiTraceHandle::disabled($placeholder, $redactor);
        }
    }

    /**
     * Record client-measured network upload time onto the upload and any existing traces.
     */
    public function recordNetworkUploadMs(IntakeUpload $upload, int $ms): void
    {
        try {
            $ms = max(0, $ms);
            $timings = $upload->processing_timings ?? [];
            $timings['network_upload_ms'] = $ms;
            $upload->processing_timings = $timings;
            $upload->save();

            AiTrace::query()
                ->where('upload_id', $upload->id)
                ->update(['network_upload_ms' => $ms]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
