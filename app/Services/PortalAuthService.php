<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalScrapingException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class PortalAuthService
{
    private Client $client;

    private ?CookieJar $cookieJar = null;

    private array $loginFormData = [];

    public function __construct()
    {
        $this->client = new Client(array_replace_recursive([
            RequestOptions::TIMEOUT => config('portal.request_timeout', 30),
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'User-Agent' => config('portal.scraping.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'en-US,en;q=0.9,id;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Cache-Control' => 'max-age=0',
            ],
            RequestOptions::COOKIES => true,
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => true,
                'referer' => true,
                'track_redirects' => true,
            ],
        ], PortalHttpClientOptions::secureDns()));
    }

    /**
     * Analyze login form to extract form data and field names
     */
    public function analyzeLoginForm(): array
    {
        $loginPageUrl = PortalEndpointRegistry::url('login_page');

        try {
            $response = $this->client->get($loginPageUrl);
            $html = (string) $response->getBody();

            if (empty($html) || Str::contains($html, ['error', 'not found', '404'])) {
                throw new PortalScrapingException('invalidHtmlStructure', 'login page');
            }

            $crawler = new Crawler($html);

            // Simple form detection - just find the first form with loginact in action
            $forms = $crawler->filter('form');

            if ($forms->count() === 0) {
                throw PortalScrapingException::elementNotFound('login form', 'login page');
            }

            // Try to find form with loginact action first
            $loginForm = null;
            foreach ($forms as $formElement) {
                $formCrawler = new Crawler($formElement);
                $action = $formCrawler->attr('action') ?? '';

                if (Str::contains($action, 'loginact')) {
                    $loginForm = $formCrawler;
                    break;
                }
            }

            // If not found, use first form with password field
            if (! $loginForm) {
                foreach ($forms as $formElement) {
                    $formCrawler = new Crawler($formElement);
                    if ($formCrawler->filter('input[type="password"]')->count() > 0) {
                        $loginForm = $formCrawler;
                        break;
                    }
                }
            }

            // If still not found, use first form
            if (! $loginForm) {
                $loginForm = $forms->first();
            }

            // Extract form data
            $formAction = $loginForm->attr('action');
            if (! Str::startsWith($formAction, 'http')) {
                // Handle relative URLs starting with ?
                if (Str::startsWith($formAction, '?')) {
                    $formAction = $loginPageUrl.$formAction;
                } else {
                    $baseUrl = config('portal.base_url');
                    $formAction = rtrim($baseUrl, '/').'/'.ltrim($formAction, '/');
                }
            }

            // Extract all input fields
            $formFields = [];
            $loginForm->filter('input')->each(function (Crawler $input) use (&$formFields) {
                $name = $input->attr('name');
                $value = $input->attr('value') ?? '';
                $type = strtolower($input->attr('type') ?? 'text');

                if ($name) {
                    $formFields[$name] = [
                        'type' => $type,
                        'value' => $value,
                        'required' => $input->attr('required') !== null,
                    ];
                }
            });

            $this->loginFormData = [
                'action' => $formAction,
                'method' => strtolower($loginForm->attr('method') ?? 'post'),
                'fields' => $formFields,
            ];

            Log::info('Login form analyzed successfully', [
                'domain' => 'campus_auth',
                'event' => 'login_form_analyzed',
                'action' => $formAction,
                'field_count' => count($formFields),
                'metric' => [
                    'name' => 'campus_auth_events_total',
                    'type' => 'counter',
                    'value' => 1,
                    'tags' => [
                        'event' => 'login_form_analyzed',
                        'component' => class_basename(static::class),
                    ],
                ],
            ]);

            return $this->loginFormData;

        } catch (GuzzleException $e) {
            Log::error('Failed to analyze login form', [
                'domain' => 'campus_auth',
                'event' => 'login_form_analysis_failed',
                'error' => $e->getMessage(),
                'url' => $loginPageUrl,
                'metric' => [
                    'name' => 'campus_auth_events_total',
                    'type' => 'counter',
                    'value' => 1,
                    'tags' => [
                        'event' => 'login_form_analysis_failed',
                        'component' => class_basename(static::class),
                    ],
                ],
            ]);
            throw PortalConnectionException::networkError($loginPageUrl, $e->getMessage());
        }
    }

    /**
     * Attempt to login to campus system
     */
    public function login(string $username, string $password): array
    {
        if (empty($this->loginFormData)) {
            $this->analyzeLoginForm();
        }

        $this->cookieJar = new CookieJar;
        $loginPageUrl = PortalEndpointRegistry::url('login_page');

        try {
            // Prepare login data - map to CIC-specific field names
            $loginData = [];
            foreach ($this->loginFormData['fields'] as $fieldName => $fieldInfo) {
                if ($fieldName === 'txt_username') {
                    $loginData[$fieldName] = $username;
                } elseif ($fieldName === 'txt_password') {
                    $loginData[$fieldName] = $password;
                } elseif ($fieldInfo['type'] === 'password') {
                    $loginData[$fieldName] = $password;
                } elseif ($fieldInfo['type'] === 'text' || $fieldInfo['type'] === 'email') {
                    // Assume first text/email field is username
                    if (empty($loginData)) {
                        $loginData[$fieldName] = $username;
                    } else {
                        $loginData[$fieldName] = $fieldInfo['value'];
                    }
                } else {
                    $loginData[$fieldName] = $fieldInfo['value'];
                }
            }

            // Perform login request
            $response = $this->client->post($this->loginFormData['action'], [
                RequestOptions::FORM_PARAMS => $loginData,
                RequestOptions::COOKIES => $this->cookieJar,
                RequestOptions::HEADERS => [
                    'Referer' => $loginPageUrl,
                    'Origin' => config('portal.base_url'),
                ],
            ]);

            $responseHtml = (string) $response->getBody();
            $redirectHistory = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);

            // Check if login was successful
            $loginSuccess = $this->isLoginSuccessful($responseHtml, $redirectHistory);

            if (! $loginSuccess) {
                throw PortalAuthenticationException::invalidCredentials();
            }

            // Get dashboard to confirm session
            $dashboardUrl = PortalEndpointRegistry::url('dashboard');
            $dashboardResponse = $this->client->get($dashboardUrl, [
                RequestOptions::COOKIES => $this->cookieJar,
            ]);

            $dashboardHtml = (string) $dashboardResponse->getBody();

            if ($this->isSessionExpired($dashboardHtml) || ! $this->hasAuthenticatedPortalMarker($dashboardHtml)) {
                throw PortalAuthenticationException::sessionExpired();
            }

            // Extract user info from dashboard
            $userInfo = $this->extractUserInfo($dashboardHtml);

            Log::info('Campus login successful', [
                'domain' => 'campus_auth',
                'event' => 'login_succeeded',
                'username_fingerprint' => $this->fingerprint($username),
                'user_info' => array_keys($userInfo),
                'cookie_count' => count($this->cookieJar?->toArray() ?? []),
                'metric' => [
                    'name' => 'campus_auth_events_total',
                    'type' => 'counter',
                    'value' => 1,
                    'tags' => [
                        'event' => 'login_succeeded',
                        'component' => class_basename(static::class),
                    ],
                ],
            ]);

            return [
                'success' => true,
                'user_info' => $userInfo,
                'cookies' => $this->cookieJar->toArray(),
                'session_data' => [
                    'login_time' => now()->toISOString(),
                    'last_activity' => now()->toISOString(),
                    'user_agent' => $this->client->getConfig(RequestOptions::HEADERS)['User-Agent'],
                ],
            ];

        } catch (GuzzleException $e) {
            Log::error('Campus login failed', [
                'domain' => 'campus_auth',
                'event' => 'login_failed',
                'username_fingerprint' => $this->fingerprint($username),
                'error' => $e->getMessage(),
                'metric' => [
                    'name' => 'campus_auth_events_total',
                    'type' => 'counter',
                    'value' => 1,
                    'tags' => [
                        'event' => 'login_failed',
                        'component' => class_basename(static::class),
                    ],
                ],
            ]);
            throw PortalConnectionException::networkError($this->loginFormData['action'], $e->getMessage());
        }
    }

    /**
     * Check if login was successful based on response and redirect history
     */
    private function isLoginSuccessful(string $responseHtml, array $redirectHistory): bool
    {
        // Check if redirected to dashboard
        foreach ($redirectHistory as $redirectUrl) {
            if (Str::contains($redirectUrl, 'dashboard') || Str::contains($redirectUrl, 'student')) {
                return true;
            }
        }

        // Check for session loading pattern (CIC specific)
        if (Str::contains(strtolower($responseHtml), 'session loading')) {
            // Extract meta refresh URL if present
            if (preg_match('/url=([^"]+)"/', $responseHtml, $matches)) {
                $refreshUrl = $matches[1];
                Log::info('Session loading detected, meta refresh URL', ['url' => $refreshUrl]);

                // Follow the meta refresh URL to complete the session
                try {
                    $fullRefreshUrl = $refreshUrl;
                    if (! Str::startsWith($refreshUrl, 'http')) {
                        $baseUrl = PortalEndpointRegistry::url('login_page'); // Use login page as base
                        // Handle URLs that start with ? (query parameters)
                        if (Str::startsWith($refreshUrl, '?')) {
                            $fullRefreshUrl = $baseUrl.$refreshUrl;
                        } else {
                            $fullRefreshUrl = rtrim($baseUrl, '/').'/'.ltrim($refreshUrl, '/');
                        }
                    }

                    $refreshResponse = $this->client->get($fullRefreshUrl, [
                        RequestOptions::COOKIES => $this->cookieJar,
                    ]);

                    $refreshHtml = (string) $refreshResponse->getBody();

                    // Check if after the refresh we're successfully logged in
                    if (! $this->isSessionExpired($refreshHtml)) {
                        Log::info('Session completed successfully after meta refresh');

                        return true;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to follow session loading meta refresh', ['error' => $e->getMessage()]);
                }
            }

            return false;
        }

        // Check response content for login success indicators
        $loginSuccessIndicators = [
            'dashboard',
            'mahasiswa',
            'student',
            'selamat datang',
            'welcome',
        ];

        foreach ($loginSuccessIndicators as $indicator) {
            if (Str::contains(strtolower($responseHtml), $indicator)) {
                return true;
            }
        }

        // Check for login failure indicators
        $loginFailureIndicators = [
            'error',
            'salah',
            'invalid',
            'gagal',
            'failed',
            'username atau password',
        ];

        foreach ($loginFailureIndicators as $indicator) {
            if (Str::contains(strtolower($responseHtml), $indicator)) {
                return false;
            }
        }

        // If redirected to login page again, login failed
        if (Str::contains($responseHtml, 'password') || Str::contains($responseHtml, 'login')) {
            return false;
        }

        return false;
    }

    /**
     * Check if session has expired
     */
    public function isSessionExpired(string $html): bool
    {
        $sessionExpiredIndicators = [
            'session expired',
            'session timeout',
            'login kembali',
            'silahkan login',
            'your session has expired',
            'login required',
        ];

        foreach ($sessionExpiredIndicators as $indicator) {
            if (Str::contains(strtolower($html), $indicator)) {
                return true;
            }
        }

        // For CIC system, we need to be smarter about detecting session expiry
        // Check if page is blank or minimal (common sign of session expiry)
        $trimmedLength = strlen(trim($html));
        if ($trimmedLength < 100) {
            return ! $this->hasAuthenticatedPortalMarker($html);
        }

        return false;
    }

    private function hasAuthenticatedPortalMarker(string $html): bool
    {
        $haystack = strtolower($html);

        return Str::contains($haystack, [
            'dashboard mahasiswa',
            'mod=logout',
            'act=dashboard',
            'student dashboard',
            'selamat datang',
        ]);
    }

    /**
     * Extract basic user information from dashboard
     */
    private function extractUserInfo(string $html): array
    {
        $crawler = new Crawler($html);
        $userInfo = [];

        try {
            // Try to find student name
            $nameSelectors = [
                '.student-name',
                '.user-name',
                'h1',
                '.profile-name',
                '[data-student-name]',
            ];

            foreach ($nameSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $userInfo['name'] = trim($element->text());
                    break;
                }
            }

            // Try to find student ID/NIM
            $idSelectors = [
                '.student-id',
                '.nim',
                '.user-id',
                '[data-student-id]',
            ];

            foreach ($idSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $userInfo['student_id'] = trim($element->text());
                    break;
                }
            }

            // Try to find department/program
            $deptSelectors = [
                '.department',
                '.program',
                '.study-program',
                '.jurusan',
                '.prodi',
            ];

            foreach ($deptSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $userInfo['department'] = trim($element->text());
                    break;
                }
            }

        } catch (\Exception $e) {
            Log::warning('Failed to extract some user info', ['error' => $e->getMessage()]);
        }

        return $userInfo;
    }

    /**
     * Get current cookie jar for session persistence
     */
    public function getCookieJar(): ?CookieJar
    {
        return $this->cookieJar;
    }

    /**
     * Set cookie jar for existing session
     */
    public function setCookieJar(CookieJar $cookieJar): void
    {
        $this->cookieJar = $cookieJar;
    }

    /**
     * Get login form analysis data
     */
    public function getLoginFormData(): array
    {
        return $this->loginFormData;
    }

    /**
     * Get the HTTP client instance
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    private function fingerprint(string $value): string
    {
        return substr(hash_hmac('sha256', $value, (string) (config('app.key') ?: 'campus-auth-observability')), 0, 16);
    }
}
