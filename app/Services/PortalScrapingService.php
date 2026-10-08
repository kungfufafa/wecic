<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalScrapingException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

abstract class PortalScrapingService
{
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
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => true,
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
