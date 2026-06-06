<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly database backup (keep 14 days). Requires the scheduler to be
// running — see database/BACKUPS.md.
Schedule::command('db:backup')->dailyAt('01:00')->withoutOverlapping();
