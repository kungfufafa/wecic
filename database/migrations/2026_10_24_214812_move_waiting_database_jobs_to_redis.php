<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The queues moved from the database to redis, which Horizon runs, and from
 * the campus-* names to one queue per worker: sessions, default and
 * background (config/horizon.php). Jobs still waiting in the jobs table
 * (delayed keep-alive pings, idle-session checks, scan follow-ups, pushes)
 * would never run, so this runs portal:move-old-queue-jobs, which moves
 * them, and failed jobs, to redis. Run the command again once the processes
 * still running the old code have stopped.
 *
 * It runs outside a transaction: a push to redis cannot be rolled back, so
 * the command commits each row it moves on its own.
 */
return new class extends Migration
{
    /**
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Artisan::call('portal:move-old-queue-jobs');
    }

    /**
     * Reverse the migrations.
     *
     * Moved jobs stay in redis; going back to the database queue would leave
     * them to a Horizon that no longer runs.
     */
    public function down(): void {}
};
