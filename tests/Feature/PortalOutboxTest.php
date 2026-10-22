<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalDataInvalidated;
use App\Events\PortalNotificationSent;
use App\Events\PortalSessionNoticeSent;
use App\Events\PortalUploadProgressed;
use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\FlushPortalOutboxJob;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Jobs\ProcessPortalExamUploadJob;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalNotification;
use App\Models\PortalOutboxItem;
use App\Services\PortalAuthService;
use App\Services\PortalDataOwner;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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

        Bus::assertDispatched(FlushPortalOutboxJob::class, fn (FlushPortalOutboxJob $job): bool => $job->uniqueId() === $token && $job->queue === 'campus-uploads');
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
        // The upload job's own wording, with the cause as detail.
        $this->assertStringStartsWith('Upload tugas gagal.', (string) $broken->last_error);
        $this->assertStringContainsString('Connection reset', (string) $broken->last_error);
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

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
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
