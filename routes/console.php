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
