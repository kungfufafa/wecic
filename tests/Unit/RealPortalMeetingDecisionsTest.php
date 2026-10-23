<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\RefreshPortalDataJob;
use App\Services\ActionCenterBuilder;
use App\Services\AttendanceShortcutService;
use App\Services\DashboardTodayClassesBuilder;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalActionProjector;
use App\Services\PortalArchiveGuard;
use App\Services\PortalClassCatalog;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalMeetingDetailResolver;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * What MyCIC decides from real, sanitized meeting and assignment pages
 * (tests/Fixtures/portal/real): the assignment status of a meeting, its
 * upload target, the attendance it shows and the pending assignment it
 * becomes, at the capture's moment, Friday 23 October 2026, 10:00 WIB.
 *
 * The class page was not captured: where a decision needs its upload
 * history, the history holds the 23 October ground truth (Data Governance
 * has no upload, AI for Web Design Advance meetings 1-4 are uploaded).
 */
class RealPortalMeetingDecisionsTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURES = 'tests/Fixtures/portal/real/';

    private const TOKEN = 'token-real';

    private const DATA_GOVERNANCE_CLASS = ['id' => '5391', 'dosen_id' => '5933', 'course_id' => '9496'];

    private const WEB_DESIGN_CLASS = ['id' => '5389', 'dosen_id' => '6584', 'course_id' => '9525'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.request_delay' => 0]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 23, 10, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_open_data_governance_assignment_is_not_uploaded(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->with(self::TOKEN, '9496')->andReturn(self::DATA_GOVERNANCE_CLASS);
        });
        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('inspectAssignmentSubmissionState');
        });

        $detail = (new PortalMeetingDetailResolver(
            $this->meetingExtractor(36365, 9496, self::DATA_GOVERNANCE_CLASS, []),
            $catalog,
            $submissionService
        ))->resolve(self::TOKEN, '36365', '9496');

        $this->assertSame('not_uploaded', $detail['assignment_info']['status']);
        $this->assertSame('3', $detail['assignment_info']['meeting_number']);
        $this->assertSame('?mod=sipandai&act=send&courses_id=9496&id=36365&pertemuan=3', $detail['assignment_info']['upload_link']);
        $this->assertArrayNotHasKey('delete_payload', $detail['assignment_info']);
        $this->assertSame('/class/5391?dosen_id=5933&course_id=9496', $detail['class_url']);
    }

    /**
     * The closed meeting page has no upload link: the meeting number comes
     * from "Instruksi Tugas Perkuliahan Pertemuan 4", and the uploaded file
     * from the real act=send page, which offers no delete link any more.
     */
    public function test_the_uploaded_web_design_assignment_shows_the_file_from_its_real_upload_page(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->with(self::TOKEN, '9525')->andReturn(self::WEB_DESIGN_CLASS);
        });
        $history = [];
        $submissionService = $this->submissionServiceAnswering([new Response(200, [], $this->page('assignment_form_36783_9525_4.html'))], $history);
        $uploadHistory = array_map(static fn (int $meeting): array => ['meeting' => (string) $meeting, 'file_info' => null], [1, 2, 3, 4]);

        $detail = (new PortalMeetingDetailResolver(
            $this->meetingExtractor(36783, 9525, self::WEB_DESIGN_CLASS, $uploadHistory),
            $catalog,
            $submissionService
        ))->resolve(self::TOKEN, '36783', '9525');

        $this->assertSame('uploaded', $detail['assignment_info']['status']);
        $this->assertSame('4', $detail['assignment_info']['meeting_number']);
        $this->assertSame('', $detail['assignment_info']['upload_link']);
        $this->assertSame(
            ['href' => '../files/tugasmhs/9525-4-20990000001-1dd.pdf', 'text' => 'Open File'],
            $detail['assignment_info']['submission']['file_info']
        );
        $this->assertArrayNotHasKey('delete_payload', $detail['assignment_info']);
        $this->assertSame('/class/5389?dosen_id=6584&course_id=9525', $detail['class_url']);
        $this->assertCount(1, $history);
        $this->assertSame(
            PortalEndpointRegistry::url('assignment_form', ['courses_id' => 9525, 'id' => 36783, 'pertemuan' => 4]),
            (string) $history[0]['request']->getUri()
        );
    }

    public function test_the_missed_data_governance_meeting_leads_back_to_its_class_without_an_assignment_status(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('findByCourseId');
        });

        $detail = (new PortalMeetingDetailResolver(
            $this->meetingExtractor(36633, 9496),
            $catalog,
            Mockery::mock(SipandaiSubmissionService::class)
        ))->resolve(self::TOKEN, '36633', '9496');

        $this->assertSame(self::DATA_GOVERNANCE_CLASS, $detail['meeting_info']['class_ref']);
        $this->assertSame('/class/5391?dosen_id=5933&course_id=9496', $detail['class_url']);
        $this->assertArrayNotHasKey('status', $detail['assignment_info']);
        $this->assertSame('Rabu, 14 Oktober 2026', $detail['schedule_info']['date']);
    }

    public function test_the_upload_target_comes_from_the_real_upload_link_and_a_meeting_without_assignment_has_none(): void
    {
        $uploadPayload = fn (int $meetingId, int $coursesId): ?array => $this->attendanceShortcutService($meetingId, $coursesId)
            ->resolveAssignmentUploadPayload(self::TOKEN, $meetingId, $coursesId);

        $this->assertSame(['courses_id' => '9496', 'id' => '36365', 'pertemuan' => 3], $uploadPayload(36365, 9496));
        $this->assertSame(['courses_id' => '9525', 'id' => '37082', 'pertemuan' => 5], $uploadPayload(37082, 9525));
        $this->assertNull($uploadPayload(36633, 9496));
        $this->assertNull($uploadPayload(36957, 9496));
    }

    public function test_attendance_is_recorded_only_where_the_real_page_shows_an_attendance_time(): void
    {
        $attendance = fn (int $meetingId, int $coursesId): array => $this->invokeNonPublicMethod(
            $this->attendanceShortcutService($meetingId, $coursesId),
            'resolveMeetingAttendanceStatus',
            [$this->extractMeeting($meetingId, $coursesId), CarbonImmutable::now('Asia/Jakarta'), null]
        );
        $notAttended = ['already_marked' => false, 'status' => 'unavailable', 'label' => 'Tidak hadir', 'time' => null, 'window' => 'closed'];

        // Data Governance P4 (Rabu, 14 Oktober 2026) was held without the student: the window is gone, not an "absen" text.
        $this->assertSame($notAttended, $attendance(36633, 9496));
        $this->assertSame($notAttended, $attendance(36365, 9496));
        $this->assertSame($notAttended, $attendance(36783, 9525));
        $this->assertSame(['already_marked' => true, 'status' => 'present', 'label' => 'Hadir', 'time' => '19:36:34', 'window' => null], $attendance(36342, 9521));
        $this->assertSame(['already_marked' => true, 'status' => 'present', 'label' => 'Hadir', 'time' => '19:20:42', 'window' => null], $attendance(37082, 9525));
    }

    public function test_the_missed_meetings_window_dated_next_week_is_not_open_yet(): void
    {
        $meetingDetail = $this->meetingExtractorAnswering($this->redatedMeetingPage('Rabu, 28 Oktober 2026'))
            ->extractMeetingDetail(self::TOKEN, ['meeting_id' => '36633', 'courses_id' => '9496']);

        $status = $this->invokeNonPublicMethod(
            $this->attendanceShortcutService(36633, 9496),
            'resolveMeetingAttendanceStatus',
            [$meetingDetail, CarbonImmutable::now('Asia/Jakarta'), null]
        );

        $this->assertSame(['already_marked' => false, 'status' => 'unavailable', 'label' => 'Belum dibuka', 'time' => null, 'window' => 'not_open'], $status);
    }

    /**
     * The missed meeting's real page dated today (in memory), for a class
     * held 13:00-15:00: before the class the dashboard still offers the
     * form and says the portal has not opened attendance; after it, the
     * student was not recorded and no form is offered.
     */
    public function test_todays_class_without_an_attendance_window_is_not_open_before_and_not_attended_after_it(): void
    {
        $before = $this->todayShortcut(CarbonImmutable::create(2026, 10, 23, 10, 0, 0, 'Asia/Jakarta'));
        $after = $this->todayShortcut(CarbonImmutable::create(2026, 10, 23, 16, 0, 0, 'Asia/Jakarta'));

        $this->assertSame(['already_marked' => false, 'status' => 'unavailable', 'label' => 'Belum dibuka', 'time' => null, 'window' => 'not_open'], $before['attendance_status']);
        $this->assertSame(['already_marked' => false, 'status' => 'unavailable', 'label' => 'Tidak hadir', 'time' => null, 'window' => 'closed'], $after['attendance_status']);

        $dashboard = app(DashboardTodayClassesBuilder::class);
        $todayClasses = ['date' => '2026-10-23', 'today_classes' => [['class_id' => '5391', 'dosen_id' => '5933', 'course_id' => '9496', 'course_code' => 'DS052002']]];
        $beforeAttendance = $dashboard->attachAttendance($todayClasses, ['date' => '2026-10-23', 'classes' => [$before]], 'synced')['today_classes'][0]['attendance'];
        $afterAttendance = $dashboard->attachAttendance($todayClasses, ['date' => '2026-10-23', 'classes' => [$after]], 'synced')['today_classes'][0]['attendance'];

        $this->assertTrue($beforeAttendance['actionable']);
        $this->assertSame('not_open', $beforeAttendance['status']['window']);
        $this->assertFalse($afterAttendance['actionable']);
        $this->assertSame('Tidak hadir', $afterAttendance['status']['label']);
    }

    public function test_attendance_sent_through_mycic_replaces_the_real_pages_unavailable_window_but_never_a_recorded_one(): void
    {
        $projector = app(PortalActionProjector::class);
        $missed = $this->extractMeeting(36633, 9496);
        $attended = $this->extractMeeting(36342, 9521);

        $projector->recordAttendance(self::TOKEN, 9496, 36633, 4);
        $projector->recordAttendance(self::TOKEN, 9521, 36342, 3);

        $projected = $projector->apply(self::TOKEN, 'meeting_detail', ['meeting_id' => '36633', 'courses_id' => '9496'], $missed);
        $this->assertSame('Hadir', $projected['attendance_info']['status']);
        $this->assertSame('present', $projected['attendance_info']['state']);

        $this->assertSame($attended, $projector->apply(self::TOKEN, 'meeting_detail', ['meeting_id' => '36342', 'courses_id' => '9521'], $attended));
    }

    public function test_the_archive_flags_a_recorded_attendance_that_turns_into_an_unavailable_window(): void
    {
        $attended = $this->extractMeeting(36342, 9521);
        $glitched = $attended;
        $glitched['attendance_info'] = $this->extractMeeting(36633, 9496)['attendance_info'];

        $findings = app(PortalArchiveGuard::class)->inspect('meeting_detail', $attended, $glitched);

        $this->assertSame([[
            'rule' => 'field',
            'label' => 'Status presensi pertemuan',
            'message' => 'Status presensi pertemuan berubah dari Hadir menjadi Absensi tidak tersedia.',
        ]], $findings);
        $this->assertSame([], app(PortalArchiveGuard::class)->inspect('meeting_detail', $glitched, $attended), 'A window that opens into presence is no loss.');
    }

    /**
     * Today's attendance shortcut of a Data Governance class held 13:00-15:00
     * on Friday 23 October 2026, from the missed meeting's real page dated
     * that day. The class page was not captured: it lists that meeting as
     * today's material.
     *
     * @return array<string, mixed>
     */
    private function todayShortcut(CarbonImmutable $reference): array
    {
        $extractor = $this->meetingExtractorAnswering($this->redatedMeetingPage('Jumat, 23 Oktober 2026'));
        $extractor->shouldReceive('extractClassDetail')
            ->with(self::TOKEN, self::DATA_GOVERNANCE_CLASS)
            ->andReturn([
                'course_materials' => [[
                    'title' => 'Pertemuan 4',
                    'meeting_number' => 4,
                    'date' => 'Jumat, 23 Oktober 2026',
                    'join_link' => ['href' => '?mod=sipandai&act=discuss&id=36633&courses_id=9496', 'text' => 'Join Pertemuan 4'],
                ]],
                'attendance_info' => ['meetings' => []],
            ]);

        $shortcuts = (new AttendanceShortcutService($extractor, Mockery::mock(SipandaiClassesExtractor::class)))->getTodayShortcuts(self::TOKEN, $reference, [[
            'course_info' => ['code' => 'DS052002', 'name' => 'Data Governance'],
            'lecturer_info' => [],
            'schedule' => ['day' => 'Jumat', 'start_time' => '13:00:00', 'end_time' => '15:00:00', 'formatted_time' => '13:00 - 15:00'],
            'join_link' => ['params' => self::DATA_GOVERNANCE_CLASS],
        ]]);

        $this->assertCount(1, $shortcuts['classes']);
        $this->assertTrue($shortcuts['classes'][0]['attendance_target_reliable']);

        return $shortcuts['classes'][0];
    }

    private function redatedMeetingPage(string $date): string
    {
        $page = str_replace('Rabu, 14 Oktober 2026</b>', $date.'</b>', $this->page('meeting_36633_9496.html'), $replaced);
        $this->assertSame(1, $replaced);

        return $page;
    }

    /**
     * ClassesExtractor answering every page request with the given HTML.
     */
    private function meetingExtractorAnswering(string $html): ClassesExtractor&MockInterface
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(ClassesExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor;
    }

    public function test_a_real_meeting_becomes_a_pending_assignment_only_with_an_assignment(): void
    {
        $dueToday = $this->pendingAssignment(36365, 9496, 3, 'Data Governance');
        $closed = $this->pendingAssignment(36620, 9521, 4, 'Manajemen Proyek');

        $this->assertSame('Batas waktu upload tugas Pertemuan 3 Sd Jumat, 23 Oktober 2026', $dueToday['deadline']);
        $this->assertSame(now('Asia/Jakarta')->toDateString(), $dueToday['deadline_date']);
        $this->assertTrue($dueToday['can_submit_directly']);
        $this->assertSame('Pertemuan 3', $dueToday['material_title']);
        $this->assertSame('/meeting/36365?courses_id=9496', $dueToday['meeting_url']);
        $this->assertSame('../files/latihan/7836009139c4eb3d39d4d31978994c2a-3-lat-2dd.pdf', $dueToday['assignment_file_url']);
        $this->assertSame(['courses_id' => '9496', 'id' => '36365', 'pertemuan' => 3], $dueToday['assignment_payload']);

        $this->assertFalse($closed['can_submit_directly'], 'The closed meeting page links no upload form.');
        $this->assertSame('2026-10-17', $closed['deadline_date']);

        $this->assertNull($this->pendingAssignment(36633, 9496, 4, 'Data Governance'));
        $this->assertNull($this->pendingAssignment(36957, 9496, 5, 'Data Governance'));
    }

    /**
     * At 10:00 WIB on its due day the Data Governance assignment is less
     * than 24 hours from the end of the day; AI for Web Design Advance P5,
     * due Selasa, 27 Oktober 2026, is not within 48 hours yet.
     */
    public function test_the_assignment_due_today_is_a_critical_action_item(): void
    {
        Bus::fake([RefreshPortalDataJob::class]);
        $store = app(PortalSnapshotStore::class);

        foreach (ActionCenterBuilder::SOURCES as $dataset) {
            $store->forget(self::TOKEN, $dataset);
        }

        $store->put(self::TOKEN, 'assignments', ['assignments' => [
            $this->pendingAssignment(36365, 9496, 3, 'Data Governance'),
            $this->pendingAssignment(37082, 9525, 5, 'AI for Web Design Advance (PHP, database)'),
        ]]);

        $items = app(ActionCenterBuilder::class)->build(self::TOKEN, 'laravel-session')['items'];
        $assignmentItems = array_values(array_filter($items, static fn (array $item): bool => $item['type'] === 'assignment_due'));

        $this->assertCount(1, $assignmentItems);
        $this->assertSame('assignment-36365', $assignmentItems[0]['id']);
        $this->assertSame('critical', $assignmentItems[0]['severity']);
        $this->assertSame('2026-10-23', $assignmentItems[0]['due_at']);
        $this->assertSame(0, $assignmentItems[0]['days_left']);
        $this->assertSame('Tugas Data Governance', $assignmentItems[0]['title']);
        $this->assertSame('/meeting/36365?courses_id=9496', $assignmentItems[0]['cta_url']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingAssignment(int $meetingId, int $coursesId, int $meetingNumber, string $courseName): ?array
    {
        $meetingDetail = $this->extractMeeting($meetingId, $coursesId);

        return $this->invokeNonPublicMethod($this->attendanceShortcutService($meetingId, $coursesId), 'buildPendingAssignment', [
            ['course_info' => ['name' => $courseName], 'lecturer_info' => ['name' => $meetingDetail['lecturer_info']['name']]],
            [
                'meeting_id' => (string) $meetingId,
                'courses_id' => (string) $coursesId,
                'meeting_number' => $meetingNumber,
                'material_title' => "Pertemuan {$meetingNumber}",
                'material_date' => $meetingDetail['schedule_info']['date'],
            ],
            $meetingDetail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMeeting(int $meetingId, int $coursesId): array
    {
        return $this->meetingExtractor($meetingId, $coursesId)->extractMeetingDetail(self::TOKEN, [
            'meeting_id' => (string) $meetingId,
            'courses_id' => (string) $coursesId,
        ]);
    }

    private function attendanceShortcutService(int $meetingId, int $coursesId): AttendanceShortcutService
    {
        return new AttendanceShortcutService($this->meetingExtractor($meetingId, $coursesId), Mockery::mock(SipandaiClassesExtractor::class));
    }

    /**
     * ClassesExtractor reading the real meeting page; the class page, when
     * given, answers with the given upload history.
     *
     * @param  array<string, string>|null  $classParameters
     * @param  list<array<string, mixed>>  $uploadHistory
     */
    private function meetingExtractor(int $meetingId, int $coursesId, ?array $classParameters = null, array $uploadHistory = []): ClassesExtractor
    {
        /** @var ClassesExtractor&MockInterface $extractor */
        $extractor = $this->makeExtractorWithRawFixture(ClassesExtractor::class, self::FIXTURES."meeting_{$meetingId}_{$coursesId}.html");

        if ($classParameters !== null) {
            $extractor->shouldReceive('extractClassDetail')
                ->with(self::TOKEN, ['meeting_id' => (string) $meetingId, 'courses_id' => (string) $coursesId])
                ->passthru();
            $extractor->shouldReceive('extractClassDetail')
                ->once()
                ->with(self::TOKEN, $classParameters)
                ->andReturn(['assignment_history' => ['assignments' => $uploadHistory, 'average_score' => null]]);
        }

        return $extractor;
    }

    /**
     * @param  list<Response|\Throwable>  $responses
     * @param  array<int, mixed>  $history
     */
    private function submissionServiceAnswering(array $responses, array &$history): SipandaiSubmissionService
    {
        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')->with(self::TOKEN)->willReturn(CookieJar::fromArray([], 'portal.invalid'));

        $service = new SipandaiSubmissionService($sessionManager);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = new \ReflectionProperty(PortalScrapingService::class, 'client');
        $client->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }

    private function page(string $file): string
    {
        $this->assertFixtureParsesLikeLivePortal(self::FIXTURES.$file);

        return (string) file_get_contents(base_path(self::FIXTURES.$file));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokeNonPublicMethod(object $instance, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($instance, $method))->invokeArgs($instance, $arguments);
    }
}
