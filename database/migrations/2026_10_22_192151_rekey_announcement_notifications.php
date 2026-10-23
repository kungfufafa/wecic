<?php

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications stored before announcements got stable ids link them by
 * their wording-based id: a new announcement by record key and link, a
 * deadline reminder by `announcement-<id>` and link. Once the announcement
 * leaves the feed or is edited, that id finds nothing. This runs
 * portal:rekey-announcement-notifications, which points every such
 * notification to the id its announcement goes by now, as each student's
 * archive and feed snapshot know it. Only the link and record key change
 * (never the dedupe key, so nothing is stored twice), and running it again
 * changes nothing.
 *
 * A student that could not be re-keyed does not fail the migration (their
 * notifications still follow when they are read or opened): the command's
 * output, which lists their owner keys, is logged so the command can be run
 * again for them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('portal_notifications') || ! Schema::hasTable('portal_archive_versions')) {
            return;
        }

        $linksAnnouncements = DB::table('portal_notifications')
            ->where(fn ($query) => $query->where('dataset', 'announcements')->orWhere('url', 'like', '/announcement/%'))
            ->exists();

        if (! $linksAnnouncements) {
            return;
        }

        if (Artisan::call('portal:rekey-announcement-notifications') !== Command::SUCCESS) {
            Log::warning('Some announcement notifications were not re-keyed; run portal:rekey-announcement-notifications again for the owners listed', [
                'output' => trim(Artisan::output()),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * The current ids open the same announcements; the old ids are not put back.
     */
    public function down(): void {}
};
