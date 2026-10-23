<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalDataInvalidated;
use App\Events\PortalNotificationSent;
use App\Events\PortalSessionNoticeSent;
use App\Events\PortalUploadProgressed;
use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\FlushPortalOutboxJob;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Jobs\ProcessPortalExamUploadJob;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Jobs\SendPortalOutboxJob;
use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalNotification;
use App\Models\PortalOutboxItem;
use App\Services\AttendanceShortcutService;
use App\Services\PortalAuthService;
use App\Services\PortalBackgroundSync;
use App\Services\PortalDataOwner;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOperationTracker;
use App\Services\PortalOutbox;
use App\Services\PortalOwnerRekeyer;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\PortalUploadLocks;
use App\Services\SipandaiSubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Attendance codes and uploads asked for in an offline session wait in the
 * outbox (PortalOutbox), explicitly not sent, and go out once the session is
 * online again (FlushPortalOutboxJob); past their deadline they expire.
 */
class PortalOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000008';

    private const PASSWORD = 'rahasia-portal';

    /**
     * How the mocked portal answers a login: 'down', 'up' or 'rejects'.
     */
    private string $portal = 'down';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeBus();
        Event::fake([PortalSessionNoticeSent::class, PortalDataInvalidated::class, PortalNotificationSent::class, PortalUploadProgressed::class]);
        Storage::fake();
        $this->withSession(['_token' => 'known-csrf-token'])->withHeader('X-CSRF-TOKEN', 'known-csrf-token');

        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andReturnUsing(function (): array {
                return match ($this->portal) {
                    'up' => $this->loginResult(),
                    'rejects' => throw PortalAuthenticationException::invalidCredentials(),
                    default => throw PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out'),
                };
            });
            $mock->shouldReceive('logout');
        }));

        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME]);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_assignment_upload_while_offline_waits_in_the_outbox_instead_of_being_refused(): void
    {
        $this->fakeBus([ProcessPortalAssignmentUploadJob::class]);
        $token = $this->offlineSession();
        $deadlineDay = CarbonImmutable::now(config('app.timezone'))->addDays(3);
        app(PortalSnapshotStore::class)->put($token, 'assignments', ['assignments' => [[
            'meeting_id' => '29833',
            'course_name' => 'Pemrograman Web',
            'material_title' => 'Tugas 2: REST API',
            'deadline' => $deadlineDay->locale('id')->translatedFormat('l, d F Y').' 21:30',
            'deadline_date' => $deadlineDay->toDateString(),
            'assignment_payload' => ['courses_id' => '9000', 'id' => '29833', 'pertemuan' => 2],
        ]]]);

        $response = $this->post('/api/assignment', [
            'courses_id' => 9000,
            'id' => 29833,
            'pertemuan' => 2,
            'form_values' => ['keterangan' => 'Dikirim saat offline'],
            'attachment' => UploadedFile::fake()->create('tugas-2.pdf', 128, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued_offline')
            ->assertJsonPath('data.outbox_item.status', 'queued')
            ->assertJsonPath('data.outbox_item.status_label', 'Belum terkirim ke portal')
            ->assertJsonPath('data.outbox_item.subject', 'Pemrograman Web · Tugas 2: REST API')
            ->assertJsonMissingPath('data.operation_id');
        $this->assertStringStartsWith('Belum terkirim ke portal.', $response->json('message'));
        $this->assertStringContainsString('Tenggatnya', $response->json('message'));

        $item = PortalOutboxItem::query()->sole();
        $this->assertSame($this->ownerHash, $item->owner_hash);
        $this->assertSame(PortalOutboxItem::ACTION_ASSIGNMENT_UPLOAD, $item->action);
        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $item->status);
        $this->assertSame(29833, $item->payload['id']);
        $this->assertSame(['keterangan' => 'Dikirim saat offline'], $item->payload['form_values']);
        $this->assertSame('tugas-2.pdf', $item->payload['file_name']);
        $this->assertTrue($deadlineDay->setTime(21, 30)->equalTo($item->deadline_at));
        Storage::assertExists($item->file_path);
        $this->assertStringNotContainsString('29833', (string) PortalOutboxItem::query()->toBase()->value('payload'));

        Bus::assertNotDispatched(ProcessPortalAssignmentUploadJob::class);
        Event::assertDispatched(PortalDataInvalidated::class, fn (PortalDataInvalidated $event): bool => in_array('/api/outbox', $event->payload['cache_keys'], true));

        $this->getJson('/api/outbox')
            ->assertOk()
            ->assertJsonPath('data.queued_count', 1)
            ->assertJsonPath('data.offline', true)
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.action_label', 'Upload tugas')
            ->assertJsonPath('data.items.0.status_label', 'Belum terkirim ke portal')
            ->assertJsonPath('data.items.0.url', '/meeting/29833?courses_id=9000');
        $this->assertContains('meeting:29833', $this->getJson('/api/outbox')->json('data.items.0.match_keys'));
    }

    public function test_the_deadline_comes_from_the_meeting_page_or_the_request_when_the_task_list_does_not_know_it(): void
    {
        $this->fakeBus([ProcessPortalAssignmentUploadJob::class]);
        $token = $this->offlineSession();
        $deadlineDay = CarbonImmutable::now(config('app.timezone'))->addDays(2);
        app(PortalSnapshotStore::class)->put($token, 'meeting_detail', [
            'found' => true,
            'meeting_info' => ['breadcrumb' => 'Kelas Basis Data > Pertemuan 5'],
            'materials' => ['assignment_topic' => 'Normalisasi'],
            'assignment_info' => ['deadline' => 'Batas: '.$deadlineDay->locale('id')->translatedFormat('d F Y').' 08.00'],
        ], params: ['meeting_id' => '33244', 'courses_id' => '9202']);

        $fromMeetingPage = $this->post('/api/assignment', [
            'courses_id' => 9202,
            'meeting_id' => 33244,
            'attachment' => UploadedFile::fake()->create('tugas.pdf', 64, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $fromMeetingPage->assertStatus(202)->assertJsonPath('data.outbox_item.subject', 'Basis Data · Normalisasi');
        $this->assertTrue($deadlineDay->setTime(8, 0)->equalTo(PortalOutboxItem::query()->sole()->deadline_at));
        // The target ids are resolved when the item is sent; the meeting id is kept for that.
        $this->assertSame(33244, PortalOutboxItem::query()->sole()->payload['meeting_id']);
        $this->assertNull(PortalOutboxItem::query()->sole()->payload['id']);

        $explicit = $this->post('/api/assignment', [
            'courses_id' => 9202,
            'id' => 33245,
            'pertemuan' => 6,
            'deadline' => '2026-12-01T17:00:00+07:00',
            'attachment' => UploadedFile::fake()->create('tugas-6.pdf', 64, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $explicit->assertStatus(202)->assertJsonPath('data.outbox_item.subject', 'Tugas pertemuan 6');
        $this->assertTrue(CarbonImmutable::parse('2026-12-01T17:00:00+07:00')->equalTo(PortalOutboxItem::query()->latest('id')->first()->deadline_at));
    }

    public function test_an_attendance_code_and_an_exam_answer_wait_in_the_outbox_too(): void
    {
        $this->fakeBus([ProcessPortalExamUploadJob::class]);
        $token = $this->offlineSession();
        $snapshots = app(PortalSnapshotStore::class);
        $snapshots->put($token, 'dashboard', ['classes_summary' => ['today_classes' => [[
            'class_id' => '771',
            'course_name' => 'Jaringan Komputer',
            'attendance' => ['meeting_id' => '29808', 'payload' => ['courseid' => '8837', 'modulesid' => '29808', 'pertemuan' => 4]],
        ]]]]);
        $snapshots->put($token, 'exams', ['exams' => [['id' => '8833', 'course_name' => 'Basis Data', 'can_upload' => true]]]);

        $attendance = $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429']);

        $attendance->assertStatus(202)
            ->assertJsonPath('data.status', 'queued_offline')
            ->assertJsonPath('data.outbox_item.action', 'attendance')
            ->assertJsonPath('data.outbox_item.subject', 'Jaringan Komputer · Pertemuan 4')
            ->assertJsonPath('data.outbox_item.url', '/meeting/29808?courses_id=8837');
        $this->assertStringContainsString('Kode absen disimpan', $attendance->json('message'));
        $attendanceItem = PortalOutboxItem::query()->where('action', PortalOutboxItem::ACTION_ATTENDANCE)->sole();
        $this->assertSame('35533429', $attendanceItem->payload['kode']);
        $this->assertSame(4, $attendanceItem->payload['pertemuan']);
        $this->assertTrue(CarbonImmutable::now(config('app.timezone'))->setTime(23, 59, 59)->equalTo($attendanceItem->deadline_at));
        $this->assertNull($attendanceItem->file_path);

        $exam = $this->post('/api/exam', [
            'id' => 8833,
            'attachment' => UploadedFile::fake()->create('uts.zip', 256, 'application/zip'),
        ], ['Accept' => 'application/json']);

        $exam->assertStatus(202)
            ->assertJsonPath('data.status', 'queued_offline')
            ->assertJsonPath('data.outbox_item.subject', 'Ujian Basis Data')
            ->assertJsonPath('data.outbox_item.deadline_at', null)
            ->assertJsonPath('data.outbox_item.url', '/exam/8833');
        $this->assertStringContainsString('kiriman akan ditolak dan kamu diberi tahu', $exam->json('message'));
        $examItem = PortalOutboxItem::query()->where('action', PortalOutboxItem::ACTION_EXAM_UPLOAD)->sole();
        Storage::assertExists($examItem->file_path);
        $this->assertSame('uts.zip', $examItem->payload['file_name']);
        Bus::assertNotDispatched(ProcessPortalExamUploadJob::class);

        $this->getJson('/api/outbox')->assertOk()->assertJsonPath('data.queued_count', 2);
    }

    public function test_an_online_session_still_sends_straight_to_the_portal(): void
    {
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAttendance')->once()->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']]);
        }));
        $this->onlineSession();

        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429', 'pertemuan' => 2])
            ->assertOk()
            ->assertJsonPath('data.status', 'marked');

        $this->assertSame(0, PortalOutboxItem::query()->count());
        Bus::assertNotDispatched(FlushPortalOutboxJob::class);
    }

    public function test_the_outbox_is_flushed_once_the_offline_session_resumes(): void
    {
        $token = $this->offlineSession();
        $this->post('/api/assignment', [
            'courses_id' => 9000,
            'id' => 29833,
            'pertemuan' => 2,
            'attachment' => UploadedFile::fake()->create('tugas-2.pdf', 128, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(202);
        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429', 'pertemuan' => 4])->assertStatus(202);
        $assignment = PortalOutboxItem::query()->where('action', PortalOutboxItem::ACTION_ASSIGNMENT_UPLOAD)->sole();
        $storedFile = $assignment->file_path;
        $snapshots = app(PortalSnapshotStore::class);
        $snapshots->put($token, 'assignments', ['assignments' => []]);
        $snapshots->put($token, 'dashboard', ['classes_summary' => ['today_classes' => []]]);

        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
            $mock->shouldReceive('submitAssignment')
                ->once()
                ->withArgs(fn (string $sessionToken, int $courseId, int $discussionId, int $meetingNumber, UploadedFile $file): bool => $sessionToken === $token
                    && $courseId === 9000 && $discussionId === 29833 && $meetingNumber === 2
                    && $file->getClientOriginalName() === 'tugas-2.pdf')
                ->andReturn(['success' => true, 'message' => 'Uploaded', 'data' => ['status' => 'uploaded']]);
            $mock->shouldReceive('inspectAssignmentSubmissionState')
                ->once()
                ->andReturn(['status' => 'uploaded', 'file_info' => ['href' => 'files/tugas-2.pdf', 'text' => 'tugas-2.pdf'], 'delete_payload' => null]);
            $mock->shouldReceive('submitAttendance')
                ->once()
                ->with($token, 8837, 29808, 4, ['kode' => '35533429'])
                ->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']]);
        }));

        $this->portal = 'up';
        $this->runResume($token);

        Bus::assertDispatched(FlushPortalOutboxJob::class, fn (FlushPortalOutboxJob $job): bool => $job->uniqueId() === $token && $job->queue === 'campus-refresh');
        $this->assertFalse(app(PortalOfflineAccess::class)->isOfflineSession($token));

        $this->runFlush($token);

        foreach (PortalOutboxItem::query()->get() as $item) {
            $this->assertSame(PortalOutboxItem::STATUS_SENT, $item->status, $item->action);
            $this->assertSame(1, $item->attempts);
            $this->assertNotNull($item->sent_at);
            $this->assertNull($item->file_path);
            $this->assertNull($item->last_error);
        }
        Storage::assertMissing($storedFile);

        $notifications = PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_sent')->get();
        $this->assertCount(2, $notifications);
        $this->assertSame('Upload tugas terkirim ke portal', $notifications->firstWhere('url', '/meeting/29833?courses_id=9000')?->title);
        $this->assertSame('Absensi terkirim ke portal', $notifications->firstWhere('url', '/meeting/29808?courses_id=8837')?->title);
        Event::assertDispatchedTimes(PortalNotificationSent::class, 2);

        // The views an upload or attendance changes are re-synced, as online.
        $this->assertTrue($snapshots->isInvalidated($token, 'assignments', $snapshots->get($token, 'assignments')));
        $this->assertTrue($snapshots->isInvalidated($token, 'dashboard', $snapshots->get($token, 'dashboard')));
        Event::assertDispatched(PortalUploadProgressed::class, fn (PortalUploadProgressed $event): bool => $event->payload['status'] === 'completed');
        Event::assertDispatched(PortalDataInvalidated::class, fn (PortalDataInvalidated $event): bool => in_array('outbox', $event->payload['sync_keys'], true)
            && str_contains($event->payload['message'], '2 terkirim ke portal'));

        $this->getJson('/api/outbox')
            ->assertOk()
            ->assertJsonPath('data.queued_count', 0)
            ->assertJsonPath('data.items.0.status_label', 'Terkirim ke portal');
    }

    public function test_items_past_their_deadline_expire_and_are_never_sent(): void
    {
        $expired = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->dueAt(now()->subMinutes(5))->create();
        $open = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->dueAt(now()->addHours(2))->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitAssignment');
            $mock->shouldReceive('submitAttendance')->once()->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']]);
        }));
        $token = $this->onlineSession();

        $this->runFlush($token);

        $expired->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_EXPIRED, $expired->status);
        $this->assertStringContainsString('sudah lewat sebelum portal kampus bisa diakses', (string) $expired->last_error);
        $this->assertNull($expired->sent_at);
        $this->assertNull($expired->file_path);
        $this->assertCount(0, Storage::allFiles('portal-outbox'));
        $this->assertSame(PortalOutboxItem::STATUS_SENT, $open->refresh()->status);

        $notification = PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_expired')->sole();
        $this->assertSame('Upload tugas tidak terkirim: tenggat sudah lewat', $notification->title);
        $this->assertSame('critical', $notification->urgency);
        $this->assertStringContainsString('Pemrograman Web · Tugas pertemuan 2', (string) $notification->body);

        $this->getJson('/api/outbox')
            ->assertOk()
            ->assertJsonPath('data.queued_count', 0)
            ->assertJsonFragment(['status_label' => 'Tenggat lewat, tidak terkirim']);
    }

    public function test_a_portal_rejection_or_an_error_is_reported_as_failed(): void
    {
        $rejected = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->create();
        $broken = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAttendance')->once()->andReturn(['success' => false, 'message' => 'Kode absen salah.', 'data' => ['status' => 'invalid_code']]);
            $mock->shouldReceive('submitAssignment')->once()->andThrow(PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection reset'));
        }));
        $token = $this->onlineSession();

        $this->runFlush($token);

        $rejected->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_FAILED, $rejected->status);
        $this->assertSame('Kode absen salah.', $rejected->last_error);
        $this->assertSame(1, $rejected->attempts);

        $broken->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_FAILED, $broken->status);
        // The upload job's own wording, with its next step as detail.
        $this->assertStringStartsWith('Upload tugas gagal.', (string) $broken->last_error);
        $this->assertStringContainsString('Coba unggah ulang', (string) $broken->last_error);
        $this->assertNull($broken->file_path);

        $failures = PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_failed')->get();
        $this->assertCount(2, $failures);
        $attendanceFailure = $failures->firstWhere('title', 'Absensi gagal terkirim ke portal');
        $this->assertSame('critical', $attendanceFailure?->urgency);
        $this->assertStringContainsString('Kode absen salah. Kirim ulang secara manual.', (string) $attendanceFailure?->body);
        Event::assertDispatched(PortalDataInvalidated::class, fn (PortalDataInvalidated $event): bool => str_contains($event->payload['message'] ?? '', '2 gagal'));

        $this->getJson('/api/outbox')->assertOk()->assertJsonFragment(['status_label' => 'Gagal terkirim ke portal']);
    }

    public function test_the_flush_leaves_everything_queued_while_the_session_is_still_offline(): void
    {
        $item = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitAttendance');
        }));
        $token = $this->offlineSession();

        $this->runFlush($token);

        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $item->refresh()->status);
        $this->assertSame(0, $item->attempts);
        $this->assertSame(0, PortalNotification::query()->count());
    }

    public function test_an_online_login_queues_a_flush_only_when_something_is_waiting(): void
    {
        $this->onlineSession();
        Bus::assertNotDispatched(FlushPortalOutboxJob::class);

        PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->create();
        $this->login()->assertOk();

        Bus::assertDispatched(FlushPortalOutboxJob::class, fn (FlushPortalOutboxJob $job): bool => $job->uniqueId() === (string) session('campus_session_token'));
    }

    public function test_a_queued_item_can_be_cancelled_but_a_settled_one_and_another_students_cannot(): void
    {
        $queued = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->create();
        $sent = PortalOutboxItem::factory()->forOwner($this->ownerHash)->sent()->create();
        $foreign = PortalOutboxItem::factory()->forOwner('student_someone_else')->create();
        $storedFile = $queued->file_path;
        $this->offlineSession();

        $this->deleteJson("/api/outbox/{$queued->id}")->assertOk()->assertJsonPath('data.queued_count', 0);
        $this->deleteJson("/api/outbox/{$sent->id}")->assertNotFound();
        $this->deleteJson("/api/outbox/{$foreign->id}")->assertNotFound();

        $this->assertNull(PortalOutboxItem::query()->find($queued->id));
        Storage::assertMissing($storedFile);
        $this->assertNotNull(PortalOutboxItem::query()->find($sent->id));
        $this->assertNotNull(PortalOutboxItem::query()->find($foreign->id));
        $this->assertSame(0, $this->getJson('/api/outbox')->json('data.queued_count'));
    }

    public function test_the_outbox_can_be_switched_off_which_closes_the_actions_offline_again(): void
    {
        config(['portal.outbox.enabled' => false]);
        $this->offlineSession();

        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429'])
            ->assertStatus(503)
            ->assertJsonPath('meta.offline', true);

        $this->assertSame(0, PortalOutboxItem::query()->count());
    }

    public function test_pages_carry_the_outbox_host_and_the_banner_says_nothing_is_sent_yet(): void
    {
        $this->offlineSession();

        $this->get('/meeting/29833?courses_id=9000')
            ->assertOk()
            ->assertSee('data-outbox-match="meeting:29833"', false)
            ->assertSee('belum terkirim ke portal');
        $this->get('/exam/8833')->assertOk()->assertSee('data-outbox-match="exam:8833"', false);
        $this->get('/class/771?course_id=8837')->assertOk()->assertSee('data-outbox-match="class:771 course:8837"', false);
        $this->get('/exam')->assertOk()->assertSee('data-outbox-match="action:exam_upload"', false);
        $this->get('/dashboard')->assertOk()->assertSee('data-outbox-list', false);
    }

    public function test_a_shortcut_attendance_without_todays_material_is_not_sent_to_an_earlier_meeting(): void
    {
        $item = PortalOutboxItem::factory()->forOwner($this->ownerHash)->confirmed()->state([
            'action' => PortalOutboxItem::ACTION_ATTENDANCE,
            'payload' => [
                'courseid' => null,
                'modulesid' => null,
                'pertemuan' => null,
                'shortcut_context' => ['class_id' => 771, 'dosen_id' => 12, 'course_id' => 8837],
                'kode' => '35533429',
                'form_values' => [],
            ],
            'deadline_at' => now()->addHours(2),
        ])->create();
        $this->instance(AttendanceShortcutService::class, Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
            // Today's material is missing: the link points at the previous meeting.
            $mock->shouldReceive('resolveAttendanceShortcutForClass')->once()->andReturn([
                'attendance_payload' => ['courseid' => 8837, 'modulesid' => 29700, 'pertemuan' => 6],
                'attendance_target_reliable' => false,
            ]);
        }));
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitAttendance');
        }));
        $token = $this->onlineSession();

        $this->runFlush($token);

        $item->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_FAILED, $item->status);
        $this->assertStringContainsString('Materi pertemuan hari ini belum diunggah dosen', (string) $item->last_error);
        $this->assertSame(1, PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_failed')->count());
    }

    public function test_items_queued_with_another_password_are_refused_at_the_next_online_login(): void
    {
        $otherPassword = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29801)->queuedWithPassword('password-lama')->create();
        $samePassword = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29802)->queuedWithPassword(self::PASSWORD)->create();
        $unknown = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29803)->state(['credential_hash' => ''])->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAttendance')->once()->withArgs(fn (string $token, int $courseId, int $moduleId): bool => $moduleId === 29802)
                ->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']]);
        }));

        $token = $this->onlineSession();

        foreach ([[$otherPassword, PortalOutbox::UNCONFIRMED_MESSAGE], [$unknown, PortalOutbox::UNCONFIRMABLE_MESSAGE]] as [$refused, $message]) {
            $refused->refresh();
            $this->assertSame(PortalOutboxItem::STATUS_FAILED, $refused->status);
            $this->assertSame($message, $refused->last_error);
        }
        $this->assertNotNull($samePassword->refresh()->confirmed_at);
        $this->assertSame(2, PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_failed')->count());

        $this->runFlush($token);

        $this->assertSame(PortalOutboxItem::STATUS_SENT, $samePassword->refresh()->status);
        $this->assertSame(PortalOutboxItem::STATUS_FAILED, $otherPassword->refresh()->status);
    }

    public function test_an_old_password_can_queue_offline_but_its_items_never_go_out_with_the_new_one(): void
    {
        $token = $this->offlineSession();
        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429', 'pertemuan' => 4])->assertStatus(202);
        $item = PortalOutboxItem::query()->sole();
        $this->assertNull($item->confirmed_at);
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $item->credential_hash));
        $this->assertArrayNotHasKey('credential_hash', $this->getJson('/api/outbox')->json('data.items.0'));
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitAttendance');
        }));

        // The password changed on the portal since; the student logs in with the new one.
        $this->portal = 'up';
        $this->login('password-baru')->assertOk()->assertJsonPath('data.offline', false);
        $this->runFlush((string) session('campus_session_token'));

        $item->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_FAILED, $item->status);
        $this->assertSame(PortalOutbox::UNCONFIRMED_MESSAGE, $item->last_error);
        $this->assertNotSame($token, session('campus_session_token'));
    }

    public function test_a_resume_sends_only_what_was_queued_with_its_own_password(): void
    {
        $token = $this->offlineSession();
        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429', 'pertemuan' => 4])->assertStatus(202);
        $own = PortalOutboxItem::query()->sole();
        $other = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29809)->queuedWithPassword('password-lain')->create();

        $this->portal = 'up';
        $this->runResume($token);

        $this->assertNotNull($own->refresh()->confirmed_at);
        $this->assertNull($other->refresh()->confirmed_at);
        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $other->status);
    }

    public function test_an_upload_cut_short_by_an_expired_session_keeps_its_queued_file(): void
    {
        $item = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->confirmed()->create();
        $queuedFile = $item->file_path;
        $calls = 0;
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('submitAssignment')->twice()->andReturnUsing(function () use (&$calls): array {
                if (++$calls === 1) {
                    throw PortalAuthenticationException::sessionExpired();
                }

                return ['success' => true, 'message' => 'Uploaded', 'data' => ['status' => 'uploaded']];
            });
            $mock->shouldReceive('inspectAssignmentSubmissionState')->once()->andReturn(['status' => 'uploaded', 'file_info' => null, 'delete_payload' => null]);
        }));
        $token = $this->onlineSession();

        $this->runFlush($token);

        $item->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $item->status);
        $this->assertSame($queuedFile, $item->file_path);
        Storage::assertExists($queuedFile);
        $this->assertSame([], Storage::allFiles('portal-uploads'), 'The copy the job got is gone.');
        $this->assertSame(0, PortalNotification::query()->count());

        $this->runFlush($token);

        $this->assertSame(PortalOutboxItem::STATUS_SENT, $item->refresh()->status);
        Storage::assertMissing($queuedFile);
    }

    public function test_a_flush_that_died_mid_way_reports_its_upload_as_unverified_and_sends_its_attendance_again(): void
    {
        $upload = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->sending()->create();
        $attendance = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->sending()->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitAssignment');
            $mock->shouldReceive('submitAttendance')->once()->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'already_marked']]);
        }));
        $token = $this->onlineSession();
        // Items left in sending are reason enough to flush.
        Bus::assertDispatched(FlushPortalOutboxJob::class);

        $this->runFlush($token);

        $upload->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_UNVERIFIED, $upload->status);
        $this->assertSame(PortalOutbox::ABANDONED_UPLOAD_MESSAGE, $upload->last_error);
        $this->assertNull($upload->file_path);
        $this->assertSame(PortalOutboxItem::STATUS_SENT, $attendance->refresh()->status);

        $notification = PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_unverified')->sole();
        $this->assertSame('Upload tugas belum pasti terkirim ke portal', $notification->title);
        $this->assertStringContainsString('Cek halamannya di portal sebelum mengirim ulang.', (string) $notification->body);
        $this->getJson('/api/outbox')->assertOk()->assertJsonFragment(['status_label' => 'Belum pasti terkirim ke portal']);
    }

    public function test_an_upload_whose_outcome_the_portal_leaves_open_is_unverified_not_failed(): void
    {
        $item = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->confirmed()->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAssignment')->once()->andReturn(['success' => false, 'message' => 'Timeout', 'data' => ['status' => 'unknown', 'transport_error' => true]]);
            $mock->shouldReceive('inspectAssignmentSubmissionState')->once()->andReturn(['status' => 'not_uploaded']);
        }));
        $token = $this->onlineSession();

        $this->runFlush($token);

        $item->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_UNVERIFIED, $item->status);
        $this->assertStringStartsWith('Status upload tugas belum pasti.', (string) $item->last_error);
        $this->assertSame(1, PortalNotification::query()->where('type', 'outbox_unverified')->count());
    }

    public function test_an_upload_waits_while_the_same_target_is_being_uploaded_online(): void
    {
        $item = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->confirmed()->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitAssignment');
        }));
        $token = $this->onlineSession();
        app(PortalOperationTracker::class)->start('online-upload', 'assignment_upload', null, 'Mengirim.');
        $lock = app(PortalUploadLocks::class)->acquire($token, PortalUploadLocks::assignmentTarget(9000, 29833, 2), 'online-upload');
        $this->assertNotNull($lock);
        Bus::fake([FlushPortalOutboxJob::class]);

        $this->runFlush($token);

        $item->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $item->status);
        Storage::assertExists((string) $item->file_path);
        $this->assertSame(0, PortalNotification::query()->count());
        Bus::assertDispatched(FlushPortalOutboxJob::class, fn (FlushPortalOutboxJob $job): bool => $job->uniqueId() === $token && $job->delay !== null);
    }

    public function test_an_online_upload_drops_the_older_copy_queued_offline(): void
    {
        $this->fakeBus([ProcessPortalAssignmentUploadJob::class, ProcessPortalExamUploadJob::class]);
        $assignment = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->confirmed()->create();
        $otherMeeting = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->confirmed()->state(['payload' => ['courses_id' => 9000, 'id' => 29834, 'pertemuan' => 3, 'meeting_id' => 29834, 'form_values' => []]])->create();
        $exam = PortalOutboxItem::factory()->forOwner($this->ownerHash)->examUpload(8833)->withFile('uts.zip')->confirmed()->create();
        $assignmentFile = $assignment->file_path;
        $this->onlineSession();
        app(PortalSnapshotStore::class)->put((string) session('campus_session_token'), 'exams', ['exams' => [['id' => '8833', 'course_name' => 'Basis Data', 'can_upload' => true]]]);

        $this->post('/api/assignment', [
            'courses_id' => 9000,
            'id' => 29833,
            'pertemuan' => 2,
            'attachment' => UploadedFile::fake()->create('tugas-v2.pdf', 64, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->post('/api/exam', [
            'id' => 8833,
            'attachment' => UploadedFile::fake()->create('uts-v2.zip', 64, 'application/zip'),
        ], ['Accept' => 'application/json'])->assertStatus(202)->assertJsonPath('data.status', 'queued');

        $this->assertNull(PortalOutboxItem::query()->find($assignment->id));
        $this->assertNull(PortalOutboxItem::query()->find($exam->id));
        Storage::assertMissing($assignmentFile);
        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $otherMeeting->refresh()->status);
    }

    public function test_the_sweep_sends_what_an_offline_session_left_once_it_idled_out(): void
    {
        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class, SyncPortalArchiveJob::class, ExpirePortalSessionJob::class, SendPortalOutboxJob::class]);
        $token = $this->offlineSession();
        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429', 'pertemuan' => 4])->assertStatus(202);
        $attendance = PortalOutboxItem::query()->sole();
        $overdue = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->dueAt(now()->subMinute())->create();
        $stale = PortalOutboxItem::factory()->forOwner($this->ownerHash)->examUpload()->create(['created_at' => now()->subDays(31)]);
        // The offline session idles out before the portal is back: no resume will send anything.
        app(PortalSessionManager::class)->invalidateSession($token, 'idle');
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAttendance')->once()->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']]);
        }));

        $this->artisan('portal:send-outbox')->assertSuccessful();

        $this->assertSame(PortalOutboxItem::STATUS_EXPIRED, $overdue->refresh()->status);
        $this->assertSame(PortalOutboxItem::STATUS_EXPIRED, $stale->refresh()->status);
        $this->assertSame('Belum bisa dikirim dalam 30 hari.', $stale->last_error);
        $this->assertSame(2, PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_expired')->count());
        $accountId = (int) app(PortalOfflineAccess::class)->findForOwner($this->ownerHash)?->getKey();
        Bus::assertDispatchedTimes(SendPortalOutboxJob::class, 1);

        // Within the quarter hour the student is not logged in for again.
        $this->artisan('portal:send-outbox')->assertSuccessful();
        Bus::assertDispatchedTimes(SendPortalOutboxJob::class, 1);

        $this->portal = 'up';
        app()->call([new SendPortalOutboxJob($accountId), 'handle']);

        $attendance->refresh();
        $this->assertSame(PortalOutboxItem::STATUS_SENT, $attendance->status);
        $this->assertNotNull($attendance->confirmed_at);
        $this->assertSame(1, PortalNotification::query()->forOwner($this->ownerHash)->where('type', 'outbox_sent')->count());
        $this->assertTrue(Cache::lock(FlushPortalOutboxJob::lockKey($this->ownerHash), 10)->get(), 'The flush lock is released.');
    }

    public function test_a_stored_password_the_portal_refuses_sends_nothing_and_is_dropped(): void
    {
        $item = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->queuedWithPassword(self::PASSWORD)->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submitAttendance');
        }));
        $account = app(PortalOfflineAccess::class)->findForOwner($this->ownerHash);
        $this->portal = 'rejects';

        $this->assertSame('rejected', app(PortalBackgroundSync::class)->sendOutbox($account));

        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $item->refresh()->status);
        $this->assertNull($account->refresh()->credentials);
        $this->assertNotNull($account->credentials_rejected_at);
    }

    public function test_items_beyond_the_time_budget_go_to_a_follow_up_flush(): void
    {
        config(['portal.outbox.flush_start_budget' => 0]);
        $first = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29801)->confirmed()->dueAt(now()->addHour())->create();
        $second = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29802)->confirmed()->dueAt(now()->addHours(2))->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAttendance')->once()->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']]);
        }));
        $token = $this->onlineSession();
        Bus::fake([FlushPortalOutboxJob::class]);

        $this->runFlush($token);

        $this->assertSame(PortalOutboxItem::STATUS_SENT, $first->refresh()->status, 'One item always starts.');
        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $second->refresh()->status);
        Bus::assertDispatched(FlushPortalOutboxJob::class, fn (FlushPortalOutboxJob $job): bool => $job->uniqueId() === $token);
    }

    public function test_a_flush_that_dies_frees_its_lock_and_hands_over_a_bounded_number_of_times(): void
    {
        $token = $this->onlineSession();
        Bus::fake([FlushPortalOutboxJob::class]);
        $job = new FlushPortalOutboxJob($token);
        $runId = (new \ReflectionProperty(FlushPortalOutboxJob::class, 'runId'))->getValue($job);
        $this->assertTrue(Cache::lock(FlushPortalOutboxJob::lockKey($this->ownerHash), 660, $runId)->get());

        $job->failed(new \RuntimeException('Job timed out'));

        $this->assertTrue(Cache::lock(FlushPortalOutboxJob::lockKey($this->ownerHash), 10)->get(), 'The dead run\'s lock is freed.');
        Bus::assertDispatchedTimes(FlushPortalOutboxJob::class, 1);

        (new FlushPortalOutboxJob($token, null, 5))->failed(new \RuntimeException('Job timed out'));

        Bus::assertDispatchedTimes(FlushPortalOutboxJob::class, 1);
    }

    public function test_an_item_cancelled_or_superseded_while_the_flush_runs_is_neither_sent_nor_reported(): void
    {
        $first = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29801)->confirmed()->dueAt(now()->addHour())->create();
        $cancelled = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29802)->confirmed()->dueAt(now()->addHours(2))->create();
        $superseded = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29803)->confirmed()->dueAt(now()->addHours(3))->create();
        $submitted = [];
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use (&$submitted, $cancelled, $superseded): void {
            $mock->shouldReceive('submitAttendance')->andReturnUsing(function (string $token, int $courseId, int $moduleId) use (&$submitted, $cancelled, $superseded): array {
                $submitted[] = $moduleId;

                if ($moduleId === 29801) {
                    // The student cancels the next code while the first goes out.
                    app(PortalOutbox::class)->cancel($this->ownerHash, $cancelled->id);
                }

                if ($moduleId === 29803) {
                    // An online action drops the item being sent.
                    PortalOutboxItem::query()->whereKey($superseded->id)->delete();
                }

                return ['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']];
            });
        }));
        $token = $this->onlineSession();

        $this->runFlush($token);

        $this->assertSame([29801, 29803], $submitted);
        $this->assertSame(PortalOutboxItem::STATUS_SENT, $first->refresh()->status);
        $this->assertNull(PortalOutboxItem::query()->find($cancelled->id));
        $this->assertNull(PortalOutboxItem::query()->find($superseded->id));
        $this->assertSame(1, PortalNotification::query()->forOwner($this->ownerHash)->count());
    }

    public function test_an_online_upload_drops_a_queued_copy_a_flush_has_picked_but_not_started(): void
    {
        $this->fakeBus([ProcessPortalAssignmentUploadJob::class]);
        $picked = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->sending()->create();
        $this->onlineSession();

        $this->post('/api/assignment', [
            'courses_id' => 9000,
            'id' => 29833,
            'pertemuan' => 2,
            'attachment' => UploadedFile::fake()->create('tugas-v2.pdf', 64, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(202);

        $this->assertNull(PortalOutboxItem::query()->find($picked->id));
    }

    public function test_the_sweep_settles_what_a_dead_flush_left_when_no_session_comes_back(): void
    {
        $upload = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->sending()->create();
        $attendance = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->sending()->dueAt(now()->subMinute())->create();
        app(PortalOfflineAccess::class)->forget($this->ownerHash);

        $this->artisan('portal:send-outbox')->assertSuccessful();

        $this->assertSame(PortalOutboxItem::STATUS_UNVERIFIED, $upload->refresh()->status);
        $this->assertNull($upload->file_path);
        $this->assertSame(PortalOutboxItem::STATUS_EXPIRED, $attendance->refresh()->status);
        $this->assertEqualsCanonicalizing(['outbox_unverified', 'outbox_expired'], PortalNotification::query()->forOwner($this->ownerHash)->pluck('type')->all());
    }

    public function test_items_queued_while_the_student_is_online_elsewhere_are_confirmed_with_the_stored_password(): void
    {
        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class, SyncPortalArchiveJob::class, FlushPortalOutboxJob::class, SendPortalOutboxJob::class]);
        $token = $this->onlineSession();
        // Queued by an offline session on another device, after this login.
        $item = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance()->queuedWithPassword(self::PASSWORD)->create();
        $stale = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29809)->queuedWithPassword('password-lama')->create();

        $this->artisan('portal:send-outbox')->assertSuccessful();

        Bus::assertDispatchedTimes(SendPortalOutboxJob::class, 1);
        $accountId = (int) app(PortalOfflineAccess::class)->findForOwner($this->ownerHash)?->getKey();
        app()->call([new SendPortalOutboxJob($accountId), 'handle']);

        $this->assertNotNull($item->refresh()->confirmed_at);
        $this->assertSame(PortalOutboxItem::STATUS_FAILED, $stale->refresh()->status);
        $this->assertNull($stale->credential_hash, 'A settled item keeps no password hash.');
        Bus::assertDispatched(FlushPortalOutboxJob::class, fn (FlushPortalOutboxJob $job): bool => $job->uniqueId() === $token);
    }

    public function test_an_offline_session_from_before_sessions_kept_the_hash_still_gets_its_items_sent(): void
    {
        $token = $this->offlineSession();
        $key = config('portal.session_prefix').$token;
        $session = Cache::get($key);
        unset($session['credential_hash']);
        Cache::put($key, $session, 3600);

        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => '35533429', 'pertemuan' => 4])->assertStatus(202);
        $item = PortalOutboxItem::query()->sole();
        $this->assertNull($item->credential_hash);

        $this->portal = 'up';
        $this->runResume($token);

        $this->assertNotNull($item->refresh()->confirmed_at);
    }

    public function test_a_background_send_leaves_what_it_could_not_send_to_the_next_sweep(): void
    {
        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class, SyncPortalArchiveJob::class, ExpirePortalSessionJob::class, SendPortalOutboxJob::class]);
        config(['portal.outbox.flush_start_budget' => 0]);
        $first = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29801)->queuedWithPassword(self::PASSWORD)->dueAt(now()->addHour())->create();
        $second = PortalOutboxItem::factory()->forOwner($this->ownerHash)->attendance(moduleId: 29802)->queuedWithPassword(self::PASSWORD)->dueAt(now()->addHours(2))->create();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAttendance')->once()->andReturn(['success' => true, 'message' => 'Absensi tercatat.', 'data' => ['status' => 'marked']]);
        }));
        $account = app(PortalOfflineAccess::class)->findForOwner($this->ownerHash);
        Cache::put(SendPortalOutboxJob::attemptKey((int) $account->getKey()), true, 900);
        $this->portal = 'up';

        $this->assertSame('flushed', app(PortalBackgroundSync::class)->sendOutbox($account));

        $this->assertSame(PortalOutboxItem::STATUS_SENT, $first->refresh()->status);
        // No follow-up for the background session it logged out: the sync queue would have run it.
        $this->assertSame(PortalOutboxItem::STATUS_QUEUED, $second->refresh()->status);
        $this->assertFalse(Cache::has(SendPortalOutboxJob::attemptKey((int) $account->getKey())));
    }

    public function test_the_outbox_follows_the_student_to_a_new_owner_key(): void
    {
        $item = PortalOutboxItem::factory()->forOwner('student_previous_key')->attendance()->create();

        app(PortalOwnerRekeyer::class)->rekey('student_previous_key', $this->ownerHash);

        $this->assertSame($this->ownerHash, $item->refresh()->owner_hash);
    }

    public function test_pending_items_are_never_pruned_but_settled_ones_are(): void
    {
        $this->travelTo(now()->subDays(40));
        $queued = PortalOutboxItem::factory()->forOwner($this->ownerHash)->create();
        $sending = PortalOutboxItem::factory()->forOwner($this->ownerHash)->sending()->create();
        $unverified = PortalOutboxItem::factory()->forOwner($this->ownerHash)->withFile()->state(['status' => PortalOutboxItem::STATUS_UNVERIFIED])->create();
        $unverifiedFile = $unverified->file_path;
        $this->travelBack();

        $this->artisan('model:prune', ['--model' => [PortalOutboxItem::class]])->assertSuccessful();

        $this->assertNotNull(PortalOutboxItem::query()->find($queued->id));
        $this->assertNotNull(PortalOutboxItem::query()->find($sending->id));
        $this->assertNull(PortalOutboxItem::query()->find($unverified->id));
        Storage::assertMissing($unverifiedFile);
    }

    /**
     * @param  list<class-string>  $also
     */
    private function fakeBus(array $also = []): void
    {
        Bus::fake([
            RefreshPortalSessionJob::class,
            ResumeOfflineSessionJob::class,
            ScanPortalNotificationsJob::class,
            RefreshPortalDataJob::class,
            SyncPortalArchiveJob::class,
            FlushPortalOutboxJob::class,
            ...$also,
        ]);
    }

    private function offlineSession(): string
    {
        $this->portal = 'down';
        $this->login()->assertOk()->assertJsonPath('data.offline', true);
        // Logging in regenerates the CSRF token.
        session()->put('_token', 'known-csrf-token');

        return (string) session('campus_session_token');
    }

    private function onlineSession(): string
    {
        $this->portal = 'up';
        $this->login()->assertOk()->assertJsonPath('data.offline', false);
        session()->put('_token', 'known-csrf-token');

        return (string) session('campus_session_token');
    }

    private function login(string $password = self::PASSWORD): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => $password]);
    }

    /**
     * Runs the resume job as the queue would (its unique lock released first).
     */
    private function runResume(string $token): void
    {
        $job = new ResumeOfflineSessionJob($token, 2);
        (new UniqueLock(Cache::store()))->release($job);

        app()->call([$job, 'handle']);
    }

    private function runFlush(string $token): void
    {
        $job = new FlushPortalOutboxJob($token, app(PortalSessionManager::class)->laravelSessionId($token));
        (new UniqueLock(Cache::store()))->release($job);

        app()->call([$job, 'handle']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loginResult(): array
    {
        return [
            'success' => true,
            'user_info' => ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME],
            'cookies' => [[
                'Name' => 'PHPSESSID',
                'Value' => 'cookie-value',
                'Domain' => 'my.cic.ac.id',
                'Path' => '/',
                'Expires' => time() + 3600,
                'Secure' => true,
                'HttpOnly' => true,
                'Discard' => false,
                'Max-Age' => null,
            ]],
            'session_data' => ['login_time' => now()->toISOString()],
        ];
    }
}
