<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// All scheduled work requires `schedule:run` to fire every minute — see
// docs/OPERATIONS.md for the Windows Task Scheduler registration.

// Nightly database backup (keep 14 days).
Schedule::command('db:backup')->dailyAt('01:00')->withoutOverlapping();

// Weekly prune of old audit logs to bound database growth.
Schedule::command('app:prune-logs')->weeklyOn(0, '03:00')->withoutOverlapping();

// Heartbeat: proves the scheduler is actually running. app:doctor warns if
// this file goes stale (> 5 min), which means schedule:run is not firing.
Schedule::call(function () {
    @file_put_contents(storage_path('app/scheduler-heartbeat'), now()->toIso8601String());
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();
