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
// Watchdog alleen: upload dispatcht AssessUploadedPhotoJob meteen. Elke 5 min i.p.v.
// elke minuut beperkt PHP/LVE-geheugenpieken op cPanel (512 MB).
Schedule::command('photos:requeue-pending-assessments')->everyFiveMinutes();

/*
 * Foto-AI (queue ai-photo) + overige jobs. cPanel heeft geen Supervisor.
 * Alleen `schedule:run` hoort in crontab — géén aparte queue:work --stop-when-empty.
 * schedule:run start elke minuut deze lange worker opnieuw als hij niet
 * draait (withoutOverlapping + runInBackground). Bij queue:restart (deploy)
 * eindigt de worker; schedule:finish geeft de mutex vrij, zodat de volgende
 * schedule:run (~1 min, cPanel RANDOM_DELAY kan tot ~3 min vertragen) weer
 * start. Mutex-expiry (60 min) > max-time (3300 s ≈ 55 min) voorkomt overlap.
 */
$queueWorkerMemoryMb = max(64, (int) config('intake.php.queue_worker_memory_mb', 256));

Schedule::command('queue:work --queue='.AssessUploadedPhotoJob::QUEUE.',default --max-time=3300 --memory='.$queueWorkerMemoryMb.' --sleep=1 --tries=2')
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('queue-ai-photo-worker');
