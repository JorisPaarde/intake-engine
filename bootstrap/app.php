<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCustomerIntakeAccess;
use App\Http\Middleware\EnsureDevAccess;
use App\Http\Middleware\LogServerErrorResponses;
use App\Http\Middleware\RestrictPublicDemoSession;
use App\Support\Logging\AppErrorLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'customer.intake' => EnsureCustomerIntakeAccess::class,
            'dev.access' => EnsureDevAccess::class,
            'public.demo.scope' => RestrictPublicDemoSession::class,
        ]);

        // Log Laravel-produced 5xx (incl. abort(503)) and abrupt PHP endings.
        $middleware->appendToGroup('web', LogServerErrorResponses::class);

        // Stale demo auth (purged ephemeral user / dead login id) must not land
        // on the real installer login form — send them to the demo-ended page.
        // That page clears url.intended so a later real login does not resume a
        // dead demo intake URL (404). Login also clears leftover demo flags.
        $middleware->redirectGuestsTo(function (Request $request): string {
            $session = $request->hasSession() ? $request->session() : null;

            if ($session !== null
                && (
                    (bool) $session->get('public_demo_mode', false)
                    || $session->has('public_demo_intake_id')
                )) {
                return route('demo.ended', ['reason' => 'expired']);
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Default Laravel ignores all HttpException (incl. 503). Report 5xx so
        // abort()/maintenance-style failures leave a stack in laravel.log.
        $exceptions->stopIgnoring(HttpException::class);
        $exceptions->dontReportWhen(
            static fn (Throwable $e): bool => $e instanceof HttpExceptionInterface
                && $e->getStatusCode() < 500,
        );

        $exceptions->context(function () {
            if (app()->runningInConsole()) {
                return [];
            }

            $request = request();
            if (! $request instanceof Request) {
                return [];
            }

            return app(AppErrorLogger::class)->requestContext($request);
        });
    })->create();
