<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalOfflineException;
use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalDataArchive;
use App\Services\PortalDataCache;
use App\Services\PortalFetchPolicy;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `/api/class/{id}` without `dosen_id`/`course_id` resolves them from the
 * student's class list; a failing lookup is not reported as "not your class".
 */
class PortalClassDetailResolutionTest extends TestCase
{
    private const STORED_PARAMS = ['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012'];

    private const CURRENT_PARAMS = ['id' => '10021', 'dosen_id' => '6100', 'course_id' => '10012'];

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_expired_session_during_the_lookup_asks_the_student_to_log_in_again(): void
    {
        $this->mockValidCampusSession('token-expired-lookup');
        $this->mockClassListThrowing(PortalScrapingException::pageNotAccessible('sipandai dashboard'));

        $this->withSession(['campus_session_token' => 'token-expired-lookup'])
            ->getJson('/api/class/2265')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.')
            ->assertJsonPath('meta.campus_session_valid', false)
            ->assertSessionMissing('campus_session_token');
    }

    public function test_an_offline_session_during_the_lookup_reports_the_offline_archive(): void
    {
        $this->mockValidCampusSession('token-offline-lookup');
        $this->mockClassListThrowing(PortalOfflineException::offlineSession());

        $this->withSession(['campus_session_token' => 'token-offline-lookup'])
            ->getJson('/api/class/2265')
            ->assertStatus(503)
            ->assertJsonPath('meta.offline', true);
    }

    public function test_a_class_missing_from_the_class_list_is_still_not_found(): void
    {
        $this->mockValidCampusSession('token-missing-class');

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->andReturn(['classes' => []]);
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractClassDetail');
        }));

        $this->withSession(['campus_session_token' => 'token-missing-class'])
            ->getJson('/api/class/2265')
            ->assertNotFound()
            ->assertJsonPath('message', 'Kelas tidak ditemukan di daftar kelas kamu.');
    }

    public function test_a_portal_failure_during_the_lookup_is_still_not_found(): void
    {
        $this->mockValidCampusSession('token-lookup-failure');
        $this->mockClassListThrowing(PortalScrapingException::dataExtractionFailed('classes', 'timeout'));

        $this->withSession(['campus_session_token' => 'token-lookup-failure'])
            ->getJson('/api/class/2265')
            ->assertNotFound();
    }

    public function test_a_fresh_stored_class_list_resolves_the_parameters_without_the_live_catalog(): void
    {
        $this->mockValidCampusSession('token-stored-list');

        app(PortalSnapshotStore::class)->put('token-stored-list', 'lms_classes', [
            'classes' => [
                ['join_link' => ['params' => ['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012']]],
            ],
        ]);

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractSipandaiClassesData');
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->with('token-stored-list', ['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012'])
                ->andReturn(['content_type' => 'class_info', 'course_info' => ['name' => 'Data Governance']]);
        }));

        $this->withSession(['campus_session_token' => 'token-stored-list'])
            ->getJson('/api/class/10021')
            ->assertOk()
            ->assertJsonPath('class_detail.course_info.name', 'Data Governance')
            ->assertJsonPath('meta.parameters.course_id', '10012');
    }

    public function test_a_stale_stored_class_list_is_checked_against_the_live_catalog_while_online(): void
    {
        $this->mockValidCampusSession('token-stale-online');
        $this->storeStaleClassList('token-stale-online');

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->andReturnUsing(function (): array {
                    $this->assertFalse(app(PortalFetchPolicy::class)->forcesFresh(), 'A read that is not forced keeps the resource caches.');

                    return ['classes' => [['join_link' => ['params' => self::CURRENT_PARAMS]]]];
                });
        }));
        $this->expectClassPageFor('6100');

        $this->withSession(['campus_session_token' => 'token-stale-online'])
            ->getJson('/api/class/10021')
            ->assertOk()
            ->assertJsonPath('meta.parameters', self::CURRENT_PARAMS);
    }

    public function test_a_stale_stored_class_list_answers_when_the_live_catalog_times_out(): void
    {
        $this->mockValidCampusSession('token-stale-timeout');
        $this->storeStaleClassList('token-stale-timeout');

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->andThrow(PortalScrapingException::dataExtractionFailed('classes', 'cURL error 28: Operation timed out'));
        }));
        $this->expectClassPageFor('5240');

        $this->withSession(['campus_session_token' => 'token-stale-timeout'])
            ->getJson('/api/class/10021')
            ->assertOk()
            ->assertJsonPath('meta.parameters', self::STORED_PARAMS);
    }

    public function test_a_stale_stored_class_list_answers_when_the_live_catalog_does_not_list_the_class(): void
    {
        $this->mockValidCampusSession('token-stale-unlisted');
        $this->storeStaleClassList('token-stale-unlisted');

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->andReturn(['classes' => []]);
        }));
        $this->expectClassPageFor('5240');

        $this->withSession(['campus_session_token' => 'token-stale-unlisted'])
            ->getJson('/api/class/10021')
            ->assertOk()
            ->assertJsonPath('meta.parameters', self::STORED_PARAMS);
    }

    public function test_a_class_list_restored_from_the_archive_is_as_stale_as_when_the_portal_last_showed_it(): void
    {
        $this->mockValidCampusSession('token-archived-list');
        $this->partialMock(PortalDataArchive::class, function (MockInterface $mock): void {
            $mock->shouldReceive('restore')->andReturnUsing(static fn (string $token, string $dataset): ?array => $dataset === 'classes' ? [
                'data' => ['classes' => [['join_link' => ['params' => self::STORED_PARAMS]]]],
                'seen_at' => now()->subHours(3)->toISOString(),
                'integrity' => null,
            ] : null);
            $mock->shouldReceive('record')->andReturnUsing(static fn (string $token, string $dataset, array $params, array $data): array => [
                'data' => $data,
                'integrity' => null,
            ]);
        });

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->andReturn(['classes' => [['join_link' => ['params' => self::CURRENT_PARAMS]]]]);
        }));
        $this->expectClassPageFor('6100');

        $this->withSession(['campus_session_token' => 'token-archived-list'])
            ->getJson('/api/class/10021')
            ->assertOk()
            ->assertJsonPath('meta.parameters', self::CURRENT_PARAMS);
    }

    public function test_an_offline_session_answers_from_a_stale_stored_class_list_without_the_portal(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->with('token-stale-offline')->andReturnTrue();
                $mock->shouldReceive('getSession')->with('token-stale-offline')->andReturn(['offline' => true]);
            })
        );
        $this->storeStaleClassList('token-stale-offline');
        app(PortalSnapshotStore::class)->put('token-stale-offline', 'class_detail', [
            'content_type' => 'class_info',
            'course_info' => ['name' => 'Data Governance'],
        ], now()->subDay()->toISOString(), self::STORED_PARAMS);

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractSipandaiClassesData');
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractClassDetail');
        }));

        $this->withSession(['campus_session_token' => 'token-stale-offline'])
            ->getJson('/api/class/10021')
            ->assertOk()
            ->assertJsonPath('class_detail.course_info.name', 'Data Governance')
            ->assertJsonPath('meta.status', 'offline')
            ->assertJsonPath('meta.parameters', self::STORED_PARAMS);
    }

    public function test_a_forced_read_resolves_the_parameters_from_the_portal_instead_of_the_stored_list(): void
    {
        $this->mockValidCampusSession('token-fresh-lookup');
        $staleParams = ['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012'];
        $currentParams = ['id' => '10021', 'dosen_id' => '6100', 'course_id' => '10012'];

        app(PortalSnapshotStore::class)->put('token-fresh-lookup', 'lms_classes', [
            'classes' => [['join_link' => ['params' => $staleParams]]],
        ]);
        app(PortalDataCache::class)->remember('token-fresh-lookup', 'class_catalog', [], static fn (): array => [
            'by_id' => ['10021' => $staleParams],
            'by_course_id' => ['10012' => $staleParams],
        ]);

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock) use ($currentParams): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->andReturnUsing(function () use ($currentParams): array {
                    $this->assertTrue(app(PortalFetchPolicy::class)->forcesFresh(), 'The class list is re-read, not served from its cache.');

                    return ['classes' => [['join_link' => ['params' => $currentParams]]]];
                });
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->withArgs(static fn (string $token, array $params): bool => $params['dosen_id'] === '6100')
                ->andReturn(['content_type' => 'class_info', 'course_info' => ['name' => 'Data Governance']]);
        }));

        $this->withSession(['campus_session_token' => 'token-fresh-lookup'])
            ->getJson('/api/class/10021?fresh=1')
            ->assertOk()
            ->assertJsonPath('meta.parameters.dosen_id', '6100');
    }

    public function test_a_forced_read_falls_back_to_the_stored_list_when_the_portal_is_offline(): void
    {
        $this->mockValidCampusSession('token-fresh-offline');

        app(PortalSnapshotStore::class)->put('token-fresh-offline', 'lms_classes', [
            'classes' => [['join_link' => ['params' => ['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012']]]],
        ]);

        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->andThrow(PortalOfflineException::offlineSession());
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->withArgs(static fn (string $token, array $params): bool => $params['dosen_id'] === '5240')
                ->andReturn(['content_type' => 'class_info', 'course_info' => ['name' => 'Data Governance']]);
        }));

        $this->withSession(['campus_session_token' => 'token-fresh-offline'])
            ->getJson('/api/class/10021?fresh=1')
            ->assertOk()
            ->assertJsonPath('meta.parameters.dosen_id', '5240');
    }

    public function test_an_empty_class_page_for_supplied_parameters_is_not_found(): void
    {
        $this->mockValidCampusSession('token-empty-class');

        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->andReturn([
                    'content_type' => 'class_info',
                    'course_info' => ['name' => '', 'code' => ''],
                    'course_materials' => [],
                    'attendance_info' => ['meetings' => []],
                    'found' => false,
                ]);
        }));

        $this->withSession(['campus_session_token' => 'token-empty-class'])
            ->getJson('/api/class/123?dosen_id=5240&course_id=8810')
            ->assertNotFound()
            ->assertJsonPath('message', 'Kelas tidak ditemukan. Periksa kembali link yang kamu buka.')
            ->assertJsonPath('meta.campus_session_valid', true);
    }

    /**
     * The class list as stored two hours ago, past its `fresh_for`
     */
    private function storeStaleClassList(string $token): void
    {
        app(PortalSnapshotStore::class)->put($token, 'lms_classes', [
            'classes' => [['join_link' => ['params' => self::STORED_PARAMS]]],
        ], now()->subHours(2)->toISOString());
    }

    private function expectClassPageFor(string $dosenId): void
    {
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock) use ($dosenId): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->withArgs(static fn (string $token, array $params): bool => $params['dosen_id'] === $dosenId)
                ->andReturn(['content_type' => 'class_info', 'course_info' => ['name' => 'Data Governance']]);
        }));
    }

    private function mockClassListThrowing(\Throwable $throwable): void
    {
        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock) use ($throwable): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->andThrow($throwable);
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractClassDetail');
        }));
    }

    private function mockValidCampusSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}
