<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalActionProjector;
use App\Services\PortalSnapshotStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PortalActionProjectorTest extends TestCase
{
    private PortalSnapshotStore $snapshots;

    private PortalActionProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshots = app(PortalSnapshotStore::class);
        $this->projector = app(PortalActionProjector::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_attendance_marks_the_dashboard_class_as_attended_and_keeps_sync_state(): void
    {
        $syncedAt = now()->subMinutes(5)->toISOString();
        $this->snapshots->put('token', 'dashboard', $this->dashboardData(), $syncedAt);
        $this->snapshots->markStale('token', 'dashboard');

        $status = $this->projector->recordAttendance('token', 8833, 30728, 8);

        $snapshot = $this->snapshots->get('token', 'dashboard');
        $attendance = $snapshot['data']['classes_summary']['today_classes'][0]['attendance'];

        $this->assertTrue($status['already_marked']);
        $this->assertTrue($attendance['status']['already_marked']);
        $this->assertSame('Hadir', $attendance['status']['label']);
        $this->assertFalse($attendance['actionable']);
        $this->assertSame(1, $snapshot['data']['classes_summary']['actionable_attendance_count']);
        $this->assertTrue($snapshot['data']['classes_summary']['today_classes'][1]['attendance']['actionable']);
        $this->assertSame($syncedAt, $snapshot['synced_at']);
        $this->assertTrue($this->snapshots->isInvalidated('token', 'dashboard', $snapshot));
    }

    public function test_attendance_marks_the_meeting_detail_as_recorded(): void
    {
        $params = ['meeting_id' => '30728', 'courses_id' => '8833'];
        $this->snapshots->put('token', 'meeting_detail', [
            'attendance_info' => ['status' => 'Belum absen, silakan scan QR'],
        ], params: $params);

        $this->projector->recordAttendance('token', 8833, 30728, 8);

        $attendanceInfo = $this->snapshots->get('token', 'meeting_detail', $params)['data']['attendance_info'];

        $this->assertSame('Hadir', $attendanceInfo['status']);
        $this->assertNotEmpty($attendanceInfo['time']);
    }

    public function test_overlay_never_replaces_attendance_the_portal_already_reports(): void
    {
        $this->projector->recordAttendance('token', 8833, 30728, 8);

        $data = $this->dashboardData();
        $data['classes_summary']['today_classes'][0]['attendance']['status'] = [
            'already_marked' => true,
            'status' => 'late',
            'label' => 'Terlambat',
            'time' => '08:17',
        ];
        $data['classes_summary']['today_classes'][0]['attendance']['actionable'] = false;

        $this->assertSame($data, $this->projector->apply('token', 'dashboard', [], $data));
    }

    public function test_uploaded_assignment_leaves_the_pending_list_and_shows_on_the_meeting(): void
    {
        $params = ['meeting_id' => '29833', 'courses_id' => '9000'];
        $this->snapshots->put('token', 'assignments', ['assignments' => [
            $this->pendingAssignment('9000', '29833'),
            $this->pendingAssignment('9001', '40000'),
        ]]);
        $this->snapshots->put('token', 'meeting_detail', [
            'assignment_info' => ['status' => 'not_uploaded', 'upload_link' => '?act=send&courses_id=9000&id=29833&pertemuan=2'],
        ], params: $params);

        $fileInfo = ['href' => 'files/task.pdf', 'text' => 'task.pdf'];
        $deletePayload = ['id' => '126663', 'idx' => '29833', 'pertemuan' => '2'];
        $this->projector->recordAssignmentUploaded('token', 9000, 29833, 2, $fileInfo, $deletePayload);

        $pending = $this->snapshots->get('token', 'assignments')['data']['assignments'];
        $assignmentInfo = $this->snapshots->get('token', 'meeting_detail', $params)['data']['assignment_info'];

        $this->assertCount(1, $pending);
        $this->assertSame('40000', $pending[0]['assignment_payload']['id']);
        $this->assertSame('uploaded', $assignmentInfo['status']);
        $this->assertSame($fileInfo, $assignmentInfo['submission']['file_info']);
        $this->assertSame($deletePayload, $assignmentInfo['delete_payload']);
    }

    public function test_deleting_an_upload_puts_the_task_back_on_the_dashboard_at_once(): void
    {
        $this->snapshots->put('token', 'assignments', ['assignments' => [
            ['deadline_date' => '2026-10-21'] + $this->pendingAssignment('9000', '29833'),
            ['deadline_date' => '2026-10-14'] + $this->pendingAssignment('9001', '40000'),
        ]]);

        $this->projector->recordAssignmentUploaded('token', 9000, 29833, 2, ['href' => 'files/task.pdf']);
        $this->assertSame(['40000'], array_column($this->snapshots->get('token', 'assignments')['data']['assignments'], 'meeting_id'));

        $this->projector->recordAssignmentDeleted('token', 9000, 29833, 2);

        // Back in the list, still ordered by deadline, without a new crawl.
        $this->assertSame(['40000', '29833'], array_column($this->snapshots->get('token', 'assignments')['data']['assignments'], 'meeting_id'));
    }

    public function test_delete_cancels_a_recorded_upload(): void
    {
        $params = ['meeting_id' => '29833', 'courses_id' => '9000'];
        $this->projector->recordAssignmentUploaded('token', 9000, 29833, 2, ['href' => 'files/task.pdf']);
        $this->projector->recordAssignmentDeleted('token', 9000, 29833, 2);

        $pending = ['assignments' => [$this->pendingAssignment('9000', '29833')]];
        $meeting = ['assignment_info' => [
            'status' => 'uploaded',
            'upload_link' => '?act=send&courses_id=9000&id=29833&pertemuan=2',
            'submission' => ['file_info' => ['href' => 'files/task.pdf']],
            'delete_payload' => ['id' => '126663'],
        ]];

        $this->assertSame($pending, $this->projector->apply('token', 'assignments', [], $pending));

        $assignmentInfo = $this->projector->apply('token', 'meeting_detail', $params, $meeting)['assignment_info'];

        $this->assertSame('not_uploaded', $assignmentInfo['status']);
        $this->assertArrayNotHasKey('submission', $assignmentInfo);
        $this->assertArrayNotHasKey('delete_payload', $assignmentInfo);
    }

    public function test_recorded_actions_expire_after_the_ledger_ttl(): void
    {
        config(['portal.actions.ledger_ttl' => 600]);
        $this->projector->recordAttendance('token', 8833, 30728, 8);

        Carbon::setTestNow(now()->addSeconds(601));

        $data = $this->dashboardData();

        $this->assertSame($data, $this->projector->apply('token', 'dashboard', [], $data));
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardData(): array
    {
        $notMarked = ['already_marked' => false, 'status' => null, 'label' => null, 'time' => null];

        return [
            'classes_summary' => [
                'actionable_attendance_count' => 2,
                'today_classes' => [
                    [
                        'course_code' => 'IF101',
                        'attendance' => [
                            'available' => true,
                            'actionable' => true,
                            'meeting_id' => '30728',
                            'status' => $notMarked,
                            'payload' => ['courseid' => '8833', 'modulesid' => '30728', 'pertemuan' => 8],
                        ],
                    ],
                    [
                        'course_code' => 'IF102',
                        'attendance' => [
                            'available' => true,
                            'actionable' => true,
                            'meeting_id' => '31000',
                            'status' => $notMarked,
                            'payload' => ['courseid' => '8840', 'modulesid' => '31000', 'pertemuan' => 8],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingAssignment(string $coursesId, string $meetingId): array
    {
        return [
            'meeting_id' => $meetingId,
            'course_name' => 'Kelas '.$coursesId,
            'can_submit_directly' => true,
            'assignment_payload' => ['courses_id' => $coursesId, 'id' => $meetingId, 'pertemuan' => 2],
        ];
    }
}
