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

/**
 * Stateless gateway for the portal's authentication pages.
 *
 * Every call works on the cookie jar it is given (or creates for a login), so
 * one instance can be shared by a long-running queue worker without leaking
 * cookies or a cached login form between students.
 */
class PortalAuthService
{
    /**
     * Wording a portal answer uses to turn down the username or password
     * itself ("Username atau password salah", "NIM tidak terdaftar"). Bare
     * "salah" is not enough: "kesalahan" (an error) and "salah satu" (one of)
     * are common on error and maintenance pages.
     */
    private const CREDENTIAL_REFUSAL_PATTERN = '/\b(?:password|kata\s*sandi|username|nim)\b[^<>]{0,60}?\b(?:salah(?!\s+satu)|tidak\s+(?:valid|sesuai|cocok|terdaftar|ditemukan)|wrong|incorrect|invalid)\b|\b(?:invalid|wrong|incorrect)\s+(?:username|password|credentials?|login)\b/i';

    private const UNCONFIRMED_CONTEXT_KEY = 'unconfirmed_refusal';

    private Client $client;

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
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => true,
                'referer' => true,
                'track_redirects' => true,
            ],
        ], PortalHttpClientOptions::shared()));
    }

    /**
     * Analyze login form to extract form data and field names
     *
     * @return array{action: string, method: string, fields: array<string, array{type: string, value: string, required: bool}>}
     */
    public function analyzeLoginForm(?CookieJar $cookieJar = null): array
    {
        $loginPageUrl = PortalEndpointRegistry::url('login_page');

        try {
            $response = $this->client->get($loginPageUrl, [
                RequestOptions::COOKIES => $cookieJar ?? new CookieJar,
            ]);
            $html = (string) $response->getBody();

            // Generic wording is common inside valid page scripts, so the
            // "is this an error page" heuristic only inspects the <title>.
            if (empty($html) || Str::contains(strtolower(PortalScrapingService::htmlTitle($html)), ['error', 'not found', '404'])) {
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
                    if (PortalScrapingService::formScope($formCrawler)->filter('input[type="password"]')->count() > 0) {
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
            $formAction = PortalUrlGuard::resolve((string) $loginForm->attr('action'), $loginPageUrl);

            // Extract all input fields
            $formFields = [];
            PortalScrapingService::formScope($loginForm)->filter('input')->each(function (Crawler $input) use (&$formFields) {
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

            $loginFormData = [
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

            return $loginFormData;

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
        // The login page and the login POST share one fresh jar, like a
        // browser would, and the form is analyzed again for every login.
        $cookieJar = new CookieJar;
        $loginFormData = $this->analyzeLoginForm($cookieJar);
        $loginPageUrl = PortalEndpointRegistry::url('login_page');

        try {
            // Prepare login data - map to CIC-specific field names
            $loginData = [];
            foreach ($loginFormData['fields'] as $fieldName => $fieldInfo) {
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
            $response = $this->client->post($loginFormData['action'], [
                RequestOptions::FORM_PARAMS => $loginData,
                RequestOptions::COOKIES => $cookieJar,
                RequestOptions::HEADERS => [
                    'Referer' => $loginPageUrl,
                    'Origin' => config('portal.base_url'),
                ],
            ]);

            $responseHtml = (string) $response->getBody();
            $redirectHistory = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);

            // Check if login was successful
            $loginSuccess = $this->isLoginSuccessful($responseHtml, $redirectHistory, $cookieJar);

            if (! $loginSuccess) {
                throw $this->loginFailure(PortalAuthenticationException::invalidCredentials(), $responseHtml);
            }

            // Get dashboard to confirm session
            $dashboardUrl = PortalEndpointRegistry::url('dashboard');
            $dashboardResponse = $this->client->get($dashboardUrl, [
                RequestOptions::COOKIES => $cookieJar,
            ]);

            $dashboardHtml = (string) $dashboardResponse->getBody();

            if ($this->isSessionExpired($dashboardHtml) || ! $this->hasAuthenticatedPortalMarker($dashboardHtml)) {
                throw $this->loginFailure(PortalAuthenticationException::sessionExpired(), $dashboardHtml);
            }

            // Extract user info from dashboard
            $userInfo = $this->extractUserInfo($dashboardHtml);

            Log::info('Campus login successful', [
                'domain' => 'campus_auth',
                'event' => 'login_succeeded',
                'username_fingerprint' => $this->fingerprint($username),
                'user_info' => array_keys($userInfo),
                'cookie_count' => count($cookieJar->toArray()),
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
                'cookies' => $cookieJar->toArray(),
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
            throw PortalConnectionException::networkError($loginFormData['action'], $e->getMessage());
        }
    }

    /**
     * Whether a failed login means the portal turned the password down, as
     * opposed to answering with a page MyCIC could not read (blank,
     * maintenance, a "session loading" page that never finished). Only a
     * refusal may end an offline session or mark stored credentials as
     * rejected; anything else is worth trying again later.
     */
    public static function refusedPassword(\Throwable $throwable): bool
    {
        return $throwable instanceof PortalAuthenticationException
            && ($throwable->getContext()[self::UNCONFIRMED_CONTEXT_KEY] ?? false) !== true;
    }

    /**
     * The same failed login, flagged as one the portal did not visibly
     * refuse (see refusedPassword()). Still a PortalAuthenticationException,
     * so an online login reports it like any failed login.
     */
    public static function unconfirmedRefusal(PortalAuthenticationException $exception): PortalAuthenticationException
    {
        return new PortalAuthenticationException(
            $exception->getMessage(),
            $exception->getCode(),
            null,
            [...($exception->getContext() ?? []), self::UNCONFIRMED_CONTEXT_KEY => true]
        );
    }

    /**
     * A login the portal did not let through. It counts as a refusal of the
     * password only when the portal answered with its login form again or
     * said the username or password is wrong.
     */
    private function loginFailure(PortalAuthenticationException $exception, string $html): PortalAuthenticationException
    {
        if (PortalScrapingService::isLoginPage($html) || preg_match(self::CREDENTIAL_REFUSAL_PATTERN, $html) === 1) {
            return $exception;
        }

        Log::warning('Campus login answer did not confirm a refused password', [
            'domain' => 'campus_auth',
            'event' => 'login_refusal_unconfirmed',
            'error' => $exception->getMessage(),
            'html_length' => strlen($html),
            'html_title' => Str::limit(PortalScrapingService::htmlTitle($html), 80),
        ]);

        return self::unconfirmedRefusal($exception);
    }

    /**
     * Check if login was successful based on response and redirect history
     */
    private function isLoginSuccessful(string $responseHtml, array $redirectHistory, ?CookieJar $cookieJar = null): bool
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
                    $fullRefreshUrl = PortalUrlGuard::resolve($refreshUrl, PortalEndpointRegistry::url('login_page'));

                    $refreshResponse = $this->client->get($fullRefreshUrl, [
                        RequestOptions::COOKIES => $cookieJar ?? new CookieJar,
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
     *
     * The indicator strings are only trusted inside the <title> tag because
     * valid authenticated pages embed the same wording in their scripts.
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

        $title = strtolower(PortalScrapingService::htmlTitle($html));

        foreach ($sessionExpiredIndicators as $indicator) {
            if (Str::contains($title, $indicator)) {
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

    /**
     * Ping the portal with the session's cookies; true when it still serves
     * the authenticated dashboard.
     *
     * @throws PortalConnectionException when the portal cannot be reached, so
     *                                   callers can tell a network blip from
     *                                   an expired session
     */
    public function isSessionAlive(CookieJar $cookieJar): bool
    {
        $dashboardUrl = PortalEndpointRegistry::url('dashboard');

        try {
            $html = (string) $this->client->get($dashboardUrl, [
                RequestOptions::COOKIES => $cookieJar,
                RequestOptions::TIMEOUT => 10,
            ])->getBody();
        } catch (GuzzleException $e) {
            throw PortalConnectionException::networkError($dashboardUrl, $e->getMessage());
        }

        return ! $this->isSessionExpired($html)
            && ! PortalScrapingService::isLoginPage($html)
            && $this->hasAuthenticatedPortalMarker($html);
    }

    /**
     * Best-effort logout from both the mahasiswa portal and SIPANDAI
     */
    public function logout(CookieJar $cookieJar): void
    {
        foreach (['student_logout', 'sipandai_logout'] as $endpoint) {
            try {
                $this->client->request(PortalEndpointRegistry::method($endpoint), PortalEndpointRegistry::url($endpoint), [
                    RequestOptions::COOKIES => $cookieJar,
                    RequestOptions::TIMEOUT => 10,
                ]);
            } catch (\Throwable $e) {
                Log::warning('campus_auth.logout_endpoint_failed', [
                    'domain' => 'campus_auth',
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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

            // Also extract from student profile / dashboard tables (.table tr)
            $crawler->filter('.table tr')->each(function (Crawler $row) use (&$userInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = strtolower(trim($cells->eq(0)->text()));
                    $value = trim(strip_tags($cells->eq(2)->text()));
                    $value = preg_replace('/\s+/', ' ', $value);

                    if (in_array($label, ['nama', 'name']) && empty($userInfo['name'])) {
                        $userInfo['name'] = $value;
                        $userInfo['nama'] = $value;
                    } elseif (in_array($label, ['nim', 'n i m', 'student id']) && empty($userInfo['student_id'])) {
                        $userInfo['student_id'] = $value;
                        $userInfo['nim'] = $value;
                    } elseif (in_array($label, ['program studi', 'prodi', 'jurusan']) && empty($userInfo['department'])) {
                        $userInfo['department'] = $value;
                        $userInfo['program_studi'] = $value;
                    }
                }
            });

        } catch (\Exception $e) {
            Log::warning('Failed to extract some user info', ['error' => $e->getMessage()]);
        }

        return $userInfo;
    }

    private function fingerprint(string $value): string
    {
        return substr(hash_hmac('sha256', $value, (string) (config('app.key') ?: 'campus-auth-observability')), 0, 16);
    }
}
