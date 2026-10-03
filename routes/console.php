<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('intakes:purge-demos')->hourly();
Schedule::command('intakes:send-reminders')->daily();
Schedule::command('intakes:purge-deleted')->daily();
Schedule::command('product-interests:purge')->daily();
Schedule::command('ai:purge-traces')->daily();
Schedule::command('photos:requeue-pending-assessments')->everyMinute();

/*
 * Foto-AI (queue ai-photo) + overige jobs. cPanel heeft geen Supervisor.
 * schedule:run start elke minuut deze lange worker opnieuw als hij niet
 * draait (withoutOverlapping + runInBackground). Bij queue:restart (deploy)
 * eindigt de worker; schedule:finish geeft de mutex vrij, zodat de volgende
 * schedule:run (~1 min, cPanel RANDOM_DELAY kan tot ~3 min vertragen) weer
 * start. Mutex-expiry (60 min) > max-time (3300 s ≈ 55 min) voorkomt overlap.
 * De minutelijke stop-when-empty-cron blijft het vangnet.
 */
Schedule::command('queue:work --queue='.AssessUploadedPhotoJob::QUEUE.',default --max-time=3300 --memory=256 --sleep=1 --tries=2')
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('queue-ai-photo-worker');
