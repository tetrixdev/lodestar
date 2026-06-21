<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Self-heal the dev Vite server (see EnsureViteRunning) — local only, every minute.
Schedule::command('dev:ensure-vite')->everyMinute()->withoutOverlapping();

// Liveness backstop for the agent loop: re-queue tasks whose worker died or
// forgot to advance out of a working (*-ing) state (see ReapStalledTasks).
Schedule::command('lodestar:reap-stalled-tasks')->everyFiveMinutes()->withoutOverlapping();

// Reconcile the embeddings index when a live model event was missed (crash,
// bulk import, a key that arrived late) — embeds new/changed/missing, drops
// orphans (see EmbedSync). Mirrors the reaper: every five minutes, no overlap.
Schedule::command('lodestar:embed-sync')->everyFiveMinutes()->withoutOverlapping();
