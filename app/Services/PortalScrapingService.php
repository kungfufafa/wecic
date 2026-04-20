<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalScrapingException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

abstract class PortalScrapingService
{
    protected Client $client;

    protected PortalSessionManager $sessionManager;

    protected ?CookieJar $cookieJar = null;

    protected ?string $sessionToken = null;

    public function __construct(PortalSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
        $this->client = new Client([
            RequestOptions::TIMEOUT => config('portal.request_timeout', 30),
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
        ]);
    }

    /**
     * Set the session for scraping operations
     */
    public function setSession(string $sessionToken): void
    {
        $this->sessionToken = $sessionToken;
        $this->cookieJar = $this->sessionManager->getSessionCookieJar($sessionToken);

        if (! $this->cookieJar) {
            $this->invalidateCurrentSession('cookie_jar_missing', [
                'source' => static::class,
            ]);
            throw PortalScrapingException::pageNotAccessible('No valid session found');
        }
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
     * Make an HTTP request with retry logic
     */
    protected function request(string $method, string $url, array $options = []): string
    {
        $maxRetries = config('portal.scraping.max_retries', 3);
        $retryDelay = config('portal.scraping.retry_delay', 1000);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->makeRequest($method, $url, $options);
                $html = (string) $response->getBody();

                // Validate response
                $this->validateResponse($html, $url);

                // Cache successful response
                $this->cacheResponse($url, $html, $method, $options);

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

        // Add delay between requests to be respectful to the campus server
        $delay = config('portal.request_delay', 1000);
        if ($delay > 0) {
            usleep($delay * 1000);
        }

        return $this->client->request($method, $url, $requestOptions);
    }

    /**
     * Validate the HTML response
     */
    protected function validateResponse(string $html, string $url, bool $allowShortResponse = false): void
    {
        // Check for empty response
        if (empty($html)) {
            throw PortalScrapingException::invalidResponse($url, 'Empty response');
        }

        // Check for session expiry indicators
        $sessionExpiredIndicators = [
            'session expired',
            'session timeout',
            'login kembali',
            'silahkan login',
            'your session has expired',
        ];

        foreach ($sessionExpiredIndicators as $indicator) {
            if (Str::contains(strtolower($html), $indicator)) {
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
            if (Str::contains(strtolower($html), $indicator)) {
                throw PortalScrapingException::invalidResponse($url, "Error page detected: {$indicator}");
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

    protected function invalidateCurrentSession(string $reason, array $context = []): void
    {
        if ($this->sessionToken !== null) {
            $this->sessionManager->invalidateSession($this->sessionToken, $reason, $context);
        }
    }

    /**
     * Get cached response if available
     */
    protected function getCachedResponse(string $url, string $method = 'GET', array $options = []): ?string
    {
        $cacheKey = $this->generateCacheKey($url, $method, $options);
        $ttl = $this->getCacheTtl($url);

        return Cache::get($cacheKey);
    }

    /**
     * Cache the response
     */
    protected function cacheResponse(string $url, string $html, string $method = 'GET', array $options = []): void
    {
        $cacheKey = $this->generateCacheKey($url, $method, $options);
        $ttl = $this->getCacheTtl($url);

        Cache::put($cacheKey, $html, $ttl);
    }

    /**
     * Generate cache key for request
     */
    private function generateCacheKey(string $url, string $method, array $options): string
    {
        $keyData = [
            'url' => $url,
            'method' => $method,
            'options' => $options,
            'session_id' => $this->cookieJar ? spl_object_hash($this->cookieJar) : 'no-session',
        ];

        return 'campus_scrape_'.md5(json_encode($keyData));
    }

    /**
     * Get cache TTL based on URL pattern
     */
    private function getCacheTtl(string $url): int
    {
        // Different TTL for different types of data
        if (Str::contains($url, 'dashboard') || Str::contains($url, 'profile')) {
            return config('portal.cache.profile_ttl', 3600);
        }

        if (Str::contains($url, ['grade', 'nilai', 'transcript'])) {
            return config('portal.cache.dynamic_ttl', 900);
        }

        if (Str::contains($url, ['schedule', 'jadwal', 'finance', 'keuangan'])) {
            return config('portal.cache.dynamic_ttl', 900);
        }

        // Default HTML cache TTL
        return config('portal.cache.html_ttl', 300);
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
        Log::info("Campus scraping: {$operation}", $context);
    }

    /**
     * Log scraping error
     */
    protected function logScrapingError(string $operation, \Exception $e, array $context = []): void
    {
        Log::error("Campus scraping error: {$operation}", array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));
    }
}
