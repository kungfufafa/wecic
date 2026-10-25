<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Services\PortalActionProjector;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The class page (/api/class/{id}) shows an attendance or an upload the
 * portal just confirmed while its snapshot is still the older scrape, the
 * way the meeting page already does (PortalActionProjector).
 */
class PortalClassPageProjectionTest extends TestCase
{
    private const PARAMS = ['id' => '5393', 'dosen_id' => '1863', 'course_id' => '8833'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_class_page_shows_a_confirmed_attendance_and_upload_while_it_resyncs(): void
    {
        Queue::fake([RefreshPortalDataJob::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-25 08:15:00'));

        $snapshots = app(PortalSnapshotStore::class);
        $snapshots->put('token-class', 'class_detail', $this->scrapedClassPage(), now()->subMinutes(5)->toISOString(), self::PARAMS);

        // What the attendance and upload endpoints do once the portal confirms.
        $snapshots->markStale('token-class', 'class_detail');
        $projector = app(PortalActionProjector::class);
        $projector->recordAttendance('token-class', 8833, 30728, 8);
        $projector->recordAssignmentUploaded('token-class', 8833, 30728, 8, ['href' => '../files/tugasmhs/8833-8-20990000001-c41.pdf', 'text' => 'Open File']);

        $this->mockValidCampusSession('token-class');

        $this->withSession(['campus_session_token' => 'token-class'])
            ->getJson('/api/class/5393?dosen_id=1863&course_id=8833')
            ->assertOk()
            ->assertJsonPath('meta.status', 'syncing')
            ->assertJsonPath('class_detail.attendance_info.meetings.1.status', 'present')
            ->assertJsonPath('class_detail.attendance_info.meetings.1.details.attendance_time', '08:15')
            ->assertJsonPath('class_detail.attendance_info.summary.present', 2)
            ->assertJsonPath('class_detail.attendance_info.summary.absent', 0)
            ->assertJsonPath('class_detail.assignment_history.assignments.1.meeting', '8')
            ->assertJsonPath('class_detail.assignment_history.assignments.1.file_info.href', '../files/tugasmhs/8833-8-20990000001-c41.pdf');

        // The background re-sync still confirms it from the portal.
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function scrapedClassPage(): array
    {
        return [
            'content_type' => 'class_info',
            'course_info' => ['name' => 'Enterprise Resource Planning'],
            'course_materials' => [
                ['title' => 'Pertemuan 7', 'meeting_number' => 7, 'join_link' => ['href' => '?mod=sipandai&act=discuss&id=30400&courses_id=8833', 'text' => 'Join Pertemuan 7']],
                ['title' => 'Pertemuan 8', 'meeting_number' => 8, 'join_link' => ['href' => '?mod=sipandai&act=discuss&id=30728&courses_id=8833', 'text' => 'Join Pertemuan 8']],
            ],
            'attendance_info' => [
                'meetings' => [
                    ['meeting_number' => 7, 'status' => 'present', 'details' => ['button_text' => 'H']],
                    ['meeting_number' => 8, 'status' => 'absent', 'details' => ['image_src' => '../images/bolos.jpg']],
                ],
                'summary' => ['total_meetings' => 2, 'present' => 1, 'late' => 0, 'absent' => 1, 'permit' => 0, 'sick' => 0, 'attendance_rate' => 50.0, 'upcoming' => 0, 'held_meetings' => 2],
            ],
            'assignment_history' => [
                'assignments' => [
                    ['no' => '1.', 'date' => 'Minggu, 18 Oktober 2026', 'meeting' => '7', 'file_info' => ['href' => '../files/tugasmhs/8833-7-20990000001-a6b.pdf', 'text' => 'Open File'], 'score' => '0'],
                ],
                'average_score' => '0',
            ],
            'found' => true,
        ];
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
