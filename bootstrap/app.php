<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCustomerIntakeAccess;
use App\Http\Middleware\EnsureDevAccess;
use App\Http\Middleware\RestrictPublicDemoSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
    })->create();
