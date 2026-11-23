<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;

/**
 * The portal of the local demo mode (PORTAL_DEMO=true in APP_ENV=local) and
 * of the store reviewer account (PortalReviewerAccount): a Guzzle handler
 * that answers every portal request from the sanitized fixture pages of
 * tests/Fixtures/portal instead of the network.
 *
 * A request is matched to its endpoint in config/portal.php (same method,
 * same URL path, every default query value present), and the endpoint to a
 * fixture page (FIXTURES, PAGED, BY_ID). The write actions a student can
 * reach in the app (QR attendance, assignment upload and delete, exam
 * upload, password change) are answered with the portal's success pages
 * from tests/Fixtures/portal/demo, whatever was sent, so a reviewer can
 * finish every flow; nothing is written anywhere. An uploaded assignment
 * shows on its page (with its file and delete link) until it is deleted:
 * that one flag lives in the cache for a day, shared by every sandbox
 * session. A campus file (/portal/files/...) is answered with a generated
 * file of its type (a one-page PDF naming it, a 1x1 GIF, ...).
 *
 * A request nothing answers, such as the login page or an endpoint no flow
 * sends, is refused like a host that cannot be reached (a ConnectException
 * whose message names the endpoint), so no sandbox request ever waits on
 * the real portal. bootstrap/demo.php binds the handler as
 * PortalHttpClientOptions::HANDLER_BINDING; routes/demo.php signs the demo
 * student in.
 */
class PortalDemoFixtures
{
    /**
     * The student every demo session belongs to. Fixed and not a NIM, so a
     * demo session can never read a real student's data (owner keys derive
     * from the username).
     */
    public const USERNAME = 'demo-mahasiswa';

    /**
     * The identity the demo session shows: the student as the fixture pages
     * name them (profile.html, the class list) after sanitizing.
     *
     * @var array{name: string, nim: string}
     */
    public const USER_INFO = ['name' => 'Mahasiswa Contoh', 'nim' => '20990000001'];

    /**
     * How the refusal of a request without a fixture starts.
     */
    private const NO_FIXTURE = 'The demo mode has no fixture for';

    /**
     * Endpoints answered by one page whatever the query.
     *
     * @var array<string, string>
     */
    private const FIXTURES = [
        'dashboard' => 'student_dashboard_raw.html',
        'profile' => 'profile.html',
        'student_course' => 'student_course_raw.html',
        'student_finance' => 'student_finance_bills_raw.html',
        'sipandai_dashboard' => 'sipandai_dashboard.html',
        'course_materials' => 'sipandai_course_detail.html',
        'exam_list' => 'demo/exam_list.html',
        'password_form' => 'demo/password_form.html',
        'password_submit' => 'demo/password_success.html',
        'qr_attendance_page' => 'demo/attendance_qr_page.html',
        'attendance_submit' => 'demo/attendance_success.html',
        'attendance_qr_submit' => 'demo/attendance_success.html',
        'assignment_submit' => 'demo/assignment_upload_success.html',
        'assignment_delete' => 'demo/assignment_delete_success.html',
        'exam_submit' => 'demo/exam_upload_success.html',
        'student_credit' => 'student_credit_raw.html',
        'student_advising' => 'student_advising_raw.html',
        'student_krs_detail' => 'student_krs_detail_raw.html',
        'student_skkm' => 'student_skkm_filled.html',
        'student_simasita' => 'student_simasita_raw.html',
        'student_skripsi' => 'student_skripsi_raw.html',
        'student_ebook' => 'student_ebook_raw.html',
        'sipandai_score' => 'sipandai_score_raw.html',
    ];

    /**
     * Paged lists: the first page, and the page every later `page` gets
     * (the one whose paging ends the walk).
     *
     * @var array<string, array{string, string}>
     */
    private const PAGED = [
        'student_announcement' => ['student_announcement_page1_raw.html', 'student_announcement_page2_raw.html'],
        'student_repository' => ['student_repository_page1_raw.html', 'student_repository_last_raw.html'],
    ];

    /**
     * Pages per id: the first template that names an existing page for the
     * requested id (a demo page, else a capture of tests/Fixtures/portal/real),
     * else the first capture in name order, so every class, meeting and exam
     * the list pages link to opens. The demo exam is the one open exam (the
     * captured ones are all over), so its upload can be tried.
     *
     * @var array<string, array{list<string>, string}>
     */
    private const BY_ID = [
        'discussion' => [['real/meeting_{id}_{courses_id}.html'], 'real/meeting_*.html'],
        'assignment_form' => [['real/assignment_form_{id}_{courses_id}_{pertemuan}.html'], 'real/assignment_form_*.html'],
        'exam_upload_form' => [['demo/exam_detail_{id}.html', 'real/exam_detail_{id}.html'], 'real/exam_detail_*.html'],
    ];

    /**
     * How the cache key of an uploaded demo assignment starts; the rest is
     * `{courses_id}:{id}:{pertemuan}` of its meeting.
     */
    public const UPLOADED_ASSIGNMENT_CACHE_PREFIX = 'portal-demo:assignment-uploaded:';

    /**
     * How long an uploaded demo assignment keeps showing, in seconds.
     */
    private const UPLOADED_ASSIGNMENT_TTL = 86400;

    /**
     * The content type of a generated campus file, by extension.
     *
     * @var array<string, string>
     */
    private const FILE_TYPES = [
        'pdf' => 'application/pdf',
        'gif' => 'image/gif',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? base_path('tests/Fixtures/portal'), '/\\');
    }

    /**
     * Whether the demo mode is on: the local environment with the flag
     * (`portal.demo`, PORTAL_DEMO). Any other environment ignores the flag.
     */
    public static function enabledIn(Application $app): bool
    {
        return $app->environment('local') && (bool) $app->make('config')->get('portal.demo', false);
    }

    /**
     * Where the login page's demo button posts to (routes/demo.php), or
     * null when the demo is off or its route is missing (a route list
     * cached while the flag was off).
     */
    public static function signInUrl(Application $app): ?string
    {
        if (! self::enabledIn($app) || ! $app->make('router')->has('demo.session.start')) {
            return null;
        }

        return $app->make('url')->route('demo.session.start');
    }

    /**
     * The Guzzle handler: the fixture page of the request, or the refusal.
     *
     * @param  array<string, mixed>  $options
     */
    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        $file = $this->campusFileResponse($request);

        if ($file !== null) {
            return Create::promiseFor($file);
        }

        $endpoint = $this->endpointKeyFor($request);
        $query = $this->queryOf($request);
        $fixture = $endpoint === null ? null : $this->fixtureFor($endpoint, $query);

        if ($endpoint === null || $fixture === null) {
            return Create::rejectionFor(new ConnectException($this->refusal($request, $endpoint), $request));
        }

        $html = (string) file_get_contents($fixture);

        if ($endpoint === 'assignment_submit') {
            $this->rememberUploadedAssignment($request);
        } elseif ($endpoint === 'assignment_delete') {
            $this->forgetUploadedAssignment($query);
        } elseif ($endpoint === 'assignment_form') {
            $html = $this->withUploadedAssignment($html, $query);
        }

        return Create::promiseFor(new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html));
    }

    /**
     * A generated file for a GET (or HEAD, headers only) of a campus file
     * PortalUrlGuard allows; null for any other request.
     */
    private function campusFileResponse(RequestInterface $request): ?Response
    {
        $method = strtoupper($request->getMethod());
        $uri = (string) $request->getUri()->withQuery('')->withFragment('');

        if (! in_array($method, ['GET', 'HEAD'], true) || $request->getUri()->getQuery() !== '' || PortalUrlGuard::campusFileUrl($uri) === null) {
            return null;
        }

        $filename = rawurldecode(basename($request->getUri()->getPath()));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $body = match ($extension) {
            'gif' => (string) base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='),
            'pdf' => $this->samplePdf($filename),
            default => "Contoh berkas WeCIC (mode demo): {$filename}\n",
        };

        return new Response(200, [
            'Content-Type' => self::FILE_TYPES[$extension] ?? 'application/octet-stream',
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="'.str_replace(['"', '\\'], '', $filename).'"',
        ], $method === 'HEAD' ? '' : $body);
    }

    /**
     * A well-formed one-page PDF whose text names the file, so each file has
     * its own content and a reader finds text in it.
     */
    private function samplePdf(string $filename): string
    {
        $text = 'Contoh materi WeCIC (mode demo) - '.preg_replace('/[^A-Za-z0-9 ._-]/', '', $filename);
        $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * An assignment upload: its meeting (read from the upload form's URL in
     * the Referer, else from the multipart fields) shows the upload from now
     * on.
     */
    private function rememberUploadedAssignment(RequestInterface $request): void
    {
        parse_str((string) parse_url($request->getHeaderLine('Referer'), PHP_URL_QUERY), $referer);
        $body = (string) $request->getBody();
        $field = static function (string $name) use ($body): string {
            return preg_match('/name="data\['.$name.'\]"\r?\n(?:[^\r\n]+\r?\n)*\r?\n([^\r\n]*)/', $body, $match) === 1 ? trim($match[1]) : '';
        };

        $meeting = [
            'courses_id' => (string) ($referer['courses_id'] ?? $field('courses_id')),
            'id' => (string) ($referer['id'] ?? $field('id')),
            'pertemuan' => (string) ($referer['pertemuan'] ?? $field('pertemuan')),
        ];

        $key = $this->uploadedAssignmentKey($meeting['courses_id'], $meeting['id'], $meeting['pertemuan']);

        if ($key !== null) {
            Cache::put($key, true, self::UPLOADED_ASSIGNMENT_TTL);
        }
    }

    /**
     * An assignment delete (?act=jobdel&id={courses_id}&idx={id}&pertemuan=,
     * the link withUploadedAssignment() adds): its meeting goes back to the
     * plain page.
     *
     * @param  array<string, string>  $query
     */
    private function forgetUploadedAssignment(array $query): void
    {
        $key = $this->uploadedAssignmentKey($query['id'] ?? '', $query['idx'] ?? '', $query['pertemuan'] ?? '');

        if ($key !== null) {
            Cache::forget($key);
        }
    }

    /**
     * The upload form of a meeting with an uploaded demo assignment gets the
     * submitted file's link and its delete link, the way the portal lists a
     * submission.
     *
     * @param  array<string, string>  $query
     */
    private function withUploadedAssignment(string $html, array $query): string
    {
        $coursesId = $query['courses_id'] ?? '';
        $id = $query['id'] ?? '';
        $pertemuan = $query['pertemuan'] ?? '';
        $key = $this->uploadedAssignmentKey($coursesId, $id, $pertemuan);

        if ($key === null || ! Cache::has($key)) {
            return $html;
        }

        $submission = sprintf(
            '<p><a href="../files/tugasmhs/%1$s-%3$s-%4$s-demo.pdf" target="_blank">Open File</a> | <a href="?mod=sipandai&amp;act=jobdel&amp;id=%1$s&amp;idx=%2$s&amp;pertemuan=%3$s">Hapus</a></p>',
            $coursesId,
            $id,
            $pertemuan,
            self::USER_INFO['nim'],
        );

        return str_contains($html, '</body>') ? str_replace('</body>', $submission.'</body>', $html) : $html.$submission;
    }

    /**
     * The cache key of a meeting's uploaded demo assignment; null unless
     * every part is a plain number.
     */
    private function uploadedAssignmentKey(string $coursesId, string $id, string $pertemuan): ?string
    {
        foreach ([$coursesId, $id, $pertemuan] as $part) {
            if (preg_match('/^\d{1,10}$/', $part) !== 1) {
                return null;
            }
        }

        return self::UPLOADED_ASSIGNMENT_CACHE_PREFIX."{$coursesId}:{$id}:{$pertemuan}";
    }

    /**
     * The key of the endpoint in config/portal.php the request is for: the
     * one with the request's method and URL path whose default query values
     * the request all carries, the most specific (most values) first. Null
     * when no endpoint matches.
     */
    private function endpointKeyFor(RequestInterface $request): ?string
    {
        $method = strtoupper($request->getMethod()) === 'HEAD' ? 'GET' : strtoupper($request->getMethod());
        $target = $this->baseOf((string) $request->getUri());
        $query = $this->queryOf($request);
        $match = null;
        $matchedValues = -1;

        foreach (PortalEndpointRegistry::all() as $key => $definition) {
            $url = $definition['url'] ?? null;

            if (! is_string($url) || $url === '' || strtoupper((string) ($definition['method'] ?? 'GET')) !== $method) {
                continue;
            }

            [$base, $baseQuery] = $this->splitUrl($url);

            if ($base === null || $base !== $target) {
                continue;
            }

            $expected = $definition['default_query'] ?? $baseQuery;

            foreach ($expected as $name => $value) {
                if (($query[$name] ?? null) !== (string) $value) {
                    continue 2;
                }
            }

            if (count($expected) > $matchedValues) {
                $match = (string) $key;
                $matchedValues = count($expected);
            }
        }

        return $match;
    }

    /**
     * The fixture page that answers the endpoint with this query, as an
     * absolute path; null when the fixtures hold no page for it.
     *
     * @param  array<string, string>  $query
     */
    private function fixtureFor(string $endpoint, array $query = []): ?string
    {
        if (isset(self::FIXTURES[$endpoint])) {
            return $this->existing(self::FIXTURES[$endpoint]);
        }

        if (isset(self::PAGED[$endpoint])) {
            [$first, $later] = self::PAGED[$endpoint];

            return $this->existing(((int) ($query['page'] ?? 1)) > 1 ? $later : $first);
        }

        if (isset(self::BY_ID[$endpoint])) {
            [$templates, $fallback] = self::BY_ID[$endpoint];

            foreach ($templates as $template) {
                $named = $this->fillTemplate($template, $query);
                $page = $named === null ? null : $this->existing($named);

                if ($page !== null) {
                    return $page;
                }
            }

            return $this->firstMatching($fallback);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $query);
    }

    /**
     * `{name}` placeholders filled from the query; null when a value is
     * missing or is not a plain id (letters, digits, `-` and `_`), so a query
     * value can never name a file outside the fixture directory.
     *
     * @param  array<string, string>  $query
     */
    private function fillTemplate(string $template, array $query): ?string
    {
        $filled = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $match) use ($query): string {
            $value = $query[$match[1]] ?? '';

            return preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1 ? $value : "\0";
        }, $template);

        return is_string($filled) && ! str_contains($filled, "\0") ? $filled : null;
    }

    private function existing(string $relative): ?string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$relative;

        return is_file($path) ? $path : null;
    }

    private function firstMatching(string $pattern): ?string
    {
        $files = glob($this->directory.DIRECTORY_SEPARATOR.$pattern) ?: [];
        sort($files, SORT_STRING);

        return $files[0] ?? null;
    }

    /**
     * `scheme://host[:port]/path` of a URL, host lower-cased and the path
     * without its trailing slash, so the same page spelled two ways matches.
     */
    private function baseOf(string $url): ?string
    {
        return $this->splitUrl($url)[0];
    }

    /**
     * @return array{string|null, array<string, string>}
     */
    private function splitUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return [null, []];
        }

        $base = strtolower($parts['scheme'].'://'.$parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .rtrim($parts['path'] ?? '', '/');
        $query = [];

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return [$base, array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $query)];
    }

    private function refusal(RequestInterface $request, ?string $endpoint): string
    {
        $url = (string) $request->getUri()->withUserInfo('');
        $where = $endpoint === null
            ? 'no endpoint in config/portal.php matches it'
            : "portal endpoint [{$endpoint}] has no page in tests/Fixtures/portal";

        return sprintf('%s %s %s: %s. Nothing reaches the campus portal in demo mode.', self::NO_FIXTURE, $request->getMethod(), $url, $where);
    }
}
