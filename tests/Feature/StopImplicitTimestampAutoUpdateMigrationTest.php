<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalArchivedFile;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL and MariaDB (explicit_defaults_for_timestamp=OFF) turn a table's
 * first required TIMESTAMP column without a default into one that resets
 * itself on every update; the migration gives those columns an explicit
 * default there, and leaves every other database alone.
 */
class StopImplicitTimestampAutoUpdateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_sqlite_the_migration_runs_nothing_and_leaves_the_table_as_it_was(): void
    {
        $file = PortalArchivedFile::factory()->create(['archived_at' => '2026-10-20 08:00:00']);
        $tableBefore = $this->sqliteTableDefinition();
        $archivedAtBefore = $this->archivedAtColumn();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame([], $queries);
        $this->assertSame($tableBefore, $this->sqliteTableDefinition());
        $this->assertSame($archivedAtBefore, $this->archivedAtColumn());
        $this->assertNull($archivedAtBefore['default']);
        $this->assertFalse($archivedAtBefore['nullable']);
        $this->assertSame('2026-10-20 08:00:00', PortalArchivedFile::query()->whereKey($file->id)->toBase()->value('archived_at'));
    }

    public function test_on_mysql_the_archive_time_gets_an_explicit_default_instead_of_the_implicit_on_update(): void
    {
        $this->assertSame(
            ['alter table `portal_archived_files` modify `archived_at` timestamp not null default CURRENT_TIMESTAMP'],
            $this->statementsOn('mysql', 'up')
        );
        $this->assertSame(
            ['alter table `portal_archived_files` modify `archived_at` timestamp not null'],
            $this->statementsOn('mysql', 'down')
        );
    }

    public function test_on_mariadb_the_archive_time_gets_an_explicit_default_instead_of_the_implicit_on_update(): void
    {
        $this->assertSame(
            ['alter table `portal_archived_files` modify `archived_at` timestamp not null default CURRENT_TIMESTAMP'],
            $this->statementsOn('mariadb', 'up')
        );
        $this->assertSame(
            ['alter table `portal_archived_files` modify `archived_at` timestamp not null'],
            $this->statementsOn('mariadb', 'down')
        );
    }

    /**
     * The audit, kept: SQLite (where the migration does nothing) still shows
     * every required timestamp column without a default the migrations
     * create, and the archive time must stay the only one. A new one needs
     * a default or a place in the migration.
     */
    public function test_the_archive_time_is_the_only_required_timestamp_without_a_default(): void
    {
        $required = [];

        foreach (Schema::getTables() as $table) {
            foreach (Schema::getColumns($table['name']) as $column) {
                if (in_array(strtolower($column['type_name']), ['datetime', 'timestamp'], true) && ! $column['nullable'] && $column['default'] === null) {
                    $required[] = "{$table['name']}.{$column['name']}";
                }
            }
        }

        $this->assertSame(['portal_archived_files.archived_at'], $required);
    }

    public function test_on_postgresql_and_sql_server_the_migration_runs_nothing(): void
    {
        foreach (['pgsql', 'sqlsrv'] as $connection) {
            $this->assertSame([], $this->statementsOn($connection, 'up'), $connection);
            $this->assertSame([], $this->statementsOn($connection, 'down'), $connection);
        }
    }

    /**
     * The statements the migration would run on `$connection`, the way the
     * migrator runs it (as the default connection), but only pretended:
     * nothing connects, and the host is unroutable in case anything tried.
     *
     * @return list<string>
     */
    private function statementsOn(string $connection, string $method): array
    {
        config(["database.connections.{$connection}.host" => 'database.invalid']);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);

        try {
            $log = DB::connection($connection)->pretend(fn () => $this->migration()->{$method}());
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge($connection);
        }

        return array_column($log, 'query');
    }

    /**
     * @return array<string, mixed>
     */
    private function archivedAtColumn(): array
    {
        return collect(Schema::getColumns('portal_archived_files'))->firstWhere('name', 'archived_at');
    }

    private function sqliteTableDefinition(): string
    {
        return (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'portal_archived_files')->value('sql');
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_22_191948_stop_implicit_auto_update_of_required_timestamps.php');
    }
}
