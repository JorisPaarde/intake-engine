<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiTrace;
use App\Domains\Intake\Models\IntakeUpload;
use App\Support\Logging\AppErrorLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resolves a stable HTTP/Livewire request id or queued job id for AI-trace correlation.
 * Always returns a non-empty string (≤80 chars). Provider completion ids are stored
 * separately as {@see AiTrace::$provider_response_id}.
 *
 * Per-upload photo chains use {@see resolveCorrelationIdForUpload()} so each upload
 * keeps one stable correlation_id (persisted on processing_timings) without a second
 * parallel correlation mechanism.
 */
final class AiTraceRequestIdResolver
{
    public const CONTEXT_KEY = 'ai_trace.request_id';

    public const JOB_CONTEXT_KEY = 'ai_trace.job_id';

    public const CORRELATION_CONTEXT_KEY = 'ai_trace.correlation_id';

    public const QUEUE_WAIT_CONTEXT_KEY = 'ai_trace.queue_wait_ms';

    public const ATTEMPT_CONTEXT_KEY = 'ai_trace.attempt';

    public const QUEUED_AT_CONTEXT_KEY = 'ai_trace.queued_at';

    public function resolve(?string $explicit = null): string
    {
        if (is_string($explicit) && $explicit !== '') {
            return $this->remember($explicit);
        }

        try {
            if (Context::has(self::CONTEXT_KEY)) {
                $fromContext = Context::get(self::CONTEXT_KEY);
                if (is_string($fromContext) && $fromContext !== '') {
                    return Str::limit($fromContext, 80, '');
                }
            }
        } catch (Throwable) {
            // Context may be unavailable in early bootstrap.
        }

        try {
            $request = request();
            $fromAttr = $request->attributes->get(AppErrorLogger::ATTR_REQUEST_ID);
            if (is_string($fromAttr) && $fromAttr !== '') {
                return $this->remember($fromAttr);
            }

            $header = $request->headers->get('X-Request-Id');
            if (is_string($header) && $header !== '') {
                return $this->remember($header);
            }
        } catch (Throwable) {
            // No HTTP request (console / early boot).
        }

        try {
            if (Context::has(self::JOB_CONTEXT_KEY)) {
                $jobId = Context::get(self::JOB_CONTEXT_KEY);
                if (is_string($jobId) && $jobId !== '') {
                    return $this->remember('job:'.$jobId);
                }
            }
        } catch (Throwable) {
            // Ignore.
        }

        return $this->remember((string) Str::uuid());
    }

    public function remember(string $requestId): string
    {
        $limited = Str::limit(trim($requestId), 80, '');
        if ($limited === '') {
            $limited = (string) Str::uuid();
        }

        try {
            Context::add(self::CONTEXT_KEY, $limited);
        } catch (Throwable) {
            // Ignore.
        }

        return $limited;
    }

    public function rememberJobId(string $jobId): void
    {
        if ($jobId === '') {
            return;
        }

        try {
            Context::add(self::JOB_CONTEXT_KEY, $jobId);
            Context::add(self::CONTEXT_KEY, 'job:'.$jobId);
        } catch (Throwable) {
            // Ignore.
        }
    }

    public function rememberCorrelationId(?string $correlationId): void
    {
        if (! is_string($correlationId) || $correlationId === '') {
            return;
        }

        try {
            Context::add(self::CORRELATION_CONTEXT_KEY, $correlationId);
        } catch (Throwable) {
            // Ignore.
        }
    }

    public function resolveCorrelationId(?string $explicit = null): string
    {
        if (is_string($explicit) && $explicit !== '') {
            $this->rememberCorrelationId($explicit);

            return $explicit;
        }

        try {
            if (Context::has(self::CORRELATION_CONTEXT_KEY)) {
                $fromContext = Context::get(self::CORRELATION_CONTEXT_KEY);
                if (is_string($fromContext) && $fromContext !== '') {
                    return $fromContext;
                }
            }
        } catch (Throwable) {
            // Ignore.
        }

        $generated = (string) Str::uuid();
        $this->rememberCorrelationId($generated);

        return $generated;
    }

    /**
     * Stable correlation for one upload's photo-assessment chain.
     *
     * Prefer explicit (job/dispatch), else the upload's stored processing_timings
     * correlation_id, else mint + persist. Does not reuse ambient context from a
     * sibling upload in the same batch.
     */
    public function resolveCorrelationIdForUpload(IntakeUpload $upload, ?string $explicit = null): string
    {
        if (is_string($explicit) && $explicit !== '') {
            $this->persistUploadCorrelation($upload, $explicit);

            return $this->resolveCorrelationId($explicit);
        }

        $timings = is_array($upload->processing_timings) ? $upload->processing_timings : [];
        $stored = $timings['correlation_id'] ?? null;
        if (is_string($stored) && $stored !== '') {
            return $this->resolveCorrelationId($stored);
        }

        $id = (string) Str::uuid();
        $this->persistUploadCorrelation($upload, $id);

        return $this->resolveCorrelationId($id);
    }

    private function persistUploadCorrelation(IntakeUpload $upload, string $correlationId): void
    {
        $timings = is_array($upload->processing_timings) ? $upload->processing_timings : [];
        if (($timings['correlation_id'] ?? null) === $correlationId) {
            return;
        }

        $timings['correlation_id'] = $correlationId;
        $upload->update(['processing_timings' => $timings]);
    }

    public function rememberQueueMetrics(?int $queueWaitMs, ?int $attempt, ?float $dispatchedAt = null): void
    {
        try {
            if ($queueWaitMs !== null) {
                Context::add(self::QUEUE_WAIT_CONTEXT_KEY, max(0, $queueWaitMs));
            }
            if ($attempt !== null) {
                Context::add(self::ATTEMPT_CONTEXT_KEY, max(1, $attempt));
            }
            if ($dispatchedAt !== null && $dispatchedAt > 0) {
                Context::add(self::QUEUED_AT_CONTEXT_KEY, $dispatchedAt);
            } elseif ($queueWaitMs !== null) {
                Context::add(self::QUEUED_AT_CONTEXT_KEY, microtime(true) - (max(0, $queueWaitMs) / 1000));
            }
        } catch (Throwable) {
            // Ignore.
        }
    }

    public function queuedAtUnix(): ?float
    {
        try {
            if (! Context::has(self::QUEUED_AT_CONTEXT_KEY)) {
                return null;
            }
            $value = Context::get(self::QUEUED_AT_CONTEXT_KEY);

            return is_float($value) || is_int($value) || is_numeric($value)
                ? (float) $value
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function queueWaitMs(): ?int
    {
        try {
            if (! Context::has(self::QUEUE_WAIT_CONTEXT_KEY)) {
                return null;
            }
            $value = Context::get(self::QUEUE_WAIT_CONTEXT_KEY);

            return is_int($value) || is_numeric($value) ? max(0, (int) $value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function attempt(): ?int
    {
        try {
            if (! Context::has(self::ATTEMPT_CONTEXT_KEY)) {
                return null;
            }
            $value = Context::get(self::ATTEMPT_CONTEXT_KEY);

            return is_int($value) || is_numeric($value) ? max(1, (int) $value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
