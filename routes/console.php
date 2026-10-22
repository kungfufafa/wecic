<?php

use App\Models\PortalNotification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:work --queue=campus-sessions,campus-uploads,campus-refresh,campus-notifications,default --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping();

// Notifications older than `portal.notifications.retention_days`.
Schedule::command('model:prune', ['--model' => [PortalNotification::class]])->daily();

// Background syncs of the archive for students whose last sync is due
// (`portal.background_sync`); each run queues one batch.
Schedule::command('portal:sync-archives')->everyFifteenMinutes()->withoutOverlapping();
