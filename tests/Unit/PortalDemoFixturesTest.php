<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalDemoFixtures;
use App\Services\PortalEndpointRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * PortalDemoFixtures is the portal of the local demo mode: every portal
 * request is matched to its endpoint in config/portal.php and answered with
 * the fixture page of that endpoint, and a request no fixture answers is
 * refused like a host that cannot be reached, naming the endpoint.
 */
class PortalDemoFixturesTest extends TestCase
{
    private const NO_FIXTURE = 'The demo mode has no fixture for';

    /**
     * @return array<string, array{string, string, array<string, string|int>, string}>
     */
    public static function fixturePages(): array
    {
        return [
            'dashboard' => ['GET', 'dashboard', [], 'student_dashboard_raw.html'],
            'profile' => ['GET', 'profile', [], 'profile.html'],
            'schedule' => ['GET', 'student_course', [], 'student_course_raw.html'],
            'finance' => ['GET', 'student_finance', [], 'student_finance_bills_raw.html'],
            'LMS class list' => ['GET', 'sipandai_dashboard', [], 'sipandai_dashboard.html'],
            'class detail, whatever the class' => ['GET', 'course_materials', ['id' => '2343', 'dosen_id' => '4961', 'course_id' => '8987'], 'sipandai_course_detail.html'],
            'meeting that was captured' => ['GET', 'discussion', ['id' => '36365', 'courses_id' => '9496'], 'real/meeting_36365_9496.html'],
            'meeting that was not captured' => ['GET', 'discussion', ['id' => '30061', 'courses_id' => '8987'], 'real/meeting_36342_9521.html'],
            'assignment form that was captured' => ['GET', 'assignment_form', ['courses_id' => '9496', 'id' => '36365', 'pertemuan' => '3'], 'real/assignment_form_36365_9496_3.html'],
            'assignment form that was not captured' => ['GET', 'assignment_form', ['courses_id' => '8987', 'id' => '30061', 'pertemuan' => '9'], 'real/assignment_form_36342_9521_3.html'],
            'exam list, with the open demo exam' => ['GET', 'exam_list', [], 'demo/exam_list.html'],
            'the open demo exam' => ['GET', 'exam_upload_form', ['id' => '99001'], 'demo/exam_detail_99001.html'],
            'exam that was captured' => ['GET', 'exam_upload_form', ['id' => '9519'], 'real/exam_detail_9519.html'],
            'exam that was not captured' => ['GET', 'exam_upload_form', ['id' => '1'], 'real/exam_detail_9496.html'],
            'announcements, first page' => ['GET', 'student_announcement', [], 'student_announcement_page1_raw.html'],
            'announcements, second page' => ['GET', 'student_announcement', ['page' => 2], 'student_announcement_page2_raw.html'],
            'announcements, any later page' => ['GET', 'student_announcement', ['page' => 7], 'student_announcement_page2_raw.html'],
            'KRS' => ['GET', 'student_credit', [], 'student_credit_raw.html'],
            'advising' => ['GET', 'student_advising', [], 'student_advising_raw.html'],
            'KRS detail, whatever the semester' => ['GET', 'student_krs_detail', ['id' => '90002'], 'student_krs_detail_raw.html'],
            'SKKM' => ['GET', 'student_skkm', [], 'student_skkm_filled.html'],
            'thesis requirements' => ['GET', 'student_simasita', [], 'student_simasita_raw.html'],
            'thesis status' => ['GET', 'student_skripsi', [], 'student_skripsi_raw.html'],
            'library' => ['GET', 'student_ebook', [], 'student_ebook_raw.html'],
            'thesis repository, first page' => ['GET', 'student_repository', [], 'student_repository_page1_raw.html'],
            'thesis repository, last page' => ['GET', 'student_repository', ['page' => 2], 'student_repository_last_raw.html'],
            'grades' => ['GET', 'sipandai_score', [], 'sipandai_score_raw.html'],
            'password form' => ['GET', 'password_form', [], 'demo/password_form.html'],
            'password change' => ['POST', 'password_submit', [], 'demo/password_success.html'],
            'QR attendance page' => ['GET', 'qr_attendance_page', ['course_id' => '9496', 'id' => '36365'], 'demo/attendance_qr_page.html'],
            'attendance' => ['POST', 'attendance_submit', ['modulesid' => '36365', 'courseid' => '9496'], 'demo/attendance_success.html'],
            'assignment upload' => ['POST', 'assignment_submit', ['courses_id' => '9496', 'id' => '36365', 'pertemuan' => '3'], 'demo/assignment_upload_success.html'],
            'assignment delete' => ['GET', 'assignment_delete', ['id' => '1', 'idx' => '2', 'pertemuan' => '3'], 'demo/assignment_delete_success.html'],
            'exam upload' => ['POST', 'exam_submit', [], 'demo/exam_upload_success.html'],
        ];
    }

    /**
     * @param  array<string, string|int>  $query
     */
    #[DataProvider('fixturePages')]
    public function test_a_portal_page_is_answered_with_its_fixture(string $method, string $endpoint, array $query, string $fixture): void
    {
        $response = $this->answer(new PsrRequest($method, PortalEndpointRegistry::url($endpoint, $query)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringEqualsFile($this->fixture($fixture), (string) $response->getBody());
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function pagesWithoutAFixture(): array
    {
        return [
            'login page' => ['GET', 'login_page', []],
            'login' => ['POST', 'login', []],
            'logout' => ['GET', 'student_logout', []],
            'meeting conformity' => ['GET', 'meeting_conformity', ['subject' => 'SI101', 'pertemuan' => '3', 'courses' => '9496', 'dosen' => '1', 'sesuai' => 'ya', 'modules' => '36365']],
            'exam answer delete' => ['GET', 'exam_submission_delete', ['id' => '1']],
            'exam card' => ['GET', 'exam_card', []],
            'printable KRS' => ['GET', 'student_krs_print', []],
            'SKKM form' => ['GET', 'student_skkm_add_form', []],
            'SKKM upload' => ['POST', 'student_skkm_submit', []],
            'printable transcript' => ['GET', 'sipandai_transcript_print', []],
        ];
    }

    /**
     * The login pages, the write actions no flow of the app sends and the
     * pages never captured are refused at once, naming the endpoint; nothing is sent anywhere. The
     * SKKM form (GET) and its upload (POST) share one URL: the method tells
     * them apart.
     *
     * @param  array<string, string>  $query
     */
    #[DataProvider('pagesWithoutAFixture')]
    public function test_a_page_without_a_fixture_is_refused_like_an_unreachable_host(string $method, string $endpoint, array $query): void
    {
        $url = PortalEndpointRegistry::url($endpoint, $query);
        $request = new PsrRequest($method, $url);
        $refusal = $this->refusal($request);

        $this->assertSame(self::NO_FIXTURE." {$method} {$url}: portal endpoint [{$endpoint}] has no page in tests/Fixtures/portal. Nothing reaches the campus portal in demo mode.", $refusal->getMessage());
        $this->assertSame($request, $refusal->getRequest());
    }

    public function test_a_url_of_no_endpoint_is_refused_too(): void
    {
        $unknown = [
            new PsrRequest('GET', config('portal.base_url').'/portal/files/modul/materi.php'),
            new PsrRequest('GET', config('portal.base_url').'/portal/files/modul/materi.pdf?download=1'),
            new PsrRequest('POST', config('portal.base_url').'/portal/files/modul/materi.pdf'),
            // The schedule page, asked for with the wrong method.
            new PsrRequest('POST', PortalEndpointRegistry::url('student_course')),
            new PsrRequest('GET', 'https://example.test/'),
        ];

        foreach ($unknown as $request) {
            $this->assertStringStartsWith(
                self::NO_FIXTURE.' '.$request->getMethod().' '.$request->getUri().': no endpoint in config/portal.php matches it',
                $this->refusal($request)->getMessage()
            );
        }
    }

    /**
     * The same page spelled another way (HEAD, an upper-case host, a
     * trailing slash left out, the query in another order or with an extra
     * value) is still that page. The mahasiswa root with an act no endpoint
     * lists is the login page's URL, which has no fixture.
     */
    public function test_a_request_is_matched_by_method_path_and_default_query(): void
    {
        $dashboard = $this->fixture('student_dashboard_raw.html');

        $this->assertStringEqualsFile($dashboard, (string) $this->answer(new PsrRequest('HEAD', PortalEndpointRegistry::url('dashboard')))->getBody());
        $this->assertStringEqualsFile($dashboard, (string) $this->answer(new PsrRequest('GET', strtoupper((string) config('portal.base_url')).'/portal/mahasiswa?act=dashboard&mod=student&extra=1'))->getBody());

        $this->assertStringContainsString(
            'portal endpoint [login_page] has no page',
            $this->refusal(new PsrRequest('GET', config('portal.base_url').'/portal/mahasiswa/?mod=student&act=nothing'))->getMessage()
        );
    }

    /**
     * An id from the request never names a file outside the fixture
     * directory: a meeting id that is not a plain id gets the fallback page.
     */
    public function test_a_query_value_never_leaves_the_fixture_directory(): void
    {
        foreach (['../../../.env', '..', 'meeting_36365_9496.html/../../profile', '*'] as $id) {
            $response = $this->answer(new PsrRequest('GET', PortalEndpointRegistry::url('discussion', ['id' => $id, 'courses_id' => '9496'])));

            $this->assertStringEqualsFile($this->fixture('real/meeting_36342_9521.html'), (string) $response->getBody(), "id {$id}");
        }
    }

    public function test_a_fixture_directory_without_the_page_refuses_it(): void
    {
        $request = new PsrRequest('GET', PortalEndpointRegistry::url('dashboard'));
        $fixtures = new PortalDemoFixtures(sys_get_temp_dir().'/mycic-no-fixtures-'.uniqid());

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('portal endpoint [dashboard] has no page');

        $fixtures($request)->wait();
    }

    /**
     * A Guzzle client on the handler, as every portal client is built in
     * demo mode (bootstrap/demo.php), reads the fixtures and gets the
     * refusal as a connection error.
     */
    public function test_a_client_on_the_handler_reads_the_fixtures(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new PortalDemoFixtures)]);

        $this->assertStringEqualsFile($this->fixture('profile.html'), (string) $client->get(PortalEndpointRegistry::url('profile'))->getBody());

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage(self::NO_FIXTURE);

        $client->get(PortalEndpointRegistry::url('login_page'));
    }

    /**
     * An uploaded assignment shows on its meeting's upload form (its file
     * and a delete link naming the meeting) until that link deletes it; the
     * other meetings keep their plain page.
     */
    public function test_an_uploaded_assignment_shows_until_it_is_deleted(): void
    {
        $meeting = ['courses_id' => '9496', 'id' => '36365', 'pertemuan' => '3'];
        $formUrl = PortalEndpointRegistry::url('assignment_form', $meeting);
        $plain = (string) file_get_contents($this->fixture('real/assignment_form_36365_9496_3.html'));

        $this->assertSame($plain, (string) $this->answer(new PsrRequest('GET', $formUrl))->getBody());

        $this->answer(new PsrRequest('POST', self::assignmentUploadUrl(), ['Referer' => $formUrl]));

        $uploaded = (string) $this->answer(new PsrRequest('GET', $formUrl))->getBody();
        $this->assertStringContainsString('href="../files/tugasmhs/9496-3-20990000001-demo.pdf"', $uploaded);
        $this->assertStringContainsString('href="?mod=sipandai&amp;act=jobdel&amp;id=9496&amp;idx=36365&amp;pertemuan=3"', $uploaded);

        $other = PortalEndpointRegistry::url('assignment_form', ['courses_id' => '9525', 'id' => '37082', 'pertemuan' => '5']);
        $this->assertStringEqualsFile($this->fixture('real/assignment_form_37082_9525_5.html'), (string) $this->answer(new PsrRequest('GET', $other))->getBody());

        $this->answer(new PsrRequest('GET', PortalEndpointRegistry::url('assignment_delete', ['id' => '9496', 'idx' => '36365', 'pertemuan' => '3'])));

        $this->assertSame($plain, (string) $this->answer(new PsrRequest('GET', $formUrl))->getBody());
    }

    /**
     * Without the form's URL in the Referer, the meeting is read from the
     * multipart fields of the upload; an upload naming no meeting shows
     * nowhere.
     */
    public function test_an_upload_without_a_referer_is_read_from_its_fields(): void
    {
        $body = "--b\r\nContent-Disposition: form-data; name=\"data[courses_id]\"\r\n\r\n9525\r\n"
            ."--b\r\nContent-Disposition: form-data; name=\"data[id]\"\r\n\r\n37082\r\n"
            ."--b\r\nContent-Disposition: form-data; name=\"data[pertemuan]\"\r\n\r\n5\r\n--b--\r\n";

        $this->answer(new PsrRequest('POST', self::assignmentUploadUrl(), ['Content-Type' => 'multipart/form-data; boundary=b'], $body));
        $this->answer(new PsrRequest('POST', self::assignmentUploadUrl(), [], 'nothing'));

        $page = (string) $this->answer(new PsrRequest('GET', PortalEndpointRegistry::url('assignment_form', ['courses_id' => '9525', 'id' => '37082', 'pertemuan' => '5'])))->getBody();

        $this->assertStringContainsString('9525-5-20990000001-demo.pdf', $page);
        $this->assertSame(1, substr_count($page, 'act=jobdel'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function campusFiles(): array
    {
        return [
            'course material' => ['/portal/files/modul/IF101-mod-1.pdf', 'application/pdf'],
            'thesis colloquium with spaces' => ['/portal/files/skripsi/kolokium/TUGAS%20AKHIR-SIDANG-a1b2c.pdf', 'application/pdf'],
            'guide in the files root' => ['/portal/files/Panduan_Penulisan_Skripsi.pdf', 'application/pdf'],
            'student photo' => ['/portal/files/fotostudent/nopic.gif', 'image/gif'],
            'archive' => ['/portal/files/tugasmhs/9496-3-20990000001-demo.zip', 'application/zip'],
            'unknown type' => ['/portal/files/latihan/soal.xyz', 'application/octet-stream'],
        ];
    }

    /**
     * A campus file is a generated file of its type, never an HTML page; a
     * PDF is a well-formed one naming the file.
     */
    #[DataProvider('campusFiles')]
    public function test_a_campus_file_is_answered_with_a_generated_file(string $path, string $type): void
    {
        $response = $this->answer(new PsrRequest('GET', config('portal.base_url').$path));
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($type, $response->getHeaderLine('Content-Type'));
        $this->assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
        $this->assertNotSame('', $body);
        $this->assertStringContainsString('attachment; filename="'.rawurldecode(basename($path)).'"', $response->getHeaderLine('Content-Disposition'));

        if ($type === 'application/pdf') {
            $this->assertStringStartsWith('%PDF-1.4', $body);
            $this->assertStringContainsString('startxref', $body);
            $this->assertStringContainsString(preg_replace('/[^A-Za-z0-9 ._-]/', '', rawurldecode(basename($path))), $body);
        }
    }

    public function test_a_head_request_for_a_campus_file_gets_its_headers_only(): void
    {
        $response = $this->answer(new PsrRequest('HEAD', config('portal.base_url').'/portal/files/modul/IF101-mod-1.pdf'));

        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $response->getBody());
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function environmentsAndFlags(): array
    {
        return [
            'local with the flag' => ['local', true, true],
            'local without the flag' => ['local', false, false],
            'testing with the flag' => ['testing', true, false],
            'staging with the flag' => ['staging', true, false],
            'production with the flag' => ['production', true, false],
            'e2e with the flag' => ['e2e', true, false],
        ];
    }

    #[DataProvider('environmentsAndFlags')]
    public function test_the_demo_is_on_in_the_local_environment_with_the_flag_only(string $environment, bool $flag, bool $enabled): void
    {
        $this->app->detectEnvironment(static fn (): string => $environment);
        config(['portal.demo' => $flag]);

        $this->assertSame($enabled, PortalDemoFixtures::enabledIn($this->app));
    }

    /**
     * The login page shows the demo button only when its route exists: a
     * route list built while the demo was off (cached, or this test
     * application, booted as testing) has none, even once the flag is on.
     */
    public function test_there_is_no_sign_in_url_without_the_demo_route(): void
    {
        $this->assertNull(PortalDemoFixtures::signInUrl($this->app));

        $this->app->detectEnvironment(static fn (): string => 'local');
        config(['portal.demo' => true]);

        $this->assertTrue(PortalDemoFixtures::enabledIn($this->app));
        $this->assertNull(PortalDemoFixtures::signInUrl($this->app));
    }

    private function answer(RequestInterface $request): Response
    {
        $response = (new PortalDemoFixtures)($request)->wait();

        $this->assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function refusal(RequestInterface $request): ConnectException
    {
        try {
            (new PortalDemoFixtures)($request)->wait();
        } catch (ConnectException $exception) {
            return $exception;
        }

        $this->fail('The request was answered: '.$request->getMethod().' '.$request->getUri());
    }

    /**
     * Where an upload is posted: the upload form's action, which carries no
     * meeting.
     */
    private static function assignmentUploadUrl(): string
    {
        return config('portal.base_url').'/portal/sipandai/?mod=sipandai&act=sent';
    }

    private function fixture(string $relative): string
    {
        return base_path('tests/Fixtures/portal/'.$relative);
    }
}
