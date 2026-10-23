<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalArchiveVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPortalArchiveSeenTimesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = ['first_seen_at', 'last_seen_at', 'trusted_at'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Jakarta']);
    }

    public function test_times_record_stored_as_utc_wall_clock_move_to_the_app_timezone(): void
    {
        $touchedBeforeTheFix = $this->version([
            'first_seen_at' => '2026-10-22 01:00:00', 'last_seen_at' => '2026-10-22 02:27:00', 'trusted_at' => '2026-10-22 01:00:00',
            'created_at' => '2026-10-22 08:00:04', 'updated_at' => '2026-10-22 09:27:03',
        ]);
        $dismissedLater = $this->version([
            'status' => PortalArchiveVersion::STATUS_DISMISSED,
            'first_seen_at' => '2026-10-22 02:00:00', 'last_seen_at' => '2026-10-22 02:00:00', 'trusted_at' => null,
            'resolved_at' => '2026-10-22 16:30:00', 'created_at' => '2026-10-22 09:00:02', 'updated_at' => '2026-10-22 16:30:00',
        ]);
        $acceptedLater = $this->version([
            'first_seen_at' => '2026-10-22 03:00:00', 'last_seen_at' => '2026-10-22 03:00:00', 'trusted_at' => '2026-10-22 10:20:00',
            'resolved_at' => '2026-10-22 10:20:00', 'created_at' => '2026-10-22 10:00:02', 'updated_at' => '2026-10-22 10:20:00',
        ]);
        $seenAgainAfterTheFix = $this->version([
            'first_seen_at' => '2026-10-22 01:00:00', 'last_seen_at' => '2026-10-22 12:00:00', 'trusted_at' => '2026-10-22 01:00:00',
            'created_at' => '2026-10-22 08:00:02', 'updated_at' => '2026-10-22 12:00:01',
        ]);

        $this->runRepair();

        $this->assertSame(['2026-10-22 08:00:00', '2026-10-22 09:27:00', '2026-10-22 08:00:00'], $this->times($touchedBeforeTheFix));
        $this->assertSame(['2026-10-22 09:00:00', '2026-10-22 09:00:00', null], $this->times($dismissedLater));
        $this->assertSame(['2026-10-22 10:00:00', '2026-10-22 10:00:00', '2026-10-22 10:20:00'], $this->times($acceptedLater));
        $this->assertSame(['2026-10-22 08:00:00', '2026-10-22 12:00:00', '2026-10-22 08:00:00'], $this->times($seenAgainAfterTheFix));
        $this->assertSame('2026-10-22 09:27:03', PortalArchiveVersion::query()->whereKey($touchedBeforeTheFix)->toBase()->value('updated_at'));
    }

    public function test_times_already_in_the_app_timezone_are_left_alone(): void
    {
        $recordedAfterTheFix = $this->version([
            'first_seen_at' => '2026-10-22 10:00:00', 'last_seen_at' => '2026-10-22 11:00:00', 'trusted_at' => '2026-10-22 10:00:00',
            'created_at' => '2026-10-22 10:00:03', 'updated_at' => '2026-10-22 11:00:02',
        ]);
        $dismissedSevenHoursAfterItWasSeen = $this->version([
            'status' => PortalArchiveVersion::STATUS_DISMISSED,
            'first_seen_at' => '2026-10-22 02:00:00', 'last_seen_at' => '2026-10-22 02:00:00', 'trusted_at' => null,
            'resolved_at' => '2026-10-22 09:05:00', 'created_at' => '2026-10-22 02:00:03', 'updated_at' => '2026-10-22 09:05:00',
        ]);

        $this->runRepair();

        $this->assertSame(['2026-10-22 10:00:00', '2026-10-22 11:00:00', '2026-10-22 10:00:00'], $this->times($recordedAfterTheFix));
        $this->assertSame(['2026-10-22 02:00:00', '2026-10-22 02:00:00', null], $this->times($dismissedSevenHoursAfterItWasSeen));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $version = $this->version([
            'first_seen_at' => '2026-10-22 01:00:00', 'last_seen_at' => '2026-10-22 02:27:00', 'trusted_at' => '2026-10-22 01:00:00',
            'created_at' => '2026-10-22 08:00:04', 'updated_at' => '2026-10-22 09:27:03',
        ]);

        $this->runRepair();
        $repaired = $this->times($version);
        $this->runRepair();

        $this->assertSame(['2026-10-22 08:00:00', '2026-10-22 09:27:00', '2026-10-22 08:00:00'], $repaired);
        $this->assertSame($repaired, $this->times($version));
    }

    public function test_nothing_changes_when_the_app_timezone_is_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $version = $this->version([
            'first_seen_at' => '2026-10-22 01:00:00', 'last_seen_at' => '2026-10-22 02:27:00', 'trusted_at' => '2026-10-22 01:00:00',
            'created_at' => '2026-10-22 01:00:04', 'updated_at' => '2026-10-22 02:27:03',
        ]);

        $this->runRepair();

        $this->assertSame(['2026-10-22 01:00:00', '2026-10-22 02:27:00', '2026-10-22 01:00:00'], $this->times($version));
    }

    /**
     * @param  array<string, string|null>  $columns  Stored as given, the way the database holds them.
     */
    private function version(array $columns): int
    {
        $version = PortalArchiveVersion::factory()->create();
        PortalArchiveVersion::query()->whereKey($version->id)->toBase()->update(['resolved_at' => null, ...$columns]);

        return $version->id;
    }

    /**
     * @return list<string|null>
     */
    private function times(int $id): array
    {
        $row = PortalArchiveVersion::query()->whereKey($id)->toBase()->first(self::COLUMNS);

        return array_map(static fn (string $column): ?string => $row->{$column}, self::COLUMNS);
    }

    private function runRepair(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_22_164210_repair_portal_archive_version_seen_times.php');
        $migration->up();
    }
}
