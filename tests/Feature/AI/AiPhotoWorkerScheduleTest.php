<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('queue-ai-photo-worker runs every minute with overlap lock longer than max-time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->description === 'queue-ai-photo-worker');

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60)
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain('--queue='.AssessUploadedPhotoJob::QUEUE.',default')
        ->and($event->command)->toContain('--max-time=3300')
        ->and($event->command)->toContain('--memory='.config('intake.php.queue_worker_memory_mb', 256))
        ->and($event->expiresAt * 60)->toBeGreaterThan(3300);
});

test('cli memory vangnet config is 256M and does not force-lower intentional higher limits', function () {
    $cliLimit = (string) config('intake.php.cli_memory_limit', '256M');
    $current = (string) ini_get('memory_limit');

    // Hosting CLI is already 256M; AppServiceProvider only caps -1 / raises too-low.
    // Under Pest the process already has a finite limit — boot must not shrink it.
    expect($cliLimit)->toBe('256M')
        ->and($current)->not->toBe('-1');
});

test('queue worker memory restart threshold stays at 256 MB', function () {
    expect((int) config('intake.php.queue_worker_memory_mb', 256))->toBe(256);
});

test('queue-ai-photo-worker background command finishes with schedule:finish to release mutex', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->description === 'queue-ai-photo-worker');

    expect($event)->toBeInstanceOf(Event::class);

    $built = $event->buildCommand();

    expect($built)->toContain('schedule:finish')
        ->and($built)->toContain($event->mutexName());
});

test('photos requeue watchdog runs every five minutes not every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'photos:requeue-pending-assessments'));

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('*/5 * * * *');
});
