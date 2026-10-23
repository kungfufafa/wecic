<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalExamListResolver;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The exam heuristics on real, sanitized pages (tests/Fixtures/portal/real),
 * captured on Friday 23 October 2026: no exam is scheduled yet and no answer
 * was sent. The exam list (act=ujian) names the semester's ten courses
 * without schedule or upload link; each sendujian page renders its form
 * without a file input, an empty `data[tipe]`, a start of "01-01-1970" and
 * the portal's "Waktu Ujian Sudah Berakhir" notice.
 */
class RealPortalExamPagesTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURES = 'tests/Fixtures/portal/real/';

    /**
     * The exam list's courses, in the portal's order.
     *
     * @var array<string, string>
     */
    private const COURSES = [
        'SI053026' => 'Pemrosesan Bahasa Alami',
        'SI032022' => 'Manajemen Proyek',
        'SI033017' => 'Statistik Inferensial',
        'AI052001' => 'Pengolahan Citra Digital',
        'DS052001' => 'Teknik Penambangan Data',
        'DS052002' => 'Data Governance',
        'SI013005' => 'Sistem Enterprise',
        'SI012002' => 'Matematika Pembelajaran Mesin',
        'SI033020' => 'AI for Web Design Advance (PHP, database)',
        'SI033019' => 'Artificial Intelligence and Basic Data Science',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.request_delay' => 0]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function examPages(): array
    {
        return [
            'Data Governance' => [9496, 'DS052002 - Data Governance'],
            'Statistik Inferensial' => [9519, 'SI033017 - Statistik Inferensial'],
            'Artificial Intelligence and Basic Data Science' => [9524, 'SI033019 - Artificial Intelligence and Basic Data Science'],
        ];
    }

    #[DataProvider('examPages')]
    public function test_no_answer_is_read_as_sent_and_the_upload_is_not_open(int $examId, string $course): void
    {
        $page = $this->examPage($examId);
        $this->assertStringContainsString($course, $page);

        $history = [];
        $detail = $this->serviceAnswering([new Response(200, [], $page)], $history)->getExamDetail('token-real', $examId);

        $this->assertCount(1, $history);
        $this->assertSame(PortalEndpointRegistry::url('exam_upload_form', ['id' => $examId]), (string) $history[0]['request']->getUri());
        $this->assertSame('not_uploaded', $detail['status']);
        $this->assertSame([], $detail['submissions']);
        $this->assertSame([], $detail['stored_submissions']);
        $this->assertNull($detail['latest_submission']);
        $this->assertNull($detail['exam_type'], 'The page names no exam: data[tipe] is empty.');
        $this->assertFalse($detail['form']['available']);
        $this->assertSame((string) $examId, $detail['form']['fields']['data[id]']);
        $this->assertSame(['data[name]'], $detail['form']['editable_fields']);
    }

    /**
     * The portal's "Waktu Ujian Sudah Berakhir" is about an exam that has no
     * type and starts on 01-01-1970: nothing is scheduled yet, so nothing has
     * ended either.
     */
    #[DataProvider('examPages')]
    public function test_an_answer_is_not_posted_before_the_exam_is_scheduled(int $examId): void
    {
        $page = $this->examPage($examId);
        $this->assertStringContainsString('01-01-1970', $page);
        $this->assertStringContainsString('Waktu Ujian Sudah Berakhir', $page);

        $history = [];
        $result = $this->serviceAnswering([new Response(200, [], $page)], $history)
            ->submitExam('token-real', $examId, UploadedFile::fake()->create('jawaban.pdf', 64, 'application/pdf'));

        $this->assertCount(1, $history, 'Only the sendujian page may be read; nothing is posted.');
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertFalse($result['success']);
        $this->assertSame('upload_unavailable', $result['data']['status']);
        $this->assertSame('Portal belum membuka form upload untuk ujian ini.', $result['message']);
    }

    /**
     * The same real page with a start date (only that changed, in memory):
     * its closing notice, below the sidebar every SIPANDAI page breaks with
     * an unclosed `</span`, is read.
     */
    public function test_a_scheduled_exam_whose_time_is_over_is_reported_as_closed(): void
    {
        $page = str_replace(', 01-01-1970 /  Wib', 'Rabu, 21-10-2026 / 08:00 Wib', $this->examPage(9524), $replaced);
        $this->assertSame(1, $replaced);

        $history = [];
        $result = $this->serviceAnswering([new Response(200, [], (string) $page)], $history)
            ->submitExam('token-real', 9524, UploadedFile::fake()->create('jawaban.pdf', 64, 'application/pdf'));

        $this->assertCount(1, $history);
        $this->assertSame('upload_unavailable', $result['data']['status']);
        $this->assertSame('Waktu ujian sudah berakhir; portal tidak menerima jawaban lagi.', $result['message']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function examTypeInputs(): array
    {
        return [
            'first data[tipe] filled' => [1],
            'second data[tipe] filled' => [2],
        ];
    }

    /**
     * The real form renders `data[tipe]` twice; the same page with only
     * one of them filled in (in memory) is open for that exam, whichever
     * of the two it is.
     */
    #[DataProvider('examTypeInputs')]
    public function test_the_exam_type_is_read_from_whichever_of_the_two_type_inputs_is_filled(int $filledInput): void
    {
        $page = $this->examPage(9519);
        $this->assertSame(2, substr_count($page, '<input type="hidden" name="data[tipe]" value="" />'));

        $parts = explode('<input type="hidden" name="data[tipe]" value="" />', $page);
        $inputs = [1 => '<input type="hidden" name="data[tipe]" value="" />', 2 => '<input type="hidden" name="data[tipe]" value="" />'];
        $inputs[$filledInput] = '<input type="hidden" name="data[tipe]" value="UTS" />';
        $page = $parts[0].$inputs[1].$parts[1].$inputs[2].$parts[2];

        $history = [];
        $detail = $this->serviceAnswering([new Response(200, [], $page)], $history)->getExamDetail('token-real', 9519);

        $this->assertSame('UTS', $detail['exam_type']);
        $this->assertSame('not_uploaded', $detail['status']);
    }

    /**
     * Notes come from the exam's own content: the numbered PERHATIAN lines,
     * never the sidebar's "Ujian (UTS/UAS/SP)" menu item or its link.
     */
    #[DataProvider('examPages')]
    public function test_the_exam_notes_are_the_perhatian_lines_and_never_the_sidebar(int $examId): void
    {
        $history = [];
        $detail = $this->serviceAnswering([new Response(200, [], $this->examPage($examId))], $history)->getExamDetail('token-real', $examId);

        $this->assertSame([
            '1. Pastikan Jawaban Ujian Saudara Bisa di Preview, Jika Tidak Saudara Bisa lakukan Upload Ulang Dalam Waktu Batas Upload Jawaban',
            '2. Ujian Praktikum, File Jawaban Harus di Kompres Menggunakan Aplikasi Winzip atau Winrar dan menghasilkan file.zip atau file .rar',
            '3. Ujian Teori, File Jawaban Harus di convert ke jenis file PDF (Gunakan tool convert word to pdf atau tool lain) dan menghasilkan file.pdf',
        ], $detail['requirements']);
        $this->assertSame([], $detail['related_links'], 'No question file is uploaded yet; the menu link is not one.');
    }

    public function test_the_exam_list_rows_are_the_semesters_courses_without_schedule_or_upload_link(): void
    {
        $history = [];
        $exams = $this->serviceAnswering([new Response(200, [], $this->examListPage())], $history)->getExamList('token-real');

        $this->assertCount(1, $history);
        $this->assertSame(PortalEndpointRegistry::url('exam_list'), (string) $history[0]['request']->getUri());
        $this->assertSame(array_keys(self::COURSES), array_column($exams, 'course_code'));
        $this->assertSame(array_values(self::COURSES), array_column($exams, 'course_name'));

        foreach ($exams as $exam) {
            $this->assertNull($exam['id'], "{$exam['course_code']} has no exam link, so no id.");
            $this->assertNull($exam['upload_url']);
            $this->assertSame('', $exam['schedule'], 'No exam is scheduled yet.');
            $this->assertSame('', $exam['status']);
            $this->assertNotSame('', (string) $exam['lecturer'], 'The "Nama Dosen / Jadwal Ujian" column holds the lecturer.');
        }
    }

    /**
     * The exam page links no exam, so ids come from the class list (not
     * captured: its rows carry the ids the real meeting and exam pages show).
     * Every course keeps its own id, Data Governance and Teknik Penambangan
     * Data (DS052002/DS052001) or the two AI courses included, and none can
     * be uploaded.
     */
    public function test_class_list_ids_reach_the_real_exam_rows_without_two_courses_sharing_one(): void
    {
        $history = [];
        $submissionService = $this->serviceAnswering([new Response(200, [], $this->examListPage())], $history);
        $classesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->with('token-real')->andReturn(['classes' => [
                $this->classRow('DS052002', 'Data Governance', 9496, 5391, 5933),
                $this->classRow('SI033017', 'Statistik Inferensial', 9519),
                $this->classRow('SI013005', 'Sistem Enterprise', 9520, 5385, 5933),
                $this->classRow('SI032022', 'Manajemen Proyek', 9521, 5413, 2744),
                $this->classRow('SI033019', 'Artificial Intelligence and Basic Data Science', 9524),
                $this->classRow('SI033020', 'AI for Web Design Advance (PHP, database)', 9525, 5389, 6584),
            ]]);
        });

        $result = (new PortalExamListResolver($submissionService, $classesExtractor))->resolve('token-real');
        $idsByCode = array_column($result['exams'], 'id', 'course_code');

        $this->assertSame('exam_page', $result['source']);
        $this->assertSame([
            'SI053026' => null,
            'SI032022' => 9521,
            'SI033017' => 9519,
            'AI052001' => null,
            'DS052001' => null,
            'DS052002' => 9496,
            'SI013005' => 9520,
            'SI012002' => null,
            'SI033020' => 9525,
            'SI033019' => 9524,
        ], $idsByCode);

        $ids = array_values(array_filter($idsByCode));
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame([false], array_values(array_unique(array_column($result['exams'], 'can_upload'))));

        $dataGovernance = $result['exams'][array_search('DS052002', array_column($result['exams'], 'course_code'), true)];
        $this->assertSame(['id' => '5391', 'course_id' => 9496, 'dosen_id' => '5933'], $dataGovernance['class_reference']);
    }

    private function examPage(int $examId): string
    {
        $fixture = self::FIXTURES."exam_detail_{$examId}.html";
        $this->assertFixtureParsesLikeLivePortal($fixture);

        return (string) file_get_contents(base_path($fixture));
    }

    private function examListPage(): string
    {
        $fixture = self::FIXTURES.'exam_list.html';
        $this->assertFixtureParsesLikeLivePortal($fixture);

        return (string) file_get_contents(base_path($fixture));
    }

    /**
     * A row as SipandaiClassesExtractor reads it from the class list.
     *
     * @return array<string, mixed>
     */
    private function classRow(string $code, string $name, int $courseId, ?int $classId = null, ?int $dosenId = null): array
    {
        return [
            'course_info' => ['code' => $code, 'name' => $name],
            'schedule' => [],
            'join_link' => ['params' => array_filter([
                'id' => $classId !== null ? (string) $classId : null,
                'dosen_id' => $dosenId !== null ? (string) $dosenId : null,
                'course_id' => (string) $courseId,
            ])],
        ];
    }

    /**
     * A service whose portal answers with the given responses, in order.
     *
     * @param  list<Response|\Throwable>  $responses
     * @param  array<int, mixed>  $history
     */
    private function serviceAnswering(array $responses, array &$history): SipandaiSubmissionService
    {
        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')->with('token-real')->willReturn(CookieJar::fromArray([], 'portal.invalid'));
        $sessionManager->method('getSession')->willReturn(['username' => '20990000001']);

        $service = new SipandaiSubmissionService($sessionManager);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = new \ReflectionProperty(PortalScrapingService::class, 'client');
        $client->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }
}
