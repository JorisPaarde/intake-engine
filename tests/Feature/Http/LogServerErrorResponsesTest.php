<?php

declare(strict_types=1);

use App\Support\Logging\AppErrorLogger;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

it('redacts customer access tokens in paths', function () {
    $logger = new AppErrorLogger;
    $token = str_repeat('a', 64);

    expect($logger->redactPath('o/'.$token))
        ->toBe('/o/[redacted]')
        ->and($logger->redactPath('o/'.$token.'/follow-up'))
        ->toBe('/o/[redacted]/follow-up')
        ->and($logger->redactPath('intakes/12'))
        ->toBe('/intakes/12');
});

it('logs HTTP 503 responses with request context', function () {
    Event::fake([MessageLogged::class]);

    Route::middleware('web')->get('/__test/server-error-503', function () {
        abort(503, 'Service Unavailable');
    });

    $this->get('/__test/server-error-503')->assertStatus(503);

    Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
        return $event->level === 'error'
            && str_contains($event->message, 'HTTP server error response')
            && ($event->context['status'] ?? null) === 503
            && ($event->context['path'] ?? null) === '/__test/server-error-503'
            && ($event->context['method'] ?? null) === 'GET';
    });
});

it('does not log client error responses as server errors', function () {
    Event::fake([MessageLogged::class]);

    // Return 404 directly — avoids rendering errors/404.blade.php (needs Vite build).
    Route::middleware('web')->get('/__test/server-error-404', function () {
        return response('Niet gevonden', 404);
    });

    $this->get('/__test/server-error-404')->assertNotFound();

    Event::assertNotDispatched(MessageLogged::class, function (MessageLogged $event): bool {
        return $event->level === 'error'
            && str_contains($event->message, 'HTTP server error response');
    });
});

it('enriches manual error reports with request context', function () {
    Event::fake([MessageLogged::class]);

    $request = Request::create('/intakes', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->attributes->set(AppErrorLogger::ATTR_REQUEST_ID, 'req-test-1');
    app()->instance('request', $request);

    app(AppErrorLogger::class)->error('Manual domain failure', ['intake_uuid' => 'demo-uuid']);

    Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
        return $event->level === 'error'
            && $event->message === 'Manual domain failure'
            && ($event->context['intake_uuid'] ?? null) === 'demo-uuid'
            && ($event->context['path'] ?? null) === '/intakes'
            && ($event->context['method'] ?? null) === 'POST'
            && ($event->context['request_id'] ?? null) === 'req-test-1';
    });
});
