<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalUploadProgressed;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Services\PortalActionProjector;
use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalOperationTracker;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The assignment heuristics of SipandaiSubmissionService on real, sanitized
 * `act=send` pages (tests/Fixtures/portal/real), captured on Friday
 * 23 October 2026. Every page renders the upload form with its file input;
 * an open one ends in an Upload button, a closed one in "Waktu upload tugas
 * sudah berakhir". Below the form, the page lists the student's upload for
 * this meeting; no capture offers a delete (`jobdel`) link.
 */
class RealPortalAssignmentPagesTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURES = 'tests/Fixtures/portal/real/';

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.request_delay' => 0]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();

        parent::tearDown();
    }

    /**
     * @return array<string, array{int, int, int, string|null}>
     */
    public static function assignmentPages(): array
    {
        return [
            'Manajemen Proyek P3, uploaded' => [36342, 9521, 3, '../files/tugasmhs/9521-3-20990000001-1cd.pdf'],
            'Manajemen Proyek P4, uploaded' => [36620, 9521, 4, '../files/tugasmhs/9521-4-20990000001-648.pdf'],
            'Sistem Enterprise P4, uploaded' => [36689, 9520, 4, '../files/tugasmhs/9520-4-20990000001-3aa.pdf'],
            'AI for Web Design Advance P4, uploaded' => [36783, 9525, 4, '../files/tugasmhs/9525-4-20990000001-1dd.pdf'],
            'AI for Web Design Advance P5, open' => [37082, 9525, 5, null],
            'Data Governance P3, open until today' => [36365, 9496, 3, null],
            'Data Governance P4, closed' => [36633, 9496, 4, null],
            'Data Governance P5, closed' => [36957, 9496, 5, null],
        ];
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function closedAssignmentPages(): array
    {
        return [
            'Manajemen Proyek P3' => [36342, 9521, 3],
            'Manajemen Proyek P4' => [36620, 9521, 4],
            'Sistem Enterprise P4' => [36689, 9520, 4],
            'AI for Web Design Advance P4' => [36783, 9525, 4],
            'Data Governance P4' => [36633, 9496, 4],
            'Data Governance P5' => [36957, 9496, 5],
        ];
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function openAssignmentPages(): array
    {
        return [
            'Data Governance P3, due Jumat, 23 Oktober 2026' => [36365, 9496, 3],
            'AI for Web Design Advance P5, due Selasa, 27 Oktober 2026' => [37082, 9525, 5],
        ];
    }

    #[DataProvider('assignmentPages')]
    public function test_the_assignment_page_tells_whether_this_meeting_was_uploaded(int $meetingId, int $coursesId, int $meetingNumber, ?string $uploadedFile): void
    {
        $history = [];
        $service = $this->serviceAnswering([new Response(200, [], $this->assignmentPage($meetingId, $coursesId, $meetingNumber))], $history);

        $state = $service->inspectAssignmentSubmissionState('token-real', $coursesId, $meetingId, $meetingNumber);

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame(
            PortalEndpointRegistry::url('assignment_form', ['courses_id' => $coursesId, 'id' => $meetingId, 'pertemuan' => $meetingNumber]),
            (string) $history[0]['request']->getUri()
        );
        $this->assertSame($uploadedFile !== null ? 'uploaded' : 'not_uploaded', $state['status']);
        $this->assertSame($uploadedFile, $state['file_info']['href'] ?? null);

        if ($uploadedFile !== null) {
            $this->assertSame('Open File', $state['file_info']['text']);
        }

        // No capture renders a jobdel link: the upload list offers no delete once the upload has closed.
        $this->assertNull($state['delete_payload']);
    }

    #[DataProvider('closedAssignmentPages')]
    public function test_a_closed_assignment_is_not_posted_although_its_page_keeps_the_file_input(int $meetingId, int $coursesId, int $meetingNumber): void
    {
        $page = $this->assignmentPage($meetingId, $coursesId, $meetingNumber);
        $this->assertStringContainsString('type="file" name="data[tugas]"', $page);
        $this->assertStringContainsString('Waktu upload tugas sudah berakhir', $page);

        $history = [];
        $service = $this->serviceAnswering([new Response(200, [], $page)], $history);

        $result = $service->submitAssignment('token-real', $coursesId, $meetingId, $meetingNumber, $this->pdf());

        $this->assertCount(1, $history, 'Only the act=send page may be read; nothing is posted.');
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertFalse($result['success']);
        $this->assertSame('upload_unavailable', $result['data']['status']);
        $this->assertStringContainsString('sudah ditutup', $result['message']);
    }

    #[DataProvider('openAssignmentPages')]
    public function test_an_open_assignment_is_posted_with_the_hidden_fields_of_its_real_form(int $meetingId, int $coursesId, int $meetingNumber): void
    {
        $history = [];
        $service = $this->serviceAnswering([
            new Response(200, [], $this->assignmentPage($meetingId, $coursesId, $meetingNumber)),
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sent')),
        ], $history);

        $result = $service->submitAssignment('token-real', $coursesId, $meetingId, $meetingNumber, $this->pdf(), ['data[name]' => 'Tugas pertemuan']);

        $this->assertCount(2, $history);
        $post = $history[1]['request'];
        $this->assertSame('POST', $post->getMethod());
        $this->assertStringContainsString('act=sent', (string) $post->getUri());
        $this->assertSame([
            'data[courses_id]' => (string) $coursesId,
            'data[pertemuan]' => (string) $meetingNumber,
            'data[id]' => (string) $meetingId,
            'data[name]' => 'Tugas pertemuan',
            'cmd' => 'Upload',
            'pertemuan' => (string) $meetingNumber,
            'data[tugas]' => 'tugas.pdf',
        ], $this->multipartFields($post));

        // The portal never answered: the caller checks the assignment page instead of retrying.
        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['data']['status']);
        $this->assertTrue($result['data']['transport_error']);
    }

    public function test_the_upload_job_refuses_a_closed_real_assignment_without_advice_to_retry(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();
        Storage::put('portal-uploads/real-task.pdf', '%PDF-1.4 tugas');

        $history = [];
        $service = $this->serviceAnswering([new Response(200, [], $this->assignmentPage(36633, 9496, 4))], $history, 'campus-token');
        $projector = Mockery::mock(PortalActionProjector::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('recordAssignmentUploaded');
        });
        $tracker = app(PortalOperationTracker::class);

        (new ProcessPortalAssignmentUploadJob(
            'op-real-closed',
            'laravel-session',
            'campus-token',
            9496,
            36633,
            4,
            'portal-uploads/real-task.pdf',
            'tugas.pdf',
            'application/pdf'
        ))->handle($service, $tracker, app(PortalBroadcastService::class), Mockery::mock(PortalDatasetRepository::class), $projector);

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());

        $operation = $tracker->get('op-real-closed');
        $this->assertSame('failed', $operation['stage']);
        $this->assertStringContainsString('sudah ditutup', $operation['message']);
        $this->assertStringNotContainsString('unggah ulang', (string) $operation['next_step']);
        Storage::assertMissing('portal-uploads/real-task.pdf');
    }

    /**
     * The sidebar of every SIPANDAI page closes a span without its `>`;
     * strip_tags() dropped the whole page from there on.
     */
    public function test_the_whole_real_page_is_read_as_visible_text(): void
    {
        $page = $this->assignmentPage(36633, 9496, 4);
        $this->assertMatchesRegularExpression('#</span\s*\n\s*<span class="nav-link-text">#', $page);

        $text = $this->invokeNonPublicMethod($this->service(), 'visibleText', [$page]);

        $this->assertStringContainsString('ujian (uts/uas/sp)', $text);
        $this->assertStringContainsString('upload tugas pertemuan 4', $text);
        $this->assertStringContainsString('waktu upload tugas sudah berakhir', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    /**
     * A real page returned after a POST carries no verdict of its own, open
     * or closed, uploaded or not: the caller re-reads the assignment page.
     */
    #[DataProvider('assignmentPages')]
    public function test_a_real_assignment_page_given_back_after_a_post_is_no_verdict(int $meetingId, int $coursesId, int $meetingNumber): void
    {
        $result = $this->invokeNonPublicMethod($this->service(), 'buildUploadResultPayload', [
            $this->assignmentPage($meetingId, $coursesId, $meetingNumber),
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sent',
            ['course_id' => $coursesId, 'discussion_id' => $meetingId, 'meeting_number' => $meetingNumber],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['data']['status']);
    }

    private function assignmentPage(int $meetingId, int $coursesId, int $meetingNumber): string
    {
        $fixture = self::FIXTURES."assignment_form_{$meetingId}_{$coursesId}_{$meetingNumber}.html";
        $this->assertFixtureParsesLikeLivePortal($fixture);

        return (string) file_get_contents(base_path($fixture));
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('tugas.pdf', 64, 'application/pdf');
    }

    private function service(): SipandaiSubmissionService
    {
        return new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));
    }

    /**
     * A service whose portal answers with the given responses, in order.
     *
     * @param  list<Response|\Throwable>  $responses
     * @param  array<int, mixed>  $history
     */
    private function serviceAnswering(array $responses, array &$history, string $token = 'token-real'): SipandaiSubmissionService
    {
        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')->with($token)->willReturn(CookieJar::fromArray([], 'portal.invalid'));
        $sessionManager->method('getSession')->willReturn(['username' => '20990000001']);

        $service = new SipandaiSubmissionService($sessionManager);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = new \ReflectionProperty(PortalScrapingService::class, 'client');
        $client->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }

    /**
     * The multipart fields of a request: values, or the file name for a file part.
     *
     * @return array<string, string>
     */
    private function multipartFields(RequestInterface $request): array
    {
        preg_match('/boundary=("?)([^";]+)\1/', $request->getHeaderLine('Content-Type'), $boundary);
        $fields = [];

        foreach (explode('--'.$boundary[2], (string) $request->getBody()) as $part) {
            [$headers, $value] = array_pad(explode("\r\n\r\n", $part, 2), 2, null);

            if ($value === null || preg_match('/name="([^"]+)"(?:; filename="([^"]+)")?/', $headers, $matches) !== 1) {
                continue;
            }

            $fields[$matches[1]] = ($matches[2] ?? '') !== '' ? $matches[2] : substr($value, 0, -2);
        }

        return $fields;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokeNonPublicMethod(object $instance, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($instance, $method))->invokeArgs($instance, $arguments);
    }
}
