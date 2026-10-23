<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL before 8.0.2 and MariaDB before 10.10 run with
 * explicit_defaults_for_timestamp=OFF by default. A table's first TIMESTAMP
 * column declared NOT NULL without a default then silently gets DEFAULT
 * CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, so every update of the row
 * rewrites it to now. `portal_archived_files.archived_at` is such a column:
 * any update of an archived file that does not set it itself, such as the
 * move of a student's files to their current owner key (PortalOwnerRekeyer),
 * reset the archive time of every file it touched.
 *
 * An explicit default stops that, as
 * 2026_10_22_165310_stop_auto_update_of_last_online_login_at_on_portal_offline_accounts_table
 * did for `portal_offline_accounts.last_online_login_at`: MySQL only adds
 * the implicit attributes to a column declared without NULL, DEFAULT and
 * ON UPDATE, and MODIFY replaces the whole column definition, so an
 * ON UPDATE clause already added goes with it. The default itself is never
 * used: PortalArchivedFileStore always sets `archived_at`.
 *
 * Every other TIMESTAMP column the migrations create was checked and needs
 * nothing: `portal_offline_accounts.last_online_login_at` is fixed already;
 * `failed_jobs.failed_at` has a default; the rest are nullable (timestamps()
 * on users, notebooks, notebook_sources, notebook_messages and
 * notebook_dismissals; the *_seen_at, trusted_at and resolved_at of
 * portal_archive_versions; created_at and read_at of portal_notifications;
 * last_seen_at of exam_submission_records; queued_at and requested_at of
 * notebook_sources; the background sync times of portal_offline_accounts),
 * which MySQL leaves alone; the queue, job batch and cache tables keep their
 * times as integers.
 *
 * Only MySQL and MariaDB add the implicit clause, so on SQLite (where
 * change() would rebuild the table), PostgreSQL and SQL Server the
 * migration does nothing at all.
 */
return new class extends Migration
{
    /**
     * NOT NULL TIMESTAMP columns without a default that are their table's
     * first TIMESTAMP column, by table.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'portal_archived_files' => ['archived_at'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->addsImplicitTimestampAttributes()) {
            return;
        }

        foreach (self::COLUMNS as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->timestamp($column)->useCurrent()->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->addsImplicitTimestampAttributes()) {
            return;
        }

        foreach (self::COLUMNS as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->timestamp($column)->change();
                }
            });
        }
    }

    /**
     * Whether the connection's database gives a required TIMESTAMP column
     * implicit DEFAULT / ON UPDATE CURRENT_TIMESTAMP attributes.
     */
    private function addsImplicitTimestampAttributes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
