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

/*
 * Foto-AI (queue ai-photo) + overige jobs. cPanel heeft geen Supervisor.
 * Zonder aparte snelle worker pikt alleen de minutelijk stop-when-empty-cron
 * jobs op (tot ~60 s latency). Deze scheduler-entry start elk uur een
 * langere worker met zonderOverlapping (cache-lock ≈ flock), zodat ai-photo
 * met sleep=1 sneller wordt opgepakt zolang schedule:run minutelijks draait.
 */
Schedule::command('queue:work --queue='.AssessUploadedPhotoJob::QUEUE.',default --max-time=3500 --sleep=1 --tries=2')
    ->hourly()
    ->withoutOverlapping(55)
    ->runInBackground()
    ->name('queue-ai-photo-worker');
