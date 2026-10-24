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

// Horizon (config/horizon.php) runs the queues; its dashboard graphs come
// from these snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

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
