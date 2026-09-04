<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shared error logger so ops can follow what goes wrong for users.
 *
 * - Call {@see error()} / {@see warning()} from domain code for soft failures.
 * - Web middleware uses {@see begin()} / {@see complete()} for HTTP ≥500 and
 *   abrupt PHP endings (LiteSpeed 503 trails when PHP still ran).
 * - Customer tokens in paths are redacted; no request bodies or secrets.
 */
final class AppErrorLogger
{
    public const ATTR_STARTED_AT = 'app_error_logger.started_at';

    public const ATTR_REQUEST_ID = 'app_error_logger.request_id';

    public const ATTR_COMPLETED = 'app_error_logger.completed';

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        $this->write('error', $message, $context, $exception);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = [], ?Throwable $exception = null): void
    {
        $this->write('warning', $message, $context, $exception);
    }

    public function begin(Request $request): void
    {
        if ($request->attributes->has(self::ATTR_REQUEST_ID)) {
            return;
        }

        $request->attributes->set(self::ATTR_STARTED_AT, microtime(true));
        $request->attributes->set(self::ATTR_REQUEST_ID, (string) Str::uuid());
        $request->attributes->set(self::ATTR_COMPLETED, false);

        register_shutdown_function(function () use ($request): void {
            if ($request->attributes->get(self::ATTR_COMPLETED) === true) {
                return;
            }

            $this->logAbruptEnd($request, error_get_last());
        });
    }

    public function complete(Request $request, Response $response): void
    {
        $request->attributes->set(self::ATTR_COMPLETED, true);

        $status = $response->getStatusCode();
        if ($status < 500) {
            return;
        }

        $this->error('HTTP server error response', [
            ...$this->requestContext($request),
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function requestContext(Request $request): array
    {
        $session = $request->hasSession() ? $request->session() : null;
        $startedAt = $request->attributes->get(self::ATTR_STARTED_AT);
        $durationMs = is_float($startedAt) || is_int($startedAt)
            ? (int) round((microtime(true) - (float) $startedAt) * 1000)
            : null;

        $referer = $request->headers->get('referer');
        $userAgent = $request->userAgent();
        $user = $request->user();

        return array_filter([
            'request_id' => $request->attributes->get(self::ATTR_REQUEST_ID),
            'method' => $request->method(),
            'path' => $this->redactPath($request->path()),
            'route' => $request->route()?->getName(),
            'duration_ms' => $durationMs,
            'user_id' => $user?->getAuthIdentifier(),
            'company_id' => $user instanceof User ? $user->company_id : null,
            'demo_mode' => $session !== null ? (bool) $session->get('public_demo_mode', false) : null,
            'demo_intake_id' => $session?->get('public_demo_intake_id'),
            'livewire' => $request->headers->has('X-Livewire') || $request->is('livewire/*'),
            'referer_path' => is_string($referer) ? $this->redactPath((string) (parse_url($referer, PHP_URL_PATH) ?: $referer)) : null,
            'user_agent' => is_string($userAgent) ? Str::limit($userAgent, 180, '') : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function redactPath(string $path): string
    {
        $normalized = '/'.ltrim($path, '/');

        // Customer intake tokens are Str::random(64) → [A-Za-z0-9].
        $redacted = preg_replace('#^/o/[A-Za-z0-9]{32,}(/.*)?$#', '/o/[redacted]$1', $normalized);

        return is_string($redacted) ? $redacted : $normalized;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $message, array $context, ?Throwable $exception): void
    {
        try {
            $payload = $context;

            // PHPUnit runs in console but still has an HTTP request during feature tests.
            if (app()->bound('request')) {
                $payload = [
                    ...$this->requestContext(request()),
                    ...$context,
                ];
            }

            if ($exception !== null) {
                $payload['exception'] = $exception::class;
                $payload['exception_message'] = Str::limit($exception->getMessage(), 300, '');
            }

            Log::log($level, $message, $payload);
        } catch (Throwable) {
            // Never break the caller while logging failures.
        }
    }

    /**
     * @param  array{type?: int, message?: string, file?: string, line?: int}|null  $lastError
     */
    private function logAbruptEnd(Request $request, ?array $lastError): void
    {
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        $isFatal = $lastError !== null
            && in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true);
        $connectionAborted = connection_status() !== CONNECTION_NORMAL;

        // Skip quiet shutdowns where terminate() was simply not reached without a
        // fatal/abort — avoids noise. LiteSpeed SIGKILL still leaves no PHP trail.
        if (! $isFatal && ! $connectionAborted) {
            return;
        }

        $context = [
            ...$this->requestContext($request),
            'reason' => 'request_ended_without_response',
            'connection_status' => connection_status(),
        ];

        if ($lastError !== null) {
            $context['php_error_type'] = $lastError['type'] ?? null;
            $context['php_error_message'] = isset($lastError['message'])
                ? Str::limit((string) $lastError['message'], 300, '')
                : null;
            $context['php_error_file'] = isset($lastError['file'])
                ? basename((string) $lastError['file'])
                : null;
            $context['php_error_line'] = $lastError['line'] ?? null;
        }

        $this->error('HTTP request ended without clean response', $context);
    }
}
