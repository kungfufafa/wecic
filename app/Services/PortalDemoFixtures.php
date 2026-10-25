<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\RequestInterface;

/**
 * The portal of the local demo mode (PORTAL_DEMO=true in APP_ENV=local): a
 * Guzzle handler that answers every portal request from the sanitized
 * fixture pages of tests/Fixtures/portal instead of the network.
 *
 * A request is matched to its endpoint in config/portal.php (same method,
 * same URL path, every default query value present), and the endpoint to a
 * fixture page (FIXTURES, PAGED, BY_ID). A request no fixture answers, such
 * as a write action or the login page, is refused like a host that cannot
 * be reached (a ConnectException whose message names the endpoint), so no
 * demo request ever waits on the real portal. bootstrap/demo.php binds the
 * handler as PortalHttpClientOptions::HANDLER_BINDING; routes/demo.php signs
 * the demo student in.
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
    public const NO_FIXTURE = 'The demo mode has no fixture for';

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
        'exam_list' => 'real/exam_list.html',
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
     * Pages captured per id (tests/Fixtures/portal/real): the capture of the
     * requested id when there is one, else the first capture in name order,
     * so every class, meeting and exam the list pages link to opens.
     *
     * @var array<string, array{string, string}>
     */
    private const BY_ID = [
        'discussion' => ['real/meeting_{id}_{courses_id}.html', 'real/meeting_*.html'],
        'assignment_form' => ['real/assignment_form_{id}_{courses_id}_{pertemuan}.html', 'real/assignment_form_*.html'],
        'exam_upload_form' => ['real/exam_detail_{id}.html', 'real/exam_detail_*.html'],
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
     * The Guzzle handler: the fixture page of the request, or the refusal.
     *
     * @param  array<string, mixed>  $options
     */
    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        $endpoint = $this->endpointKeyFor($request);
        $fixture = $endpoint === null ? null : $this->fixtureFor($endpoint, $this->queryOf($request));

        if ($fixture === null) {
            return Create::rejectionFor(new ConnectException($this->refusal($request, $endpoint), $request));
        }

        return Create::promiseFor(new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], (string) file_get_contents($fixture)));
    }

    /**
     * The key of the endpoint in config/portal.php the request is for: the
     * one with the request's method and URL path whose default query values
     * the request all carries, the most specific (most values) first. Null
     * when no endpoint matches.
     */
    public function endpointKeyFor(RequestInterface $request): ?string
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
    public function fixtureFor(string $endpoint, array $query = []): ?string
    {
        if (isset(self::FIXTURES[$endpoint])) {
            return $this->existing(self::FIXTURES[$endpoint]);
        }

        if (isset(self::PAGED[$endpoint])) {
            [$first, $later] = self::PAGED[$endpoint];

            return $this->existing(((int) ($query['page'] ?? 1)) > 1 ? $later : $first);
        }

        if (isset(self::BY_ID[$endpoint])) {
            [$template, $fallback] = self::BY_ID[$endpoint];
            $named = $this->fillTemplate($template, $query);

            return ($named === null ? null : $this->existing($named)) ?? $this->firstMatching($fallback);
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
