<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * GET /api/lms/class reads the SIPANDAI class list with the parser of the
 * merged class list and reports the student it belongs to.
 */
class PortalLmsClassListTest extends TestCase
{
    use InteractsWithPortalFixtures;
    use RefreshDatabase;

    private const TOKEN = 'token-lms-class-list';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_class_list_names_the_student_and_splits_lecturer_and_schedule(): void
    {
        $this->instance(
            SipandaiClassesExtractor::class,
            $this->makeExtractorWithFixture(SipandaiClassesExtractor::class, 'tests/Fixtures/portal/sipandai_dashboard.html')
        );

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/lms/class')
            ->assertOk()
            ->assertJsonPath('user_info.nim', '20990000001')
            ->assertJsonPath('user_info.nama', 'Mahasiswa Contoh')
            ->assertJsonPath('classes.0.course_info', ['code' => 'UC01024011', 'name' => 'Bahasa Indonesia'])
            ->assertJsonPath('classes.0.lecturer_info.name', 'Dosen Contoh Tiga, M.Pd')
            ->assertJsonPath('classes.0.schedule.day', 'Senin')
            ->assertJsonPath('classes.0.schedule.start_time', '09:30:00')
            ->assertJsonPath('classes.0.schedule.end_time', '11:00:00')
            ->assertJsonPath('classes.0.join_link.params', ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810']);
    }

    public function test_a_stored_class_list_reports_its_student(): void
    {
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'lms_classes', [
            'student_info' => ['program_studi' => 'SISTEM INFORMASI - S1', 'nim' => '20990000001', 'nama' => 'Mahasiswa Contoh'],
            'classes' => [],
        ]);
        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractSipandaiClassesData');
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/lms/class')
            ->assertOk()
            ->assertJsonPath('user_info', ['program_studi' => 'SISTEM INFORMASI - S1', 'nim' => '20990000001', 'nama' => 'Mahasiswa Contoh'])
            ->assertJsonPath('classes', []);
    }
}
