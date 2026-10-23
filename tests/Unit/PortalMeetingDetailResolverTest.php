<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalClassCatalog;
use App\Services\PortalMeetingDetailResolver;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class PortalMeetingDetailResolverTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const CLASS_PARAMS = ['id' => '1688', 'dosen_id' => '2744', 'course_id' => '9198'];

    /**
     * A real meeting page; it was saved without the page header, so it has no breadcrumb.
     */
    private const FIXTURE = 'tests/Fixtures/portal/meeting_assignment_detail.html';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_assignment_status_is_unresolved_when_the_class_page_cannot_be_read(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->with('token', '9198')->andReturn(self::CLASS_PARAMS);
        });
        $extractor = $this->meetingExtractor(function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->with('token', self::CLASS_PARAMS)
                ->andThrow(new \RuntimeException('cURL error 28: Operation timed out'));
        });

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33317', '9198');

        $this->assertSame('unresolved', $detail['assignment_info']['status']);
        $this->assertSame('6', $detail['assignment_info']['meeting_number']);
    }

    public function test_assignment_status_is_unresolved_when_the_course_is_not_in_the_class_list(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->andReturnNull();
        });

        $detail = $this->resolver($this->meetingExtractor(), $catalog)->resolve('token', '33317', '9198');

        $this->assertSame('unresolved', $detail['assignment_info']['status']);
    }

    public function test_an_expired_session_on_the_class_page_is_not_hidden(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->andThrow(PortalScrapingException::pageNotAccessible('class list'));
        });

        $this->expectException(PortalScrapingException::class);

        $this->resolver($this->meetingExtractor(), $catalog)->resolve('token', '33317', '9198');
    }

    public function test_assignment_is_not_uploaded_when_the_class_history_has_no_row_for_the_upload_links_meeting(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->andReturn(self::CLASS_PARAMS);
        });
        $extractor = $this->meetingExtractor(function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')->once()->with('token', self::CLASS_PARAMS)->andReturn([
                'assignment_history' => ['assignments' => [
                    ['meeting' => '1', 'date' => 'Senin, 2 Maret 2026', 'delete_info' => ['href' => '?mod=sipandai&act=jobdel&id=120001&idx=31001&pertemuan=1']],
                ]],
            ]);
        });

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33317', '9198');

        $this->assertSame('not_uploaded', $detail['assignment_info']['status']);
        $this->assertSame('6', $detail['assignment_info']['meeting_number']);
        $this->assertArrayNotHasKey('delete_payload', $detail['assignment_info']);
        $this->assertSame('/class/1688?dosen_id=2744&course_id=9198', $detail['class_url']);
    }

    public function test_assignment_is_uploaded_when_the_class_history_lists_this_meeting(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->andReturn(self::CLASS_PARAMS);
        });
        $extractor = $this->meetingExtractor(function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')->once()->with('token', self::CLASS_PARAMS)->andReturn([
                'assignment_history' => ['assignments' => [
                    ['meeting' => '6', 'date' => 'Selasa, 21 April 2026', 'delete_info' => ['href' => '?mod=sipandai&act=jobdel&id=126663&idx=33317&pertemuan=6']],
                ]],
            ]);
        });
        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inspectAssignmentSubmissionState')->once()->with('token', 9198, 33317, 6)->andReturn([
                'status' => 'uploaded',
                'delete_payload' => null,
                'file_info' => ['href' => '../files/tugasmhs/9198-6-20990000001-a14.pdf', 'text' => 'Open File'],
            ]);
        });

        $detail = $this->resolver($extractor, $catalog, $submissionService)->resolve('token', '33317', '9198');

        $this->assertSame('uploaded', $detail['assignment_info']['status']);
        $this->assertSame(['id' => '126663', 'idx' => '33317', 'pertemuan' => '6'], $detail['assignment_info']['delete_payload']);
        $this->assertSame('../files/tugasmhs/9198-6-20990000001-a14.pdf', $detail['assignment_info']['submission']['file_info']['href']);
    }

    public function test_a_meeting_without_an_assignment_leads_back_to_the_class_of_its_breadcrumb(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('findByCourseId');
        });
        $extractor = $this->meetingExtractor(overrides: [
            'meeting_info' => ['breadcrumb' => 'Beranda > Kelas Data Governance > Pertemuan 5', 'class_ref' => self::CLASS_PARAMS],
            'assignment_info' => ['deadline' => '', 'upload_link' => '', 'upload_text' => ''],
            'materials' => ['main_topic' => 'Pokok Bahasan : Data Governance'],
        ]);

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33317', '9198');

        $this->assertSame('/class/1688?dosen_id=2744&course_id=9198', $detail['class_url']);
        $this->assertArrayNotHasKey('status', $detail['assignment_info']);
    }

    public function test_the_breadcrumb_class_leads_back_even_when_the_class_list_does_not_know_the_course(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->andReturnNull();
        });
        $extractor = $this->meetingExtractor(overrides: [
            'meeting_info' => ['breadcrumb' => 'Beranda > Kelas Basis Data > Pertemuan 6', 'class_ref' => self::CLASS_PARAMS],
        ]);

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33317', '9198');

        $this->assertSame('unresolved', $detail['assignment_info']['status']);
        $this->assertSame('/class/1688?dosen_id=2744&course_id=9198', $detail['class_url']);
    }

    public function test_the_breadcrumb_class_wins_over_the_class_list_entry_of_the_course(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->andReturn(['id' => '1500', 'dosen_id' => '2001', 'course_id' => '9198']);
        });
        $extractor = $this->meetingExtractor(function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')->once()->with('token', ['id' => '1500', 'dosen_id' => '2001', 'course_id' => '9198'])->andReturn([
                'assignment_history' => ['assignments' => []],
            ]);
        }, [
            'meeting_info' => ['breadcrumb' => 'Beranda > Kelas Basis Data > Pertemuan 6', 'class_ref' => self::CLASS_PARAMS],
        ]);

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33317', '9198');

        $this->assertSame('not_uploaded', $detail['assignment_info']['status']);
        $this->assertSame('/class/1688?dosen_id=2744&course_id=9198', $detail['class_url']);
    }

    public function test_a_meeting_without_a_breadcrumb_class_or_an_assignment_has_no_class_url(): void
    {
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('findByCourseId');
        });
        $extractor = $this->meetingExtractor(overrides: [
            'assignment_info' => ['deadline' => '', 'upload_link' => '', 'upload_text' => ''],
            'materials' => ['main_topic' => 'Pokok Bahasan : Basis Data'],
        ]);

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33317', '9198');

        $this->assertArrayNotHasKey('class_url', $detail);
    }

    /**
     * The real fixture was saved without the page header, so the breadcrumb
     * is laid out as PORTAL_FEATURE_MAP F25 describes it.
     */
    public function test_the_breadcrumb_class_link_of_a_meeting_page_becomes_its_class_url(): void
    {
        $extractor = $this->extractorReturning(<<<'HTML'
            <!DOCTYPE html>
            <html><body><div class="app-wrapper"><div class="app-content">
            <div class="card">
                <div class="header"><h4 class="title"><a href="?mod=sipandai&act=dashboardmahasiswa">Beranda</a> > <a href="?mod=sipandai&act=download&id=1688&dosen_id=2744&course_id=9198">Kelas Data Governance</a> > Pertemuan 5</h4></div>
                <div class="content table-responsive">
                    <table class="table">
                        <tr><td width="40%"><b>Nama Dosen</b></td><td>:</td><td>Dosen Contoh Empat, M.Kom.</td></tr>
                        <tr><td><b>Upload Tugas </b></td><td>:</td><td><b>Tidak ada tugas perkuliahan untuk pertemuan tersebut</b></td></tr>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="content table-responsive">
                    <table class="table"><tr><td align="justify">
                        <b><h5>Pokok Bahasan : Data Governance</h5></b>
                        <b><h4>Materi Perkuliahan</h4><br></b>
                        <iframe src="../files/modul/abc-5-mod-x.pdf"></iframe>
                    </td></tr></table>
                </div>
            </div>
            </div></div></body></html>
            HTML);
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('findByCourseId');
        });

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33317', '9198');

        $this->assertSame(self::CLASS_PARAMS, $detail['meeting_info']['class_ref']);
        $this->assertSame('/class/1688?dosen_id=2744&course_id=9198', $detail['class_url']);
    }

    public function test_a_meeting_page_without_a_breadcrumb_class_link_leads_back_to_the_class_from_the_class_list(): void
    {
        $extractor = $this->makeExtractorWithFixture(ClassesExtractor::class, self::FIXTURE);
        $catalog = Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByCourseId')->once()->with('token', '9188')->andReturn(self::CLASS_PARAMS);
        });

        $detail = $this->resolver($extractor, $catalog)->resolve('token', '33118', '9188');

        $this->assertNull($detail['meeting_info']['class_ref']);
        $this->assertSame('3', $detail['assignment_info']['meeting_number']);
        $this->assertSame('/class/1688?dosen_id=2744&course_id=9198', $detail['class_url']);
    }

    /**
     * A meeting whose lecturer topic mentions another meeting, with the
     * portal's upload link for meeting 6.
     *
     * @param  array<string, mixed>  $overrides  Top-level keys of the meeting page that replace the default ones.
     */
    private function meetingExtractor(?callable $classDetailExpectations = null, array $overrides = []): ClassesExtractor
    {
        return Mockery::mock(ClassesExtractor::class, function (MockInterface $mock) use ($classDetailExpectations, $overrides): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->with('token', ['meeting_id' => '33317', 'courses_id' => '9198'])
                ->andReturn([
                    'request_url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=discuss&id=33317&courses_id=9198',
                    'meeting_info' => ['breadcrumb' => 'Beranda > Kelas Basis Data > Pertemuan 6', 'class_ref' => null],
                    'assignment_info' => [
                        'deadline' => 'Kamis, 30 April 2026',
                        'upload_link' => '?mod=sipandai&act=send&courses_id=9198&id=33317&pertemuan=6',
                        'upload_text' => 'Upload Tugas',
                    ],
                    'materials' => [
                        'main_topic' => 'Review Pertemuan 1',
                        'assignment_material_iframe' => '../files/latihan/task.pdf',
                    ],
                    ...$overrides,
                ]);

            if ($classDetailExpectations !== null) {
                $classDetailExpectations($mock);
            }
        });
    }

    private function extractorReturning(string $html): ClassesExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(ClassesExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor;
    }

    private function resolver(
        ClassesExtractor $extractor,
        PortalClassCatalog $catalog,
        ?SipandaiSubmissionService $submissionService = null
    ): PortalMeetingDetailResolver {
        return new PortalMeetingDetailResolver(
            $extractor,
            $catalog,
            $submissionService ?? Mockery::mock(SipandaiSubmissionService::class)
        );
    }
}
