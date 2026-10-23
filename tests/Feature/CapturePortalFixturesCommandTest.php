<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\CapturePortalFixturesCommand;
use App\Services\PortalHttpClientOptions;
use App\Services\PortalReadOnlyFetcher;
use App\Services\PortalResponseRecorder;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LogicException;
use Mockery\MockInterface;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * F114: real fixtures of the pages the write-action heuristics read are
 * captured read-only, sanitized, and listed in the fixtures README.
 */
class CapturePortalFixturesCommandTest extends TestCase
{
    private const TOKEN = 'campus-session-token-0123456789abcdef';

    private const COOKIE = 'q1w2e3r4t5y6u7i8o9p0';

    private const NIM = '20231234567';

    private string $outputDirectory;

    private MockHandler $portal;

    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDirectory = storage_path('framework/testing/portal-fixtures-'.Str::random(12));
        config([
            'portal.request_delay' => 0,
            'portal.scraping.max_retries' => 1,
            'portal.scraping.retry_delay' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDirectory);

        parent::tearDown();
    }

    public function test_it_captures_meeting_assignment_and_exam_pages_sanitized_with_get_requests_only(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 09:30:15'));
        $this->givenSession();
        $this->givenPortal([
            new Response(200, [], $this->meetingPage()),
            new Response(200, [], $this->page('<form method="post" action="?mod=sipandai&amp;act=sent" enctype="multipart/form-data"><input type="hidden" name="_token" value="abc123def456ghi789"><input type="file" name="data[tugas]"></form>')),
            new Response(200, [], $this->page('<table><tr><td>Ujian Akhir Semester</td><td><a href="?mod=sipandai&amp;act=sendujian&amp;id=9195">Upload</a></td></tr></table>')),
            new Response(200, [], $this->page('<form method="post" action="?mod=sipandai&amp;act=sentujian" enctype="multipart/form-data"><input type="hidden" name="data[id]" value="9195"><input type="file" name="file"></form><a href="../files/tugasmhs/9195-UAS-'.self::NIM.'-131900.pdf">Open Jawaban Ujian</a>')),
        ]);

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('portal:capture-fixtures', [
            '--session' => self::TOKEN,
            '--meeting' => ['29282:8987'],
            '--exam' => ['9195'],
            '--output' => $this->outputDirectory,
        ]);
        $output = Artisan::output();

        $this->assertSame(CapturePortalFixturesCommand::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('Saved meeting_29282_8987.html: Meeting page (act=discuss), meeting 29282 of course 8987', $output);
        $this->assertStringContainsString('Saved assignment_form_29282_8987_3.html', $output);
        $this->assertStringContainsString('Saved exam_list.html', $output);
        $this->assertStringContainsString('Saved exam_detail_9195.html', $output);
        $this->assertStringContainsString('Captured 4 page(s)', $output);

        foreach ([self::TOKEN, self::COOKIE, self::NIM, 'Budi'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }

        $this->assertSame(['GET', 'GET', 'GET', 'GET'], array_map(fn (array $entry): string => $entry['request']->getMethod(), $this->history));
        $this->assertSame([
            ['mod' => 'sipandai', 'act' => 'discuss', 'id' => '29282', 'courses_id' => '8987'],
            ['mod' => 'sipandai', 'act' => 'send', 'courses_id' => '8987', 'id' => '29282', 'pertemuan' => '3'],
            ['mod' => 'sipandai', 'act' => 'ujian'],
            ['mod' => 'sipandai', 'act' => 'sendujian', 'id' => '9195'],
        ], array_map(fn (array $entry): array => $this->query($entry['request']), $this->history));
        $this->assertStringContainsString('PHPSESSID='.self::COOKIE, $this->history[0]['request']->getHeaderLine('Cookie'));

        $meeting = File::get($this->outputDirectory.'/meeting_29282_8987.html');
        $this->assertStringNotContainsString('Budi', $meeting);
        $this->assertStringNotContainsString(self::NIM, $meeting);
        $this->assertStringNotContainsString('budi.santoso@student.cic.ac.id', $meeting);
        $this->assertStringContainsString('Mahasiswa Uji ('.PortalResponseRecorder::NIM_PLACEHOLDER.')', $meeting);
        $this->assertStringContainsString('act=send&amp;courses_id=8987&amp;id=29282&amp;pertemuan=3', $meeting);

        $assignment = File::get($this->outputDirectory.'/assignment_form_29282_8987_3.html');
        $this->assertStringContainsString('name="_token" value="'.PortalResponseRecorder::TOKEN_PLACEHOLDER.'"', $assignment);
        $this->assertStringContainsString('<input type="file" name="data[tugas]">', $assignment);

        $this->assertFileExists($this->outputDirectory.'/exam_list.html');
        $examDetail = File::get($this->outputDirectory.'/exam_detail_9195.html');
        $this->assertStringContainsString('9195-UAS-'.PortalResponseRecorder::NIM_PLACEHOLDER.'-131900.pdf', $examDetail);

        $readme = File::get($this->outputDirectory.'/README.md');
        $this->assertStringContainsString('# Real portal fixtures', $readme);
        $this->assertStringContainsString('| `assignment_form_29282_8987_3.html` | Assignment upload page (act=send), meeting 29282 of course 8987, pertemuan 3 | 2026-10-23T09:30:15+07:00 |', $readme);
        $this->assertStringContainsString('| `exam_detail_9195.html` | Exam detail and upload page (act=sendujian), exam 9195 | 2026-10-23T09:30:15+07:00 |', $readme);
        $this->assertStringContainsString('| `exam_list.html` | Exam list (act=ujian) | 2026-10-23T09:30:15+07:00 |', $readme);
        $this->assertStringContainsString('| `meeting_29282_8987.html` | Meeting page (act=discuss), meeting 29282 of course 8987 | 2026-10-23T09:30:15+07:00 |', $readme);
    }

    public function test_the_readme_keeps_the_rows_already_listed_and_updates_recaptured_files(): void
    {
        File::ensureDirectoryExists($this->outputDirectory);
        File::put($this->outputDirectory.'/README.md', implode("\n", [
            '# Real portal fixtures',
            '',
            'Notes written by hand.',
            '',
            CapturePortalFixturesCommand::README_START,
            '| File | What it is | Captured at |',
            '| --- | --- | --- |',
            '| `attendance_submit_reply.html` | Hand-copied attendance reply | 2026-10-01T08:00:00+00:00 |',
            '| `exam_list.html` | Exam list (act=ujian) | 2026-10-01T08:00:00+00:00 |',
            CapturePortalFixturesCommand::README_END,
            '',
            'Footer kept.',
            '',
        ]));
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $this->givenSession();
        $this->givenPortal([new Response(200, [], $this->page('<table><tr><td>Ujian</td></tr></table>'))]);

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--output' => $this->outputDirectory])
            ->assertSuccessful();

        $this->assertSame(implode("\n", [
            '# Real portal fixtures',
            '',
            'Notes written by hand.',
            '',
            CapturePortalFixturesCommand::README_START,
            '| File | What it is | Captured at |',
            '| --- | --- | --- |',
            '| `attendance_submit_reply.html` | Hand-copied attendance reply | 2026-10-01T08:00:00+00:00 |',
            '| `exam_list.html` | Exam list (act=ujian) | 2026-10-23T10:00:00+07:00 |',
            CapturePortalFixturesCommand::README_END,
            '',
            'Footer kept.',
            '',
        ]), File::get($this->outputDirectory.'/README.md'));
    }

    public function test_the_shipped_readme_has_the_table_the_command_rewrites(): void
    {
        $readme = File::get(base_path('tests/Fixtures/portal/real/README.md'));

        $this->assertStringContainsString(CapturePortalFixturesCommand::README_START, $readme);
        $this->assertStringContainsString(CapturePortalFixturesCommand::README_END, $readme);
        $this->assertStringContainsString('portal:capture-fixtures', $readme);
    }

    public function test_an_explicit_meeting_number_captures_the_assignment_page_of_a_meeting_without_upload_link(): void
    {
        $this->givenSession();
        $this->givenPortal([
            new Response(200, [], $this->page('<p>Tidak ada tugas perkuliahan.</p><a href="?mod=sipandai&amp;act=send&amp;courses_id=8987&amp;id=11111&amp;pertemuan=9">Tugas pertemuan lain</a>')),
            new Response(200, [], $this->page('<p>Tidak ada tugas perkuliahan.</p>')),
            new Response(200, [], $this->page('<p>Upload tugas.</p>')),
            new Response(200, [], $this->page('<table><tr><td>Ujian</td></tr></table>')),
        ]);

        $this->artisan('portal:capture-fixtures', [
            '--session' => self::TOKEN,
            '--meeting' => ['29282:8987', '29283:8987:4'],
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('Meeting 29282 links no assignment upload page; pass 29282:8987:<pertemuan>')
            ->assertSuccessful();

        $this->assertSame([
            ['mod' => 'sipandai', 'act' => 'discuss', 'id' => '29282', 'courses_id' => '8987'],
            ['mod' => 'sipandai', 'act' => 'discuss', 'id' => '29283', 'courses_id' => '8987'],
            ['mod' => 'sipandai', 'act' => 'send', 'courses_id' => '8987', 'id' => '29283', 'pertemuan' => '4'],
            ['mod' => 'sipandai', 'act' => 'ujian'],
        ], array_map(fn (array $entry): array => $this->query($entry['request']), $this->history));
        $this->assertFileDoesNotExist($this->outputDirectory.'/assignment_form_29282_8987_9.html');
        $this->assertFileExists($this->outputDirectory.'/assignment_form_29283_8987_4.html');
    }

    public function test_an_endpoint_registered_with_another_method_than_get_is_refused_before_any_request(): void
    {
        config(['portal.endpoints.exam_list.method' => 'POST']);
        $this->givenSession();
        $this->givenPortal([new Response(200, [], $this->page('<p>never sent</p>'))]);

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--output' => $this->outputDirectory])
            ->expectsOutputToContain('Refused a POST request: portal fixtures are captured with GET requests only.')
            ->assertFailed();

        $this->assertSame([], $this->history);
        $this->assertSame(1, $this->portal->count());
        $this->assertFileDoesNotExist($this->outputDirectory.'/exam_list.html');
    }

    public function test_the_fetcher_refuses_every_method_but_get_before_and_inside_its_client(): void
    {
        $this->givenSession();
        $this->givenPortal([new Response(200, [], $this->page('<p>never sent</p>'))]);
        $fetcher = app(PortalReadOnlyFetcher::class);
        $fetcher->setSession(self::TOKEN);
        $client = $this->clientOf($fetcher);

        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'post'] as $method) {
            try {
                $fetcher->fetch('https://portal.invalid/portal/sipandai/?mod=sipandai&act=sentujian', $method);
                $this->fail("fetch() sent a {$method} request.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Refused a '.strtoupper($method).' request', $exception->getMessage());
            }

            try {
                $client->request($method, 'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sentujian');
                $this->fail("The fetcher's client sent a {$method} request.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Refused a '.strtoupper($method).' request', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->history);
        $this->assertSame(1, $this->portal->count());
    }

    public function test_the_fetcher_guards_a_client_whose_handler_is_a_plain_callable(): void
    {
        $sent = [];
        $this->app->instance(PortalHttpClientOptions::HANDLER_BINDING, function (RequestInterface $request) use (&$sent) {
            $sent[] = $request->getMethod();

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, [], 'ok'));
        });

        $client = $this->clientOf(new PortalReadOnlyFetcher($this->createMock(PortalSessionManager::class)));

        $this->assertSame('ok', (string) $client->request('GET', 'https://portal.invalid/portal/')->getBody());
        $this->expectException(LogicException::class);

        try {
            $client->request('POST', 'https://portal.invalid/portal/');
        } finally {
            $this->assertSame(['GET'], $sent);
        }
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('isSessionUsable');
            $mock->shouldNotReceive('getSessionCookieJar');
        });
        $this->givenPortal([new Response(200, [], $this->page('<p>never sent</p>'))]);

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--output' => $this->outputDirectory])
            ->expectsOutputToContain('portal:capture-fixtures does not run in production')
            ->assertFailed();

        $this->assertSame(1, $this->portal->count());
        $this->assertDirectoryDoesNotExist($this->outputDirectory);
    }

    public function test_it_stops_with_a_clear_message_when_the_session_is_not_usable(): void
    {
        $this->mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isSessionUsable')->with(self::TOKEN)->andReturnFalse();
            $mock->shouldNotReceive('getSessionCookieJar');
        });
        $this->givenPortal([new Response(200, [], $this->page('<p>never sent</p>'))]);

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--output' => $this->outputDirectory]);
        $output = Artisan::output();

        $this->assertSame(CapturePortalFixturesCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('The campus session is not usable (expired, logged out or unknown).', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);

        $this->assertSame(1, $this->portal->count());
    }

    public function test_it_stops_with_a_clear_message_for_an_offline_session(): void
    {
        $this->givenSession(['offline' => true]);
        $this->givenPortal([new Response(200, [], $this->page('<p>never sent</p>'))]);

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--output' => $this->outputDirectory])
            ->expectsOutputToContain('offline (archive) session and holds no portal cookies')
            ->assertFailed();

        $this->assertSame(1, $this->portal->count());
    }

    public function test_it_stops_before_reading_anything_when_the_session_lacks_the_students_name(): void
    {
        $this->givenSession(['user_info' => ['student_id' => self::NIM, 'email' => 'budi.santoso@student.cic.ac.id']], readsCookies: false);
        $this->givenPortal([new Response(200, [], $this->page('<p>Budi Santoso</p>'))]);

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('portal:capture-fixtures', [
            '--session' => self::TOKEN,
            '--exam' => ['9195'],
            '--output' => $this->outputDirectory,
        ]);
        $output = Artisan::output();

        $this->assertSame(CapturePortalFixturesCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('Nothing was captured: the campus session holds no student name to sanitize the pages with', $output);
        $this->assertStringNotContainsString(self::NIM, $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertSame([], $this->history);
        $this->assertSame(1, $this->portal->count());
        $this->assertDirectoryDoesNotExist($this->outputDirectory);
    }

    public function test_a_real_login_without_user_info_is_sanitized_with_the_stored_profile(): void
    {
        $this->givenSession(['user_info' => []]);
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'profile', [
            'user_info' => [],
            'profile' => [
                'personal_data' => ['nama' => 'Budi Santoso', 'nim' => self::NIM, 'angkatan' => '2025', 'tempat_lahir' => 'Sukabumi', 'status' => 'Aktif'],
                'contact_data' => ['email' => 'budi.santoso@student.cic.ac.id', 'hp' => '089512345678', 'alamat_rumah' => 'Jl. Mawar Indah No. 7', 'kota' => 'Kota Bandung'],
                'education_data' => ['nisn' => '-', 'asal_sekolah' => 'SMAN 3 Sukabumi'],
            ],
        ]);
        $this->givenPortal([
            new Response(200, [], $this->page('<p>Budi Santoso ('.self::NIM.') lahir di Sukabumi, tinggal di Jl. Mawar Indah No. 7, Kota Bandung. HP 089512345678, budi.santoso@student.cic.ac.id, alumni SMAN 3 Sukabumi. Angkatan 2025, status Aktif, T.A 2025/2026.</p>')),
        ]);

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('portal:capture-fixtures', [
            '--session' => self::TOKEN,
            '--output' => $this->outputDirectory,
        ]);
        $output = Artisan::output();

        $this->assertSame(CapturePortalFixturesCommand::SUCCESS, $exitCode, $output);
        $page = File::get($this->outputDirectory.'/exam_list.html');

        foreach (['Budi', self::NIM, 'budi.santoso@student.cic.ac.id', '089512345678', 'Jl. Mawar Indah', 'SMAN 3 Sukabumi'] as $identifying) {
            $this->assertStringNotContainsString($identifying, $page);
        }

        $this->assertStringContainsString('Mahasiswa Uji ('.PortalResponseRecorder::NIM_PLACEHOLDER.')', $page);
        $this->assertStringContainsString('Kota Bandung', $page);
        $this->assertStringContainsString('Angkatan 2025, status Aktif, T.A 2025/2026.', $page);
    }

    public function test_no_captured_page_keeps_a_lecturers_name(): void
    {
        $this->givenSession();
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'lms_classes', ['classes' => [
            ['course_info' => ['code' => 'DS052002', 'name' => 'Data Governance'], 'lecturer_info' => ['name' => 'Rina Wulandari, M.Kom.']],
        ]]);
        $this->givenPortal([
            new Response(200, [], '<!DOCTYPE html><html><head><meta name="author" content="Hendra Gunawan"></head><body><table>'
                .'<tr><th>No.</th><th>Nama Mata Kuliah</th><th>Nama Dosen / Jadwal Ujian</th></tr>'
                .'<tr><td>1.</td><td><b>DS052002<br/>Data Governance</b></td><td><b>Rina Wulandari, M.Kom.</b><br/></td></tr>'
                .'<tr><td>2.</td><td><b>SI033017<br/>Statistik Inferensial</b></td><td><b>Agus Prasetyo, M.Pd.</b><br/></td></tr>'
                .'</table></body></html>'),
            new Response(200, [], '<!DOCTYPE html><html><body><table>'
                .'<tr><td>Nama Mata Kuliah</td><td>:</td><td><b>SI033017 - Statistik Inferensial</b></td></tr>'
                .'<tr><td>Dosen</td><td>:</td><td><b>Agus Prasetyo, M.Pd.</b></td></tr>'
                .'</table><p>Ditanyakan ke Bu Rina Wulandari.</p></body></html>'),
        ]);

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('portal:capture-fixtures', [
            '--session' => self::TOKEN,
            '--exam' => ['9519'],
            '--output' => $this->outputDirectory,
        ]);

        $this->assertSame(CapturePortalFixturesCommand::SUCCESS, $exitCode, Artisan::output());

        $examList = File::get($this->outputDirectory.'/exam_list.html');
        $examDetail = File::get($this->outputDirectory.'/exam_detail_9519.html');

        foreach (['Rina', 'Wulandari', 'Agus', 'Prasetyo', 'Hendra', 'Gunawan'] as $name) {
            $this->assertStringNotContainsString($name, $examList.$examDetail);
        }

        // The class list's lecturer is "Dosen Uji A" in every page; the others follow in each page.
        $this->assertStringContainsString('<b>Dosen Uji A, M.Kom.</b>', $examList);
        $this->assertStringContainsString('Ditanyakan ke Bu Dosen Uji A.', $examDetail);
        $this->assertStringContainsString('<b>Dosen Uji B, M.Pd.</b>', $examDetail);
        $this->assertStringContainsString('Statistik Inferensial', $examDetail);
    }

    public function test_it_stops_before_reading_anything_when_the_session_lacks_the_students_nim(): void
    {
        $this->givenSession(['username' => '', 'user_info' => ['name' => 'Budi Santoso']], readsCookies: false);
        $this->givenPortal([new Response(200, [], $this->page('<p>'.self::NIM.'</p>'))]);

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--output' => $this->outputDirectory])
            ->expectsOutputToContain('Nothing was captured: the campus session holds no NIM/username to sanitize the pages with')
            ->doesntExpectOutputToContain('Budi')
            ->assertFailed();

        $this->assertSame([], $this->history);
        $this->assertDirectoryDoesNotExist($this->outputDirectory);
    }

    public function test_it_stops_when_the_portal_answers_with_its_login_page(): void
    {
        $this->givenSession(invalidated: true);
        $this->givenPortal([
            new Response(200, [], '<html><body><form action="?mod=loginact" method="post"><input type="text" name="txt_username"><input type="password" name="txt_password"></form></body></html>'),
            new Response(200, [], $this->page('<p>never read</p>')),
        ]);

        $this->artisan('portal:capture-fixtures', [
            '--session' => self::TOKEN,
            '--exam' => ['9195'],
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('The portal answered with its login page: the campus session has expired.')
            ->assertFailed();

        $this->assertCount(1, $this->history);
        $this->assertSame(1, $this->portal->count());
        $this->assertFileDoesNotExist($this->outputDirectory.'/exam_list.html');
        $this->assertFileDoesNotExist($this->outputDirectory.'/README.md');
    }

    public function test_a_page_that_cannot_be_read_is_reported_and_the_rest_is_still_captured(): void
    {
        $this->givenSession();
        $this->givenPortal([
            new Response(200, [], $this->page('<table><tr><td>Ujian</td></tr></table>')),
            new Response(500, [], 'Fatal error for '.self::NIM.' Budi Santoso'),
        ]);

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('portal:capture-fixtures', [
            '--session' => self::TOKEN,
            '--exam' => ['9195'],
            '--output' => $this->outputDirectory,
        ]);
        $output = Artisan::output();

        $this->assertSame(CapturePortalFixturesCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('Could not read exam_detail_9195.html: Network error', $output);
        $this->assertStringContainsString('Fatal error for '.PortalResponseRecorder::NIM_PLACEHOLDER.' Mahasiswa Uji', $output);
        $this->assertStringContainsString('1 page(s) could not be captured.', $output);
        $this->assertStringNotContainsString(self::NIM, $output);
        $this->assertStringNotContainsString('Budi', $output);

        $this->assertFileExists($this->outputDirectory.'/exam_list.html');
        $this->assertFileDoesNotExist($this->outputDirectory.'/exam_detail_9195.html');
        $this->assertStringContainsString('`exam_list.html`', File::get($this->outputDirectory.'/README.md'));
        $this->assertStringNotContainsString('exam_detail_9195.html', File::get($this->outputDirectory.'/README.md'));
    }

    public function test_options_are_checked_before_anything_is_read(): void
    {
        $this->mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('isSessionUsable');
        });
        $this->givenPortal([new Response(200, [], $this->page('<p>never sent</p>'))]);

        $this->artisan('portal:capture-fixtures', ['--output' => $this->outputDirectory])
            ->expectsOutputToContain('Pass --session=<campus session token>')
            ->assertFailed();

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--meeting' => ['29282'], '--output' => $this->outputDirectory])
            ->expectsOutputToContain('--meeting=29282 is not meeting_id:courses_id')
            ->assertFailed();

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--meeting' => ['29282:abc'], '--output' => $this->outputDirectory])
            ->expectsOutputToContain('--meeting=29282:abc is not meeting_id:courses_id')
            ->assertFailed();

        $this->artisan('portal:capture-fixtures', ['--session' => self::TOKEN, '--exam' => ['9195; rm -rf'], '--output' => $this->outputDirectory])
            ->expectsOutputToContain('is not an exam id')
            ->assertFailed();

        $this->assertSame(1, $this->portal->count());
    }

    /**
     * @param  array<string, mixed>  $sessionOverrides
     */
    private function givenSession(array $sessionOverrides = [], bool $invalidated = false, bool $readsCookies = true): void
    {
        $session = [
            'token' => self::TOKEN,
            'username' => self::NIM,
            'user_info' => [
                'name' => 'Budi Santoso',
                'student_id' => self::NIM,
                'email' => 'budi.santoso@student.cic.ac.id',
            ],
            'cookies' => [['Name' => 'PHPSESSID', 'Value' => self::COOKIE, 'Domain' => 'portal.invalid', 'Path' => '/']],
            ...$sessionOverrides,
        ];

        $this->mock(PortalSessionManager::class, function (MockInterface $mock) use ($session, $invalidated, $readsCookies): void {
            $mock->shouldReceive('isSessionUsable')->with(self::TOKEN)->andReturnTrue();
            $mock->shouldReceive('getSession')->with(self::TOKEN)->andReturn($session);

            if ($readsCookies) {
                $mock->shouldReceive('getSessionCookieJar')->with(self::TOKEN)->andReturn(CookieJar::fromArray(['PHPSESSID' => self::COOKIE], 'portal.invalid'));
            } else {
                $mock->shouldNotReceive('getSessionCookieJar');
            }

            if ($invalidated) {
                $mock->shouldReceive('invalidateSession')->once()->withArgs(fn (string $token, string $reason): bool => $token === self::TOKEN && $reason === 'login_page_detected');
            } else {
                $mock->shouldNotReceive('invalidateSession');
            }
        });
    }

    /**
     * Every portal client of the test sends through this mock: nothing
     * leaves the test.
     *
     * @param  list<Response>  $responses
     */
    private function givenPortal(array $responses): void
    {
        $this->portal = new MockHandler($responses);
        $stack = HandlerStack::create($this->portal);
        $stack->push(Middleware::history($this->history));

        $this->app->instance(PortalHttpClientOptions::HANDLER_BINDING, $stack);
    }

    private function clientOf(PortalReadOnlyFetcher $fetcher): Client
    {
        $property = new \ReflectionProperty(PortalScrapingService::class, 'client');

        return $property->getValue($fetcher);
    }

    /**
     * @return array<string, string>
     */
    private function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    private function meetingPage(): string
    {
        return $this->page(<<<'HTML'
            <ol class="breadcrumb"><li>Budi Santoso (20231234567)</li></ol>
            <a href="mailto:budi.santoso@student.cic.ac.id">budi.santoso@student.cic.ac.id</a>
            <div class="card"><div class="content"><table>
                <tr><td>Upload Tugas</td><td>:</td><td><a href="?mod=sipandai&amp;act=send&amp;courses_id=8987&amp;id=11111&amp;pertemuan=9">Upload tugas pertemuan lain</a></td></tr>
                <tr><td>Upload Tugas</td><td>:</td><td><a href="?mod=sipandai&amp;act=send&amp;courses_id=8987&amp;id=29282&amp;pertemuan=3">Upload</a></td></tr>
            </table></div></div>
            HTML);
    }

    private function page(string $content): string
    {
        return <<<HTML
            <html>
                <head><title>SIPANDAI</title></head>
                <body>
                    <a href="?mod=logout">Keluar</a>
                    {$content}
                </body>
            </html>
            HTML;
    }
}
