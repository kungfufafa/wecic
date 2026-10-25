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
            'exam list' => ['GET', 'exam_list', [], 'real/exam_list.html'],
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
            'password form' => ['GET', 'password_form', []],
            'attendance' => ['POST', 'attendance_submit', ['modulesid' => '36365', 'courseid' => '9496']],
            'assignment upload' => ['POST', 'assignment_submit', ['courses_id' => '9496', 'id' => '36365', 'pertemuan' => '3']],
            'assignment delete' => ['GET', 'assignment_delete', ['id' => '1', 'idx' => '2', 'pertemuan' => '3']],
            'exam upload' => ['POST', 'exam_submit', []],
            'SKKM form' => ['GET', 'student_skkm_add_form', []],
            'SKKM upload' => ['POST', 'student_skkm_submit', []],
            'printable transcript' => ['GET', 'sipandai_transcript_print', []],
        ];
    }

    /**
     * Write actions, the login pages and the pages never captured are
     * refused at once, naming the endpoint; nothing is sent anywhere. The
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
            new PsrRequest('GET', config('portal.base_url').'/portal/files/modul/materi.pdf'),
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

        $client->post(PortalEndpointRegistry::url('attendance_submit', ['modulesid' => '1', 'courseid' => '2']));
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

    private function fixture(string $relative): string
    {
        return base_path('tests/Fixtures/portal/'.$relative);
    }
}
