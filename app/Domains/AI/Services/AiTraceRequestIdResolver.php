<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Support\Logging\AppErrorLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resolves the HTTP/Livewire request id or queued job id for AI-trace correlation.
 */
final class AiTraceRequestIdResolver
{
    public const CONTEXT_KEY = 'ai_trace.request_id';

    public const JOB_CONTEXT_KEY = 'ai_trace.job_id';

    public function resolve(?string $explicit = null): string
    {
        if (is_string($explicit) && $explicit !== '') {
            return Str::limit($explicit, 80, '');
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
                return Str::limit($fromAttr, 80, '');
            }

            $header = $request->headers->get('X-Request-Id');
            if (is_string($header) && $header !== '') {
                return Str::limit($header, 80, '');
            }
        } catch (Throwable) {
            // No HTTP request (console / early boot).
        }

        try {
            if (Context::has(self::JOB_CONTEXT_KEY)) {
                $jobId = Context::get(self::JOB_CONTEXT_KEY);
                if (is_string($jobId) && $jobId !== '') {
                    return Str::limit('job:'.$jobId, 80, '');
                }
            }
        } catch (Throwable) {
            // Ignore.
        }

        $generated = (string) Str::uuid();

        try {
            Context::add(self::CONTEXT_KEY, $generated);
        } catch (Throwable) {
            // Ignore.
        }

        return $generated;
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
}
