<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The queues went from campus-sessions, campus-refresh,
 * campus-notifications, default and campus-background (campus-uploads
 * before that) to one per worker: sessions, default and background
 * (routes/console.php). A worker only takes rows on a queue it listens to,
 * so jobs still waiting under an old name (delayed keep-alive pings,
 * idle-session checks, scan follow-ups, pushes) would never run. This runs
 * portal:move-old-queue-jobs, which moves them and failed jobs too; run the
 * command again once the workers still running the old code have stopped.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Artisan::call('portal:move-old-queue-jobs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Artisan::call('portal:move-old-queue-jobs', ['--back' => true]);
    }
};
