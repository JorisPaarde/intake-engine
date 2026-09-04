<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Logging\AppErrorLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records 5xx responses and abrupt PHP endings for staging/production debugging.
 */
final class LogServerErrorResponses
{
    public function __construct(
        private readonly AppErrorLogger $logger,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->logger->begin($request);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->logger->complete($request, $response);
    }
}
