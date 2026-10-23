<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Portal pages a dashboard re-sync reads, counted on the real extractors
 * (only the HTTP transport answers from fixtures).
 *
 * Today's schedule used to read, besides its list pages, the class page and
 * the meeting page of every class held today, one request a second in a
 * queue worker: four pages plus two per class (six on the fixture day
 * below, sixteen on a day of six classes) before the schedule was stored.
 * It now reads the four list pages; the class and meeting pages are the
 * attendance snapshot's own build.
 */
class DashboardPortalPageBudgetTest extends TestCase
{
    private const TOKEN = 'token-page-budget';

    /**
     * @var list<string>
     */
    private array $requestedPages = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.request_delay' => 0]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldIgnoreMissing();
        }));
        $this->servePortalFixtures();

        // Wednesday: the fixture schedule has one class (ERP, 07:30-09:30) and
        // its class page lists today's meeting.
        $this->travelTo(CarbonImmutable::create(2025, 11, 5, 8, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_todays_schedule_is_rebuilt_from_its_list_pages_only(): void
    {
        $data = app(PortalDatasetRepository::class)->sync(self::TOKEN, 'dashboard');

        $this->assertSame(['profile', 'dashboardmahasiswa', 'course', 'credit'], $this->requestedPages);
        $this->assertSame(1, $data['classes_summary']['today_classes_count']);
        $this->assertSame('SIE053004', $data['classes_summary']['today_classes'][0]['course_code']);
        $this->assertSame('2025-11-05', $data['classes_summary']['date']);
    }

    public function test_todays_attendance_reads_the_class_and_meeting_pages_in_its_own_build(): void
    {
        $data = app(PortalDatasetRepository::class)->sync(self::TOKEN, 'dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS);

        $this->assertSame(['dashboardmahasiswa', 'download#2343', 'discuss#30061'], $this->requestedPages);
        $this->assertSame('2025-11-05', $data['date']);
        $this->assertSame('30061', $data['classes'][0]['meeting_id']);
        $this->assertTrue($data['classes'][0]['attendance_target_reliable']);
    }

    public function test_the_dashboard_joins_both_snapshots_without_reading_the_portal(): void
    {
        Bus::fake([RefreshPortalDataJob::class]);
        app(PortalDatasetRepository::class)->sync(self::TOKEN, 'dashboard');
        // Each re-sync runs in its own queue job, with its own page memo and fetch policy.
        $this->app->forgetScopedInstances();
        app(PortalDatasetRepository::class)->sync(self::TOKEN, 'dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS);
        $pagesBeforeRead = count($this->requestedPages);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.course_code', 'SIE053004')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.state', 'ready')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.meeting_id', '30061')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.target_reliable', true);

        $this->assertSame(7, $pagesBeforeRead);
        $this->assertCount($pagesBeforeRead, $this->requestedPages);
    }

    /**
     * Answer every portal request with the fixture page of its `act`, and
     * record it as `act` or `act#id`.
     */
    private function servePortalFixtures(): void
    {
        $pages = [
            'profile' => 'tests/Fixtures/portal/profile.html',
            'dashboardmahasiswa' => 'tests/Fixtures/portal/sipandai_dashboard.html',
            'course' => 'tests/Fixtures/portal/student_course.html',
            'credit' => 'tests/Fixtures/portal/student_credit.html',
            'download' => 'tests/Fixtures/portal/sipandai_course_detail.html',
            'discuss' => 'tests/Fixtures/portal/meeting_assignment_detail.html',
        ];

        $this->app->resolving(PortalScrapingService::class, function (PortalScrapingService $service) use ($pages): void {
            $client = (new \ReflectionProperty(PortalScrapingService::class, 'client'))->getValue($service);
            $handler = $client instanceof Client ? $client->getConfig('handler') : null;

            if (! $handler instanceof HandlerStack) {
                return;
            }

            $handler->setHandler(function (RequestInterface $request) use ($pages): PromiseInterface {
                parse_str($request->getUri()->getQuery(), $query);
                $act = is_string($query['act'] ?? null) ? $query['act'] : '';
                $this->requestedPages[] = $act.(is_string($query['id'] ?? null) ? '#'.$query['id'] : '');

                $this->assertArrayHasKey($act, $pages, "Unexpected portal page: {$request->getUri()}");

                return Create::promiseFor(new Response(200, [], (string) file_get_contents(base_path($pages[$act]))));
            });
        });
    }
}
