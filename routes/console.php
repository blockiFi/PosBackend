<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Server-to-Server Sync Schedule (only runs on edge servers)
if (config('sync.mode') === 'edge' && config('sync.auto_sync')) {
    Schedule::command('server:sync')->everyThirtySeconds();
}

// Quick sale discount lifecycle
Schedule::command('quicksales:activate')->everyMinute();
Schedule::command('quicksales:cleanup-discounts --all')->dailyAt('01:00');

// Analytics rollups (repair window; observers handle hot paths when ANALYTICS_USE_ROLLUPS=true)
Schedule::call(function () {
    Artisan::call('analytics:rollup', [
        '--from' => now()->subDays(7)->toDateString(),
        '--to' => now()->toDateString(),
    ]);
})->hourly()->name('analytics-rollup-repair')->withoutOverlapping();
