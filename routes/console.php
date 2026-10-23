<?php

use App\Models\PortalMobileSession;
use App\Models\PortalNotification;
use App\Models\PortalOfflineAccount;
use App\Models\PortalOutboxItem;
use App\Models\PortalPushToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fallback queue workers, one per queue group, so keep-alive pings and
// uploads never wait behind a 30-minute archive crawl; production also runs
// long-lived Supervisor workers for the same groups (README, "Menjalankan di
// Server"). Both run in the background, so neither delays the other events
// of the tick. --max-time ends a busy worker after its current job, and the
// overlap lock expires just after the longest run (max-time + timeout): a
// worker killed mid-run (reboot, out of memory) holds its queues back for
// minutes instead of the default 24 hours. tests/Unit/QueueJobConfigurationTest
// and tests/Unit/ScheduledQueueWorkerTest check these numbers.

// Keep-alive pings and session resumes (180 s), uploads (the worker's 300 s):
// a run ends within 60 + 300 s.
Schedule::command('queue:work --queue=campus-sessions,campus-uploads --stop-when-empty --timeout=300 --tries=1 --max-time=60')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 7)
    ->runInBackground();

// Work a student is waiting on: snapshot refreshes (RefreshPortalDataJob:
// up to 600 s), notification scans and notebook ingestion. A run ends within
// 300 + 600 s.
Schedule::command('queue:work --queue=campus-refresh,campus-notifications,default --stop-when-empty --timeout=600 --tries=1 --max-time=300')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 16)
    ->runInBackground();

// Background archive and course crawls (SyncPortalArchiveJob: 1800 s), on
// their own worker so the work above never waits behind them. A run ends
// within 300 + 1800 s; --timeout stays below queue.connections.database.retry_after.
Schedule::command('queue:work --queue=campus-background --stop-when-empty --timeout=1800 --tries=1 --max-time=300')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 36)
    ->runInBackground();

// Notifications older than `portal.notifications.retention_days`, the
// stored credentials of offline accounts (see PortalOfflineAccount::prunable()),
// unused app tokens and push tokens of apps that stopped registering
// (`portal.mobile`), and settled outbox items older than
// `portal.outbox.retention_days`.
Schedule::command('model:prune', ['--model' => [PortalNotification::class, PortalOfflineAccount::class, PortalMobileSession::class, PortalPushToken::class, PortalOutboxItem::class]])->daily();

// Upload operations left unfinished by a web process that died mid-upload,
// and their orphaned temporary files.
Schedule::command('portal:recover-uploads')->everyFiveMinutes();

// Outbox items no session will send (an offline session that idled out
// before the portal came back): expired with a notification once they can no
// longer go out, else sent with the student's online session or a
// background login with the stored password (SendPortalOutboxJob).
Schedule::command('portal:send-outbox')->everyFiveMinutes()->withoutOverlapping(expiresAt: 5);

// Deadline and class reminders for students whose mobile app is closed,
// built from stored snapshots only (PortalAwayReminders); each run only
// queues one light job per student with a registered app install.
Schedule::command('portal:remind-away')->everyTenMinutes()->withoutOverlapping(expiresAt: 5);

// Background syncs of the archive for students whose last sync is due
// (`portal.background_sync`); each run queues one batch within seconds, so
// the lock of a killed run expires before the next one is due.
Schedule::command('portal:sync-archives')->everyFifteenMinutes()->withoutOverlapping(expiresAt: 10);
