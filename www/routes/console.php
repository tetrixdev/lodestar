<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Self-heal the dev Vite server (see EnsureViteRunning) — local only, every minute.
Schedule::command('dev:ensure-vite')->everyMinute()->withoutOverlapping();
