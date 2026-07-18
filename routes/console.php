<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('intakes:purge-demos')->hourly();
Schedule::command('intakes:send-reminders')->daily();
Schedule::command('intakes:purge-deleted')->daily();
