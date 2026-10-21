<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalScrapingException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

abstract class PortalScrapingService
{
    /**
     * Timezone of every date the portal prints (WIB).
     */
    protected const PORTAL_TIMEZONE = 'Asia/Jakarta';

    /**
     * Month names and abbreviations used by the portal and its staff, mapped
     * to their month number. English names cover copy-pasted announcements.
     *
     * @var array<string, int>
     */
    private const MONTH_NUMBERS = [
        'januari' => 1, 'january' => 1, 'jan' => 1,
        'februari' => 2, 'pebruari' => 2, 'february' => 2, 'feb' => 2, 'peb' => 2,
        'maret' => 3, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5, 'may' => 5,
        'juni' => 6, 'june' => 6, 'jun' => 6,
        'juli' => 7, 'july' => 7, 'jul' => 7,
        'agustus' => 8, 'august' => 8, 'agus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9,
        'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nopember' => 11, 'nov' => 11, 'nop' => 11,
        'desember' => 12, 'december' => 12, 'des' => 12, 'dec' => 12,
    ];

    /**
     * @var list<string>
     */
    private const DAY_NAMES = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', "jum'at", 'jum’at', 'sabtu', 'minggu', 'ahad'];

    /**
     * Separators between the two ends of a date range ("sd", "s.d.", "s/d", "-", ...).
     */
    private const DATE_RANGE_SEPARATOR = '(?:s\.?\s?d\.?|s\/d|sampai(?:\s+dengan)?|hingga|[-–—~])';

    /**
     * Words that, right before a bare number, mark it as an ordinal instead of
     * the first day of a range ("Tahap 4 - 24 Juni 2024").
     */
    private const NON_DAY_NUMBER_PREFIX = '(?<!\p{L})(?:ke|tahap|no\.?|nomor|poin|langkah|pertemuan|semester|sesi|gelombang|bab|pasal|sks)\s*$';

    protected Client $client;

    protected PortalSessionManager $sessionManager;

    protected ?CookieJar $cookieJar = null;

    protected ?string $sessionToken = null;

    /**
     * Cookies attached to this process when the session was adopted; used to
     * detect that another worker rotated the session before an invalidation.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $attachedSessionCookies = null;

    public function __construct(PortalSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
        $this->client = new Client(array_replace_recursive([
            // Web requests run inside PHP-FPM's short execution window, so they
            // get a tighter timeout than queue workers.
            RequestOptions::TIMEOUT => $this->isWebContext()
                ? (int) config('portal.scraping.web_request_timeout', 15)
                : (int) config('portal.request_timeout', 30),
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'User-Agent' => config('portal.scraping.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ],
            // Like a browser, a redirect after a POST is followed with a GET:
            // re-sending the POST would submit attendance or uploads twice.
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'track_redirects' => true,
            ],
        ], PortalHttpClientOptions::secureDns()));
    }

    /**
     * Set the session for scraping operations
     *
     * The cookie jar is loaded once per request/job scope and shared by every
     * scraper in it; adopting a session no longer writes to the session store.
     */
    public function setSession(string $sessionToken): void
    {
        $this->sessionToken = $sessionToken;
        $this->cookieJar = $this->sessionContext()->cookieJar(
            $sessionToken,
            fn (): ?CookieJar => $this->sessionManager->getSessionCookieJar($sessionToken)
        );

        if (! $this->cookieJar) {
            $this->invalidateCurrentSession('cookie_jar_missing', [
                'source' => static::class,
            ]);
            throw PortalScrapingException::pageNotAccessible('No valid session found');
        }

        $this->attachedSessionCookies = $this->cookieJar->toArray();
    }

    /**
     * Make a GET request to the campus system
     */
    protected function get(string $url, array $options = []): string
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * Make a POST request to the campus system
     */
    protected function post(string $url, array $options = []): string
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * Make an HTTP request to the campus system
     *
     * GET pages are memoized for the current request/job scope, so a page
     * needed by several extractors is downloaded once. Any other method
     * changes portal state and drops the memo.
     */
    protected function request(string $method, string $url, array $options = []): string
    {
        if (strtoupper($method) !== 'GET') {
            $this->forgetMemoizedPages();

            return $this->requestUpstream($method, $url, $options);
        }

        if ($this->sessionToken === null) {
            return $this->requestUpstream($method, $url, $options);
        }

        return $this->sessionContext()->rememberPage(
            $this->sessionToken,
            md5($url.'|'.(json_encode($options) ?: '')),
            fn (): string => $this->requestUpstream($method, $url, $options)
        );
    }

    /**
     * Make an HTTP request with retry logic
     */
    private function requestUpstream(string $method, string $url, array $options = []): string
    {
        $maxRetries = $this->isWebContext()
            ? 1
            : (int) config('portal.scraping.max_retries', 3);
        $retryDelay = (int) config('portal.scraping.retry_delay', 1000);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->makeRequest($method, $url, $options);
                $html = (string) $response->getBody();

                $this->validateResponse($html, $url);

                return $html;

            } catch (GuzzleException $e) {
                $lastException = $e;
                Log::warning('HTTP request failed, retrying', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'url' => $url,
                    'method' => $method,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    usleep($retryDelay * 1000 * $attempt); // Exponential backoff
                }

            } catch (PortalScrapingException $e) {
                // Don't retry scraping exceptions
                throw $e;
            }
        }

        // All retries failed
        throw PortalConnectionException::networkError($url, $lastException->getMessage());
    }

    /**
     * Make the actual HTTP request
     */
    private function makeRequest(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $requestOptions = array_merge([
            RequestOptions::COOKIES => $this->cookieJar,
            RequestOptions::HEADERS => [
                'Referer' => config('portal.base_url'),
                'Origin' => config('portal.base_url'),
            ],
        ], $options);

        $this->pauseBetweenRequests();

        return $this->client->request($method, $url, $requestOptions);
    }

    /**
     * Wait between requests to be respectful to the campus server.
     *
     * Web requests skip it: the politeness delay belongs to the queue
     * workers, while interactive requests race PHP-FPM's time limit.
     */
    protected function pauseBetweenRequests(): void
    {
        $delay = $this->fetchPolicy()->requestDelayMs();

        if ($delay > 0 && ! $this->isWebContext()) {
            usleep($delay * 1000);
        }
    }

    /**
     * Validate the HTML response
     *
     * Page-level indicators (error wording, session-expiry prompts) are only
     * trusted inside the <title> tag: the same strings appear inside the
     * bodies and inline scripts of perfectly valid portal pages.
     */
    protected function validateResponse(string $html, string $url, bool $allowShortResponse = false): void
    {
        // Check for empty response
        if (empty($html)) {
            throw PortalScrapingException::invalidResponse($url, 'Empty response');
        }

        // Upstream answers an expired session with its login form instead of
        // an error, which would otherwise be parsed (and cached) as empty data.
        if (self::isLoginPage($html)) {
            $this->invalidateCurrentSession('login_page_detected', [
                'source' => static::class,
                'url' => $url,
            ]);
            throw PortalScrapingException::pageNotAccessible($url);
        }

        $title = strtolower(self::htmlTitle($html));

        if ($title !== '') {
            // Check for session expiry indicators
            $sessionExpiredIndicators = [
                'session expired',
                'session timeout',
                'login kembali',
                'silahkan login',
                'your session has expired',
            ];

            foreach ($sessionExpiredIndicators as $indicator) {
                if (Str::contains($title, $indicator)) {
                    $this->invalidateCurrentSession('session_expired_indicator_detected', [
                        'source' => static::class,
                        'indicator' => $indicator,
                        'url' => $url,
                    ]);
                    throw PortalScrapingException::pageNotAccessible($url);
                }
            }

            // Check for error pages
            $errorIndicators = [
                '500 internal server error',
                '404 not found',
                '403 forbidden',
                'error occurred',
                'server error',
            ];

            foreach ($errorIndicators as $indicator) {
                if (Str::contains($title, $indicator)) {
                    throw PortalScrapingException::invalidResponse($url, "Error page detected: {$indicator}");
                }
            }
        }

        // Check for blank pages (common sign of session expiry)
        if (! $allowShortResponse && strlen(trim($html)) < 100) {
            $this->invalidateCurrentSession('blank_response_detected', [
                'source' => static::class,
                'url' => $url,
                'response_length' => strlen(trim($html)),
            ]);
            throw PortalScrapingException::pageNotAccessible($url);
        }
    }

    /**
     * Extract the contents of the <title> tag, or an empty string when absent
     */
    public static function htmlTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Whether the HTML is the mahasiswa portal login form
     *
     * Authenticated pages embed a SIPANDAI single-sign-on form (posting to
     * `loginact`), so only the login form's own password field is trusted.
     */
    public static function isLoginPage(string $html): bool
    {
        return preg_match('/<input[^>]+name\s*=\s*["\']txt_password["\']/i', $html) === 1;
    }

    /**
     * Reject the login form an expired session is answered with, so it is
     * never parsed (or cached) as an empty page
     *
     * `get()` already does this through validateResponse(); extractors call
     * it again so every parsing path, including replayed HTML, is covered.
     */
    protected function ensureNotLoginPage(string $html, string $url): void
    {
        if (self::isLoginPage($html)) {
            throw PortalScrapingException::pageNotAccessible($url);
        }
    }

    /**
     * Whether the current execution is an interactive HTTP request instead of
     * a queue worker or console command
     */
    private function isWebContext(): bool
    {
        return ! app()->runningInConsole();
    }

    protected function invalidateCurrentSession(string $reason, array $context = []): void
    {
        if ($this->sessionToken !== null) {
            $this->sessionManager->invalidateSession($this->sessionToken, $reason, $context, $this->attachedSessionCookies);
        }
    }

    protected function shouldForceFresh(array $context = []): bool
    {
        return ($context['fresh'] ?? false) === true || $this->fetchPolicy()->forcesFresh();
    }

    /**
     * Serve a parsed resource from the student's resource cache, or build it
     * from upstream and cache it
     *
     * @param  array<string, mixed>  $params
     * @param  callable(): array<string, mixed>  $build
     * @param  (callable(array<string, mixed>): bool)|null  $cacheable
     * @return array<string, mixed>
     */
    protected function rememberResource(string $resource, array $params, callable $build, ?callable $cacheable = null, bool $forceFresh = false): array
    {
        $result = app(PortalDataCache::class)->remember(
            (string) $this->sessionToken,
            $resource,
            $params,
            $build,
            $this->shouldForceFresh(['fresh' => $forceFresh]),
            $cacheable
        );

        return $this->withSyncMeta($result['data'], $result['origin']);
    }

    /**
     * Forget pages memoized in this scope after a state-changing request
     */
    protected function forgetMemoizedPages(): void
    {
        if ($this->sessionToken !== null) {
            $this->sessionContext()->forgetPages($this->sessionToken);
        }
    }

    protected function withSyncMeta(array $payload, string $dataOrigin = 'upstream'): array
    {
        $payload['_sync'] = [
            'data_origin' => $dataOrigin,
            'requested_fresh' => $this->shouldForceFresh(),
            'synced_at' => now()->toISOString(),
        ];

        return $payload;
    }

    protected function fetchPolicy(): PortalFetchPolicy
    {
        return app(PortalFetchPolicy::class);
    }

    protected function sessionContext(): PortalSessionContext
    {
        return app(PortalSessionContext::class);
    }

    /**
     * Create a DomCrawler instance from HTML
     */
    protected function createCrawler(string $html): Crawler
    {
        return new Crawler($html);
    }

    /**
     * Extract text from element with fallback selectors
     */
    protected function extractText(Crawler $crawler, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $text = trim($element->text());
                if (! empty($text)) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Extract attribute from element with fallback selectors
     */
    protected function extractAttribute(Crawler $crawler, array $selectors, string $attribute): ?string
    {
        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $value = $element->attr($attribute);
                if (! empty($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Extract table data from a table element
     */
    protected function extractTableData(Crawler $table): array
    {
        $data = [];
        $headers = [];

        // Extract headers
        $table->filter('thead tr th, tr:first-child td, tr:first-child th')->each(function (Crawler $cell) use (&$headers) {
            $headers[] = trim($cell->text());
        });

        // Extract rows
        $table->filter('tbody tr, tr')->each(function (Crawler $row) use (&$data, $headers) {
            $rowData = [];
            $row->filter('td, th')->each(function (Crawler $cell) use (&$rowData) {
                $rowData[] = trim($cell->text());
            });

            if (! empty($rowData)) {
                if (empty($headers)) {
                    $data[] = $rowData;
                } else {
                    $data[] = array_combine(array_slice($headers, 0, count($rowData)), $rowData);
                }
            }
        });

        return $data;
    }

    /**
     * Rows of this table only, in document order
     *
     * Live pages are parsed from raw server HTML, without a browser's table
     * repair: rows sit directly under the table (no tbody) or inside a form
     * or stray formatting tags, and the table is never restructured. Rows of
     * nested tables are left out, so raw and browser-normalized HTML give the
     * same rows. Pass the table element itself (a tbody, thead or tfoot also
     * works); a scope that is not a table section yields no rows.
     *
     * @return list<Crawler>
     */
    protected function tableRows(Crawler $table): array
    {
        return $this->ownTableElements($table, ['tr'], ['table']);
    }

    /**
     * The row's own td/th cells, in document order
     *
     * Includes cells the portal wraps in b, font, center, span, a, form or
     * other tags (`<tr><b><td>..</td></b></tr>`), which `children('td, th')`
     * misses on raw HTML. Cells of nested tables are left out. A colspan
     * cell is returned once, as written.
     *
     * @return list<Crawler>
     */
    protected function rowCells(Crawler $row): array
    {
        return $this->ownTableElements($row, ['td', 'th'], ['table', 'tr']);
    }

    /**
     * Normalized text of a cell (or any node); empty for an empty crawler
     */
    protected function cellText(Crawler $cell): string
    {
        return $cell->count() === 0 ? '' : $this->normalizePortalText($cell->text(''));
    }

    /**
     * First table in document order (the scope itself included) with a cell
     * for every required label in one of its first three rows that have cells
     *
     * A label matches a whole cell, ignoring case, whitespace and punctuation
     * ("T.A" matches "t. a"), never a substring of it. Only the row's own
     * cells count, so a layout table wrapping the wanted one never matches.
     *
     * @param  list<string>  $requiredHeaderLabels
     */
    protected function findTableByHeaders(Crawler $scope, array $requiredHeaderLabels): ?Crawler
    {
        $required = array_values(array_unique(array_filter(
            array_map(fn (string $label): string => $this->headerLabelKey($label), $requiredHeaderLabels),
            static fn (string $key): bool => $key !== ''
        )));

        if ($required === [] || $scope->count() === 0) {
            return null;
        }

        foreach ($scope->filter('table') as $node) {
            $table = new Crawler($node, $scope->getUri(), $scope->getBaseHref());
            $checkedRows = 0;

            foreach ($this->tableRows($table) as $row) {
                $cells = $this->rowCells($row);

                if ($cells === []) {
                    continue;
                }

                $labels = array_map(fn (Crawler $cell): string => $this->headerLabelKey($this->cellText($cell)), $cells);

                if (array_diff($required, $labels) === []) {
                    return $table;
                }

                if (++$checkedRows === 3) {
                    break;
                }
            }
        }

        return null;
    }

    private function headerLabelKey(string $label): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $this->normalizePortalText($label)));
    }

    /**
     * Descendants named in $targets that belong to the root, without
     * descending into a target or a $boundaries element
     *
     * @param  list<string>  $targets
     * @param  list<string>  $boundaries
     * @return list<Crawler>
     */
    private function ownTableElements(Crawler $root, array $targets, array $boundaries): array
    {
        $node = $root->getNode(0);

        if (! $node instanceof \DOMElement) {
            return [];
        }

        $elements = [];
        $this->collectOwnTableElements($node, $targets, $boundaries, $elements);

        return array_map(
            static fn (\DOMElement $element): Crawler => new Crawler($element, $root->getUri(), $root->getBaseHref()),
            $elements
        );
    }

    /**
     * @param  list<string>  $targets
     * @param  list<string>  $boundaries
     * @param  list<\DOMElement>  $elements
     */
    private function collectOwnTableElements(\DOMNode $parent, array $targets, array $boundaries, array &$elements): void
    {
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, $targets, true)) {
                $elements[] = $child;
            } elseif (! in_array($name, $boundaries, true)) {
                $this->collectOwnTableElements($child, $targets, $boundaries, $elements);
            }
        }
    }

    /**
     * Clean and normalize text
     */
    protected function cleanText(string $text): string
    {
        return trim(
            preg_replace(
                ['/\s+/', '/[\r\n\t]+/'],
                [' ', ' '],
                html_entity_decode($text, ENT_QUOTES, 'UTF-8')
            )
        );
    }

    /**
     * Extract numeric value from text
     */
    protected function extractNumber(string $text): ?float
    {
        $cleaned = preg_replace('/[^0-9.,-]/', '', $text);
        $cleaned = str_replace(',', '.', $cleaned);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    /**
     * Extract date from text
     */
    protected function extractDate(string $text): ?\DateTime
    {
        $text = $this->cleanText($text);

        // Try common date formats
        $formats = [
            'Y-m-d',
            'd-m-Y',
            'd/m/Y',
            'm/d/Y',
            'Y/m/d',
            'd M Y',
            'M d, Y',
            'd F Y',
            'F d, Y',
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $text);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    /**
     * The page's own content, without the sidebar, navbar and footer
     *
     * Every Portal Mahasiswa page carries the same sidebar (including the
     * SiPANDAI SSO form with the student's NIM), so extractors parse only
     * this scope. SIPANDAI pages keep their content in `.app-content`.
     * Pass the page-level crawler: page chrome such as the navbar brand
     * (academic period) lives outside this scope.
     */
    protected function mainContent(Crawler $crawler): Crawler
    {
        foreach (['.main-panel .content', '.app-wrapper .app-content', '.content', 'body'] as $selector) {
            $content = $crawler->filter($selector);

            if ($content->count() > 0) {
                return $content->first();
            }
        }

        return $crawler;
    }

    /**
     * Decode HTML entities, turn non-breaking spaces into plain spaces and
     * collapse whitespace
     */
    protected function normalizePortalText(string $text): string
    {
        $text = mb_scrub(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
        $text = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\u{200B}"], [' ', ' ', ' ', ''], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * First date written in the text, in WIB at midnight (K3)
     *
     * Understands "<Hari>, dd <Bulan> yyyy", "dd <Bulan> yyyy" (with common
     * month abbreviations), "dd-mm-yyyy", "dd/mm/yyyy" and "yyyy-mm-dd".
     * When the text starts with a range, its start date is returned.
     */
    protected function parseIndonesianDate(string $text): ?CarbonImmutable
    {
        return $this->scanIndonesianDates($text)[0]['start'] ?? null;
    }

    /**
     * First date range written in the text (K3)
     *
     * Ranges are joined by "sd", "s.d.", "s/d", "sampai (dengan)", "hingga"
     * or a dash. A start date without month and/or year inherits them from
     * the end date ("17 s/d 19 September 2026", "20 Juli - 16 Agustus 2026");
     * when that would put it after the end date, a start with only a day
     * moves back one month (and one year past January), and a start with a
     * month but no year moves back one year. Returns null when the text has
     * no range; parseIndonesianDate() then covers a single date.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    protected function parseIndonesianDateRange(string $text): ?array
    {
        return $this->extractIndonesianDateRanges($text)[0] ?? null;
    }

    /**
     * Every date range written in the text, in reading order
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    protected function extractIndonesianDateRanges(string $text): array
    {
        $ranges = [];

        foreach ($this->scanIndonesianDates($text) as $match) {
            if ($match['end'] !== null) {
                $ranges[] = ['start' => $match['start'], 'end' => $match['end']];
            }
        }

        return $ranges;
    }

    /**
     * Every distinct date written in free text, in reading order; both ends
     * of a range are included (used for announcement deadlines)
     *
     * @return list<CarbonImmutable>
     */
    protected function extractIndonesianDates(string $text): array
    {
        $dates = [];

        foreach ($this->scanIndonesianDates($text) as $match) {
            foreach ([$match['start'], $match['end']] as $date) {
                if ($date !== null) {
                    $dates[$date->toDateString()] ??= $date;
                }
            }
        }

        return array_values($dates);
    }

    /**
     * Academic year and term from a portal heading such as
     * "PORTAL MyCIC T.A 2026/2027 SEMESTER GANJIL" (K1)
     *
     * @return array{label: string, academic_year: string, term: 'ganjil'|'genap'|null}|null
     */
    protected function parseAcademicPeriod(string $text): ?array
    {
        $text = $this->normalizePortalText($text);

        if (preg_match('/T\.\s?A\.?\s*(\d{4})\s*\/\s*(\d{4})/iu', $text, $yearMatches) !== 1) {
            return null;
        }

        $academicYear = $yearMatches[1].'/'.$yearMatches[2];
        $term = preg_match('/SEMESTER\s+(GANJIL|GENAP)\b/iu', $text, $termMatches) === 1
            ? strtolower($termMatches[1])
            : null;

        return [
            'label' => 'T.A '.$academicYear.($term !== null ? ' Semester '.ucfirst($term) : ''),
            'academic_year' => $academicYear,
            'term' => $term,
        ];
    }

    /**
     * Path of a static campus file relative to /portal/ ("files/skkm/<file>"),
     * as the campus file proxy (`/api/support/download?path=`) expects it (K7)
     *
     * Returns null for anything PortalUrlGuard does not serve as a static
     * file, so a portal PHP endpoint or write action never becomes a path (K13).
     */
    protected function campusFilePath(string $reference): ?string
    {
        $url = PortalUrlGuard::campusFileUrl($reference);

        if ($url === null) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $position = strpos($path, '/portal/files/');

        return $position === false ? null : rawurldecode(substr($path, $position + strlen('/portal/')));
    }

    /**
     * Cloudflare `data-cfemail` hex: the first byte is the key, the rest is XOR-ed with it
     */
    protected function decodeCloudflareEmail(string $hex): ?string
    {
        if (! preg_match('/^[0-9a-f]+$/i', $hex) || strlen($hex) < 4 || strlen($hex) % 2 !== 0) {
            return null;
        }

        $key = hexdec(substr($hex, 0, 2));
        $email = '';

        for ($i = 2; $i < strlen($hex); $i += 2) {
            $email .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * First Cloudflare-obfuscated e-mail inside the node, whatever tag carries it (K4)
     */
    protected function extractCloudflareEmail(Crawler $node): ?string
    {
        return $this->extractCloudflareEmails($node)[0] ?? null;
    }

    /**
     * Every distinct Cloudflare-obfuscated e-mail inside the node, in document order
     *
     * Reads `[data-cfemail]` on any tag, and the fragment of
     * `/cdn-cgi/l/email-protection#<hex>` links for pages that only keep it there.
     *
     * @return list<string>
     */
    protected function extractCloudflareEmails(Crawler $node): array
    {
        if ($node->count() === 0) {
            return [];
        }

        $emails = [];

        $node->filter('[data-cfemail], [href*="/cdn-cgi/l/email-protection"]')->each(function (Crawler $element) use (&$emails): void {
            $encoded = $element->attr('data-cfemail');

            if ($encoded === null) {
                $fragment = parse_url((string) $element->attr('href'), PHP_URL_FRAGMENT);
                $encoded = is_string($fragment) ? $fragment : '';
            }

            $email = $this->decodeCloudflareEmail(trim($encoded));

            if ($email !== null) {
                $emails[strtolower($email)] ??= $email;
            }
        });

        return array_values($emails);
    }

    /**
     * The scrolling (marquee) notice inside the scope, with the dates it mentions (K5)
     *
     * `dates` are ISO dates (Y-m-d) in reading order; `is_future` is true when
     * any of them is today or later in WIB. Pass `auto` as kind to classify
     * the text as payment, attendance or general.
     *
     * @return array{text: string, dates: list<string>, is_future: bool, kind: string}|null
     */
    protected function extractPortalNotice(Crawler $scope, string $kind): ?array
    {
        if ($scope->count() === 0) {
            return null;
        }

        $texts = $scope->filter('marquee')->each(
            fn (Crawler $marquee): string => $this->normalizePortalText($marquee->text(''))
        );
        $text = current(array_filter($texts, static fn (string $value): bool => $value !== ''));

        if (! is_string($text)) {
            return null;
        }

        $text = (string) preg_replace('/\.(?:\s*\.)+$/u', '.', $text);
        $dates = $this->extractIndonesianDates($text);
        $today = CarbonImmutable::instance(now())->setTimezone(self::PORTAL_TIMEZONE)->startOfDay();

        return [
            'text' => $text,
            'dates' => array_map(static fn (CarbonImmutable $date): string => $date->toDateString(), $dates),
            'is_future' => array_filter($dates, static fn (CarbonImmutable $date): bool => $date->greaterThanOrEqualTo($today)) !== [],
            'kind' => $kind === 'auto' ? $this->classifyPortalNotice($text) : $kind,
        ];
    }

    /**
     * Topic of a portal-wide notice: payment, attendance or general
     */
    protected function classifyPortalNotice(string $text): string
    {
        if (preg_match('/pembayaran|bayar|tagihan|\bukt\b|keuangan|lunas/iu', $text) === 1) {
            return 'payment';
        }

        if (preg_match('/absen|presensi|kehadiran/iu', $text) === 1) {
            return 'attendance';
        }

        return 'general';
    }

    /**
     * Every date (or date range) in the text, in reading order
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable|null}>
     */
    private function scanIndonesianDates(string $text): array
    {
        $text = $this->normalizePortalText($text);

        if ($text === '') {
            return [];
        }

        $tokens = $this->findIndonesianDateTokens($text);
        $matches = [];
        $consumedUntil = 0;
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $tokenEnd = $token['offset'] + $token['length'];
            $next = $tokens[$index + 1] ?? null;

            if ($next !== null && $this->isDateRangeGap(substr($text, $tokenEnd, $next['offset'] - $tokenEnd))) {
                $matches[] = ['start' => $token['date'], 'end' => $next['date']];
                $consumedUntil = $next['offset'] + $next['length'];
                $index++;

                continue;
            }

            $start = $this->partialRangeStart(substr($text, $consumedUntil, $token['offset'] - $consumedUntil), $token['date']);
            $matches[] = $start === null
                ? ['start' => $token['date'], 'end' => null]
                : ['start' => $start, 'end' => $token['date']];
            $consumedUntil = $tokenEnd;
        }

        return $matches;
    }

    /**
     * Complete dates (day, month and year) with their byte offsets
     *
     * @return list<array{date: CarbonImmutable, offset: int, length: int}>
     */
    private function findIndonesianDateTokens(string $text): array
    {
        $months = self::alternation(array_keys(self::MONTH_NUMBERS));
        $pattern = '/(?<!\d)(?:'
            .'(?<td>\d{1,2})(?:\s+|\s*[-\/]\s*)(?<tm>'.$months.')(?!\p{L})\.?(?:,?\s+|\s*[-\/]\s*)(?<ty>\d{4})'
            .'|(?<nd>\d{1,2})(?<ns>[-\/.])(?<nm>\d{1,2})\k<ns>(?<ny>\d{4})'
            .'|(?<iy>\d{4})-(?<im>\d{1,2})-(?<id>\d{1,2})'
            .')(?!\d)/iu';

        if (preg_match_all($pattern, $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $tokens = [];

        foreach ($found as $match) {
            $date = match (true) {
                $match['td'][0] !== null => $this->makePortalDate(
                    (int) $match['ty'][0],
                    self::MONTH_NUMBERS[mb_strtolower($match['tm'][0])] ?? 0,
                    (int) $match['td'][0]
                ),
                $match['nd'][0] !== null => $this->makePortalDate((int) $match['ny'][0], (int) $match['nm'][0], (int) $match['nd'][0]),
                default => $this->makePortalDate((int) $match['iy'][0], (int) $match['im'][0], (int) $match['id'][0]),
            };

            if ($date !== null) {
                $tokens[] = ['date' => $date, 'offset' => $match[0][1], 'length' => strlen($match[0][0])];
            }
        }

        return $tokens;
    }

    /**
     * Whether the text between two complete dates joins them into a range
     */
    private function isDateRangeGap(string $gap): bool
    {
        return preg_match('/^\s*'.self::DATE_RANGE_SEPARATOR.'\s*'.self::dateFillerPattern().'$/iu', $gap) === 1;
    }

    /**
     * Start of a range whose month and/or year are only written on the end date
     *
     * The prefix is the text right before the (complete) end date.
     */
    private function partialRangeStart(string $prefix, CarbonImmutable $end): ?CarbonImmutable
    {
        $months = self::alternation(array_keys(self::MONTH_NUMBERS));
        $pattern = '/(?<![\p{L}\d.:,\/])(?<day>\d{1,2})(?:\s*(?<month>'.$months.')(?!\p{L})\.?)?\s*'
            .self::DATE_RANGE_SEPARATOR.'\s*'.self::dateFillerPattern().'$/iu';

        if (preg_match($pattern, $prefix, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $day = (int) $match['day'][0];
        $year = $end->year;

        if (($match['month'][0] ?? null) !== null) {
            $month = self::MONTH_NUMBERS[mb_strtolower($match['month'][0])] ?? 0;

            if ($month > $end->month || ($month === $end->month && $day > $end->day)) {
                $year--;
            }

            return $this->makePortalDate($year, $month, $day);
        }

        if (preg_match('/'.self::NON_DAY_NUMBER_PREFIX.'/iu', substr($prefix, 0, $match['day'][1])) === 1) {
            return null;
        }

        $month = $end->month;

        if ($day > $end->day) {
            $month--;

            if ($month < 1) {
                $month = 12;
                $year--;
            }
        }

        return $this->makePortalDate($year, $month, $day);
    }

    private function makePortalDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        if ($year < 1900 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::PORTAL_TIMEZONE);
    }

    /**
     * Optional words between a range separator and the end date ("s.d. Hari Rabu, 31 ...")
     */
    private static function dateFillerPattern(): string
    {
        return '(?:(?:hari|tanggal|tgl\.?|'.self::alternation(self::DAY_NAMES).')\s*,?\s*)*';
    }

    /**
     * Regex alternation of the given words, longest first
     *
     * @param  list<string>  $words
     */
    private static function alternation(array $words): string
    {
        usort($words, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), $words));
    }

    /**
     * Log scraping operation
     */
    protected function logScraping(string $operation, array $context = []): void
    {
        Log::info("Campus scraping: {$operation}", PortalLogContext::sanitize($context));
    }

    /**
     * Log scraping debug operation
     */
    protected function logScrapingDebug(string $operation, array $context = []): void
    {
        Log::debug("Campus scraping: {$operation}", PortalLogContext::sanitize($context));
    }

    /**
     * Log scraping error
     */
    protected function logScrapingError(string $operation, \Exception $e, array $context = []): void
    {
        Log::error("Campus scraping error: {$operation}", PortalLogContext::sanitize(array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ])));
    }
}
