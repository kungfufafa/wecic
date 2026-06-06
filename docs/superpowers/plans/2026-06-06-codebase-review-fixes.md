# Codebase Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the highest-impact code-review findings around campus API session security, credential retention, token leakage, upstream URL trust, rate limiting, and exam submission record integrity.

**Architecture:** Keep the existing Laravel 12 API shape and browser-session campus session model, but harden it in place. Add focused helpers for sensitive log context and portal URL host validation, then wire them into the current controllers and services without introducing new dependencies.

**Tech Stack:** Laravel 12, PHP 8.4, PHPUnit 11, Guzzle, Symfony DomCrawler, Laravel cache/session/rate limiting, Laravel Pint.

---

## Scope Check

The review findings belong to one subsystem: the campus portal API integration. This plan fixes that subsystem in prioritized, independently testable tasks. No dependency changes are required.

## File Structure

- Modify: `routes/api.php`
  - Add CSRF verification to the custom session-aware API middleware stack.
  - Add named `throttle:*` middleware to login and expensive mutating routes.
- Modify: `bootstrap/app.php`
  - Keep existing middleware alias registration and API CORS behavior.
  - Do not add CSRF exceptions here.
- Modify: `app/Http/Middleware/VerifyCsrfTokenMiddleware.php`
  - Remove broad `api/*` exclusions so cookie-backed API mutations require CSRF tokens.
- Modify: `app/Http/Controllers/API/PortalAuthController.php`
  - Regenerate Laravel session IDs on login.
  - Invalidate sessions on logout.
  - Stop returning raw campus session tokens in JSON responses.
  - Purge retained campus credentials after a successful password change.
  - Use safe fingerprints in logs.
- Modify: `app/Providers/AppServiceProvider.php`
  - Register `portal-login` and `portal-action` named rate limiters.
- Modify: `config/portal.php`
  - Default auto relogin to disabled.
  - Add explicit `auto_relogin_credential_ttl` and rate-limit config entries.
- Modify: `.env.example`
  - Set `CAMPUS_AUTO_RELOGIN=false`.
  - Add documented rate-limit and auto-relogin TTL env keys.
- Modify: `app/Services/PortalSessionManager.php`
  - Stop storing encrypted credentials unless auto relogin is explicitly enabled.
  - Add a public credential purge method.
  - Avoid raw username/token log contexts.
- Modify: `app/Services/PortalScrapingService.php`
  - Sanitize `session_token` and `username` values before logging.
- Create: `app/Services/PortalLogContext.php`
  - Centralize HMAC fingerprinting and context sanitization for logs.
- Create: `app/Services/PortalUrlGuard.php`
  - Resolve relative portal URLs and reject absolute off-host or non-HTTPS URLs before dispatch.
- Modify: `app/Services/PortalAuthService.php`
  - Use `PortalUrlGuard` for login form and meta-refresh URLs.
  - Treat blank, unknown, or minimal upstream login/session pages as failures unless explicit success evidence exists.
- Modify: `app/Services/PortalPasswordService.php`
  - Use `PortalUrlGuard` for password form action URLs.
- Modify: `app/Services/SipandaiSubmissionService.php`
  - Use `PortalUrlGuard` for assignment, attendance, exam, and related-link action resolution.
  - Persist exam submission records with a durable deduplication key.
- Create: `database/migrations/2026_06_06_000000_add_record_key_to_exam_submission_records_table.php`
  - Add a unique canonical `record_key` for durable submission record deduplication.
- Modify: `app/Models/ExamSubmissionRecord.php`
  - Add `record_key` to fillable fields.
- Modify: `tests/Feature/PortalAuthControllerTest.php`
  - Add CSRF, session rotation, token response, logout, credential purge, and login throttle coverage.
- Modify: `tests/Feature/PortalActionControllerTest.php`
  - Add action throttle coverage.
- Modify: `tests/Unit/PortalSessionManagerTest.php`
  - Add auto-relogin default and credential purge coverage.
- Create: `tests/Unit/PortalAuthServiceTest.php`
  - Add strict upstream login/session detection and login URL guard coverage.
- Modify: `tests/Unit/PortalPasswordServiceTest.php`
  - Add malicious password form action rejection coverage.
- Modify: `tests/Unit/SipandaiSubmissionServiceTest.php`
  - Add malicious action URL and exam submission deduplication coverage.

---

### Task 1: Protect Cookie-Session API Routes With CSRF And Session Rotation

**Files:**
- Modify: `routes/api.php`
- Modify: `app/Http/Middleware/VerifyCsrfTokenMiddleware.php`
- Modify: `app/Http/Controllers/API/PortalAuthController.php`
- Test: `tests/Feature/PortalAuthControllerTest.php`
- Test: `tests/Feature/PortalActionControllerTest.php`

- [ ] **Step 1: Write failing CSRF tests**

Add these imports to `tests/Feature/PortalAuthControllerTest.php`:

```php
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
```

Replace the class body with these tests, keeping the existing validation test but requiring a valid CSRF header for it:

```php
class PortalAuthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_login_rejects_missing_csrf_token(): void
    {
        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->post('/api/auth/login', [
                'username' => '20251030030',
                'password' => 'secret-password',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(419);
    }

    public function test_login_validation_returns_json_without_accept_header(): void
    {
        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'Username dan password harus diisi',
            ]);

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('content-type')
        );
    }

    public function test_login_rotates_laravel_session_id_and_does_not_return_session_token(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('createSession')
                    ->once()
                    ->with('20251030030', 'secret-password')
                    ->andReturn([
                        'success' => true,
                        'session_token' => 'campus-token-123',
                        'user_info' => ['name' => 'Mahasiswa Uji'],
                        'expires_at' => '2026-06-06T10:00:00+07:00',
                    ]);
            })
        );

        $this->startSession();
        session()->put('_token', 'known-csrf-token');
        $oldSessionId = session()->getId();

        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => '20251030030',
                'password' => 'secret-password',
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonMissingPath('data.session_token')
            ->assertSessionHas('campus_session_token', 'campus-token-123');

        $this->assertNotSame($oldSessionId, session()->getId());
    }

    public function test_logout_invalidates_laravel_session(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('destroySession')
                    ->once()
                    ->with('campus-token-123')
                    ->andReturnTrue();
            })
        );

        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->withSession([
                '_token' => 'known-csrf-token',
                'campus_session_token' => 'campus-token-123',
                'campus_user_info' => ['name' => 'Mahasiswa Uji'],
            ])
            ->post('/api/auth/logout', [
                '_token' => 'known-csrf-token',
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertSessionMissing('campus_session_token')
            ->assertSessionMissing('campus_user_info');
    }
}
```

Add this test to `tests/Feature/PortalActionControllerTest.php` before the current `setUp()` method disables CSRF for the rest of the class:

```php
public function test_mutating_action_rejects_missing_csrf_token_when_csrf_middleware_is_enabled(): void
{
    $this->withMiddleware(VerifyCsrfTokenMiddleware::class);

    $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

    $response = $this->withSession(['campus_session_token' => 'token-123'])
        ->post('/api/assignment', [
            'courses_id' => 9000,
            'id' => 29833,
            'pertemuan' => 2,
            'attachment' => $file,
        ], ['Accept' => 'application/json']);

    $response->assertStatus(419);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/PortalAuthControllerTest.php tests/Feature/PortalActionControllerTest.php
```

Expected: at least the new CSRF and session-rotation assertions fail because API routes do not run CSRF verification and login does not regenerate the session ID.

- [ ] **Step 3: Implement CSRF middleware wiring**

In `routes/api.php`, replace the existing `$sessionMiddleware` block with:

```php
// Session-aware middleware stack for campus API routes (cookie + session + CSRF).
$sessionMiddleware = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \App\Http\Middleware\VerifyCsrfTokenMiddleware::class,
];
```

In `app/Http/Middleware/VerifyCsrfTokenMiddleware.php`, replace the `$except` property with:

```php
protected $except = [];
```

- [ ] **Step 4: Implement session rotation and logout invalidation**

In `app/Http/Controllers/API/PortalAuthController.php`, in `login()`, insert session regeneration before storing campus data and remove `session_token` from the JSON payload:

```php
$sessionResult = $this->sessionManager->createSession($username, $password);

session()->regenerate();
session([
    'campus_session_token' => $sessionResult['session_token'],
    'campus_user_info' => $sessionResult['user_info'],
]);

Log::info('User logged in successfully', ['username_fingerprint' => PortalLogContext::fingerprint($username)]);

return $this->statusSuccess('Login berhasil', [
    'data' => [
        'user_info' => $sessionResult['user_info'],
        'expires_at' => $sessionResult['expires_at'],
    ],
], campusSessionValid: true);
```

Add this import at the top of the controller:

```php
use App\Services\PortalLogContext;
```

In `logout()`, replace both `session()->forget(...)` calls with:

```php
session()->invalidate();
session()->regenerateToken();
```

- [ ] **Step 5: Run tests to verify they pass**

Run:

```bash
php artisan test tests/Feature/PortalAuthControllerTest.php tests/Feature/PortalActionControllerTest.php
```

Expected: PASS for the updated feature tests.

- [ ] **Step 6: Commit**

```bash
git add routes/api.php app/Http/Middleware/VerifyCsrfTokenMiddleware.php app/Http/Controllers/API/PortalAuthController.php tests/Feature/PortalAuthControllerTest.php tests/Feature/PortalActionControllerTest.php
git commit -m "fix: protect campus API sessions with CSRF"
```

---

### Task 2: Disable Stored Upstream Credentials By Default And Purge Them Safely

**Files:**
- Modify: `config/portal.php`
- Modify: `.env.example`
- Modify: `app/Services/PortalSessionManager.php`
- Modify: `app/Http/Controllers/API/PortalAuthController.php`
- Test: `tests/Unit/PortalSessionManagerTest.php`
- Test: `tests/Feature/PortalAuthControllerTest.php`

- [ ] **Step 1: Write failing credential retention tests**

Add this test to `tests/Unit/PortalSessionManagerTest.php`:

```php
public function test_create_session_does_not_store_encrypted_credentials_by_default(): void
{
    config([
        'portal.session_prefix' => 'portal_session_no_credentials_',
        'portal.session_refresh_interval' => 1200,
        'portal.auto_relogin' => false,
    ]);

    Cache::flush();
    Queue::fake();

    $authService = Mockery::mock(PortalAuthService::class);
    $authService->shouldReceive('login')
        ->once()
        ->with('20251030036', 'login-pass')
        ->andReturn($this->makeLoginResult([
            'nama' => 'Mahasiswa No Credentials',
        ]));

    $manager = new PortalSessionManager($authService, Mockery::mock(Encrypter::class));

    $result = $manager->createSession('20251030036', 'login-pass');
    $storedSession = Cache::get(config('portal.session_prefix').$result['session_token']);

    $this->assertArrayNotHasKey('encrypted_credentials', $storedSession);
}
```

Add this test to `tests/Unit/PortalSessionManagerTest.php`:

```php
public function test_forget_stored_credentials_removes_credentials_without_destroying_session(): void
{
    config([
        'portal.session_prefix' => 'portal_session_forget_credentials_',
        'portal.session_refresh_interval' => 1200,
    ]);

    Cache::flush();

    $manager = new PortalSessionManager(
        Mockery::mock(PortalAuthService::class),
        Mockery::mock(Encrypter::class)
    );

    Cache::put(config('portal.session_prefix').'token-with-credentials', $this->makeStoredSession('token-with-credentials', [
        'encrypted_credentials' => 'encrypted-credentials',
    ]), 1200);

    $manager->forgetStoredCredentials('token-with-credentials');

    $storedSession = Cache::get(config('portal.session_prefix').'token-with-credentials');

    $this->assertNotNull($storedSession);
    $this->assertArrayNotHasKey('encrypted_credentials', $storedSession);
    $this->assertTrue($storedSession['is_active']);
}
```

Add this test to `tests/Feature/PortalAuthControllerTest.php`:

```php
public function test_successful_password_change_purges_stored_credentials(): void
{
    $sessionManager = Mockery::mock(PortalSessionManager::class);
    $sessionManager->shouldReceive('forgetStoredCredentials')
        ->once()
        ->with('campus-token-123');

    $passwordService = Mockery::mock(\App\Services\PortalPasswordService::class);
    $passwordService->shouldReceive('changePassword')
        ->once()
        ->with('campus-token-123', 'old-secret', 'new-secret', 'new-secret')
        ->andReturn([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data' => [],
        ]);

    $this->instance(PortalSessionManager::class, $sessionManager);
    $this->instance(\App\Services\PortalPasswordService::class, $passwordService);

    $response = $this->withSession([
        '_token' => 'known-csrf-token',
        'campus_session_token' => 'campus-token-123',
    ])->post('/api/auth/password', [
        '_token' => 'known-csrf-token',
        'current_password' => 'old-secret',
        'new_password' => 'new-secret',
        'new_password_confirmation' => 'new-secret',
    ], ['Accept' => 'application/json']);

    $response->assertOk()
        ->assertJsonPath('success', true);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
php artisan test tests/Unit/PortalSessionManagerTest.php tests/Feature/PortalAuthControllerTest.php
```

Expected: FAIL because `forgetStoredCredentials()` does not exist and password change does not call it.

- [ ] **Step 3: Update configuration defaults**

In `config/portal.php`, replace the session management lines with:

```php
'session_prefix' => env('CAMPUS_SESSION_PREFIX', 'cic_session_'),
'auto_relogin' => (bool) env('CAMPUS_AUTO_RELOGIN', false),
'auto_relogin_credential_ttl' => (int) env('CAMPUS_AUTO_RELOGIN_CREDENTIAL_TTL', 900),
'session_refresh_interval' => (int) env('CAMPUS_SESSION_REFRESH_INTERVAL', 1200),
```

In `.env.example`, replace `CAMPUS_AUTO_RELOGIN=true` with:

```dotenv
CAMPUS_AUTO_RELOGIN=false
CAMPUS_AUTO_RELOGIN_CREDENTIAL_TTL=900
```

- [ ] **Step 4: Add credential purge method**

In `app/Services/PortalSessionManager.php`, replace every `config('portal.auto_relogin', true)` fallback with:

```php
config('portal.auto_relogin', false)
```

Add this public method after `invalidateSession()`:

```php
public function forgetStoredCredentials(string $sessionToken): void
{
    $session = $this->loadSession($sessionToken);

    if (! $session || ! array_key_exists('encrypted_credentials', $session)) {
        return;
    }

    unset($session['encrypted_credentials']);
    $this->storeSession($sessionToken, $session);

    $this->logSessionEvent('stored_credentials_removed', sessionToken: $sessionToken, session: $session);
}
```

- [ ] **Step 5: Purge credentials after password changes**

In `app/Http/Controllers/API/PortalAuthController.php`, inside `changePassword()` after `$result` is returned from the service and before `return $this->booleanResponse(...)`, add:

```php
if (($result['success'] ?? false) === true) {
    $this->sessionManager->forgetStoredCredentials($sessionToken);
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run:

```bash
php artisan test tests/Unit/PortalSessionManagerTest.php tests/Feature/PortalAuthControllerTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add config/portal.php .env.example app/Services/PortalSessionManager.php app/Http/Controllers/API/PortalAuthController.php tests/Unit/PortalSessionManagerTest.php tests/Feature/PortalAuthControllerTest.php
git commit -m "fix: avoid retaining campus passwords by default"
```

---

### Task 3: Remove Raw Token And Identifier Leakage From Responses And Logs

**Files:**
- Create: `app/Services/PortalLogContext.php`
- Modify: `app/Services/PortalScrapingService.php`
- Modify: `app/Services/PortalSessionManager.php`
- Modify: `app/Http/Controllers/API/PortalAuthController.php`
- Test: `tests/Feature/PortalAuthControllerTest.php`
- Test: `tests/Unit/SipandaiSubmissionServiceTest.php`

- [ ] **Step 1: Write failing response and log sanitization tests**

Add this test to `tests/Feature/PortalAuthControllerTest.php`:

```php
public function test_refresh_session_does_not_return_raw_session_token(): void
{
    $this->instance(
        PortalSessionManager::class,
        Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshSession')
                ->once()
                ->with('campus-token-123')
                ->andReturn([
                    'success' => true,
                    'session_token' => 'campus-token-123',
                    'expires_at' => '2026-06-06T10:00:00+07:00',
                ]);
        })
    );

    $response = $this->withSession([
        '_token' => 'known-csrf-token',
        'campus_session_token' => 'campus-token-123',
    ])->post('/api/auth/session/refresh', [
        '_token' => 'known-csrf-token',
    ], ['Accept' => 'application/json']);

    $response->assertOk()
        ->assertJsonMissingPath('data.session_token')
        ->assertJsonPath('data.expires_at', '2026-06-06T10:00:00+07:00');
}
```

Add this test to `tests/Unit/SipandaiSubmissionServiceTest.php`:

```php
public function test_log_scraping_replaces_raw_session_token_with_fingerprint(): void
{
    \Illuminate\Support\Facades\Log::spy();

    $service = new class($this->createMock(PortalSessionManager::class)) extends SipandaiSubmissionService {
        public function writeLog(): void
        {
            $this->logScraping('token leak check', [
                'session_token' => 'raw-campus-token',
                'username' => '20251030030',
                'course_id' => 8837,
            ]);
        }
    };

    $service->writeLog();

    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Campus scraping: token leak check'
                && ! array_key_exists('session_token', $context)
                && ! array_key_exists('username', $context)
                && isset($context['session_token_fingerprint'])
                && isset($context['username_fingerprint'])
                && $context['course_id'] === 8837;
        })
        ->once();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/PortalAuthControllerTest.php tests/Unit/SipandaiSubmissionServiceTest.php
```

Expected: FAIL because refresh still returns `session_token` and scraping logs still contain raw `session_token`.

- [ ] **Step 3: Create `PortalLogContext`**

Run:

```bash
php artisan make:class Services/PortalLogContext --no-interaction
```

Replace `app/Services/PortalLogContext.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services;

class PortalLogContext
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function sanitize(array $context): array
    {
        if (isset($context['session_token']) && is_scalar($context['session_token'])) {
            $context['session_token_fingerprint'] = self::fingerprint((string) $context['session_token']);
            unset($context['session_token']);
        }

        if (isset($context['username']) && is_scalar($context['username'])) {
            $context['username_fingerprint'] = self::fingerprint((string) $context['username']);
            unset($context['username']);
        }

        return $context;
    }

    public static function fingerprint(string $value): string
    {
        return substr(hash_hmac('sha256', $value, self::fingerprintKey()), 0, 16);
    }

    private static function fingerprintKey(): string
    {
        return (string) (config('app.key') ?: 'campus-observability');
    }
}
```

- [ ] **Step 4: Sanitize scraping logs and refresh response**

In `app/Services/PortalScrapingService.php`, replace `logScraping()` with:

```php
protected function logScraping(string $operation, array $context = []): void
{
    Log::info("Campus scraping: {$operation}", PortalLogContext::sanitize($context));
}
```

In `app/Services/PortalScrapingService.php`, replace `logScrapingError()` with:

```php
protected function logScrapingError(string $operation, \Exception $e, array $context = []): void
{
    Log::error("Campus scraping error: {$operation}", PortalLogContext::sanitize(array_merge($context, [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ])));
}
```

In `app/Http/Controllers/API/PortalAuthController.php`, replace the refresh success payload with:

```php
return $this->statusSuccess('Session refreshed successfully', [
    'data' => [
        'expires_at' => $refreshResult['expires_at'] ?? null,
    ],
], campusSessionValid: true);
```

- [ ] **Step 5: Reuse helper in session manager logs**

In `app/Services/PortalSessionManager.php`, replace the private `fingerprint()` method body with:

```php
return PortalLogContext::fingerprint($value);
```

Add this import:

```php
use App\Services\PortalLogContext;
```

- [ ] **Step 6: Run tests to verify they pass**

Run:

```bash
php artisan test tests/Feature/PortalAuthControllerTest.php tests/Unit/SipandaiSubmissionServiceTest.php tests/Unit/PortalSessionManagerTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/PortalLogContext.php app/Services/PortalScrapingService.php app/Services/PortalSessionManager.php app/Http/Controllers/API/PortalAuthController.php tests/Feature/PortalAuthControllerTest.php tests/Unit/SipandaiSubmissionServiceTest.php
git commit -m "fix: redact campus tokens from responses and logs"
```

---

### Task 4: Make Upstream Login And Session Detection Explicit

**Files:**
- Modify: `app/Services/PortalAuthService.php`
- Create: `tests/Unit/PortalAuthServiceTest.php`

- [ ] **Step 1: Create the unit test file**

Run:

```bash
php artisan make:test --unit --phpunit PortalAuthServiceTest --no-interaction
```

Replace `tests/Unit/PortalAuthServiceTest.php` with:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalAuthService;
use Tests\TestCase;

class PortalAuthServiceTest extends TestCase
{
    public function test_unknown_login_response_is_not_successful(): void
    {
        $service = new PortalAuthService;

        $result = $this->invokeNonPublicMethod($service, 'isLoginSuccessful', [
            '<html><body><p>Maintenance window</p></body></html>',
            [],
        ]);

        $this->assertFalse($result);
    }

    public function test_session_loading_without_followable_success_is_not_successful(): void
    {
        $service = new PortalAuthService;

        $result = $this->invokeNonPublicMethod($service, 'isLoginSuccessful', [
            '<html><head><meta http-equiv="refresh" content="0;url=?mod=student&act=loading"></head><body>Session Loading</body></html>',
            [],
        ]);

        $this->assertFalse($result);
    }

    public function test_blank_or_minimal_session_page_is_expired(): void
    {
        $service = new PortalAuthService;

        $this->assertTrue($service->isSessionExpired(''));
        $this->assertTrue($service->isSessionExpired('<html><body></body></html>'));
        $this->assertTrue($service->isSessionExpired('<html><body>OK</body></html>'));
    }

    public function test_dashboard_markers_are_not_expired(): void
    {
        $service = new PortalAuthService;

        $html = '<html><body><a href="?mod=logout">Logout</a><h1>Dashboard Mahasiswa</h1></body></html>';

        $this->assertFalse($service->isSessionExpired($html));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokeNonPublicMethod(object $instance, string $method, array $arguments): mixed
    {
        $reflectionMethod = new \ReflectionMethod($instance, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($instance, $arguments);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test tests/Unit/PortalAuthServiceTest.php
```

Expected: FAIL because unknown login responses currently fall through to success and minimal pages are treated as not expired.

- [ ] **Step 3: Implement explicit success markers**

In `app/Services/PortalAuthService.php`, replace the final `return true;` in `isLoginSuccessful()` with:

```php
return false;
```

Replace the unconditional `return true;` after failed session-loading meta refresh with:

```php
return false;
```

Add this private method before `extractUserInfo()`:

```php
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
```

In `login()`, after `$dashboardHtml = (string) $dashboardResponse->getBody();`, replace the session-expired check with:

```php
if ($this->isSessionExpired($dashboardHtml) || ! $this->hasAuthenticatedPortalMarker($dashboardHtml)) {
    throw PortalAuthenticationException::sessionExpired();
}
```

In `isSessionExpired()`, replace the short-content block with:

```php
$trimmedLength = strlen(trim($html));
if ($trimmedLength < 100) {
    return ! $this->hasAuthenticatedPortalMarker($html);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
php artisan test tests/Unit/PortalAuthServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PortalAuthService.php tests/Unit/PortalAuthServiceTest.php
git commit -m "fix: require explicit campus login success"
```

---

### Task 5: Apply Rate Limits To Login And Expensive Actions

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/portal.php`
- Modify: `.env.example`
- Modify: `routes/api.php`
- Test: `tests/Feature/PortalAuthControllerTest.php`
- Test: `tests/Feature/PortalActionControllerTest.php`

- [ ] **Step 1: Write failing rate-limit tests**

Add this test to `tests/Feature/PortalAuthControllerTest.php`:

```php
public function test_login_is_rate_limited_by_username_and_ip(): void
{
    config([
        'portal.security.login_rate_limit_attempts' => 2,
        'portal.security.login_rate_limit_decay' => 60,
    ]);

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $response = $this->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => '20251030030',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    $response = $this->withSession(['_token' => 'known-csrf-token'])
        ->post('/api/auth/login', [
            '_token' => 'known-csrf-token',
            'username' => '20251030030',
        ], ['Accept' => 'application/json']);

    $response->assertStatus(429);
}
```

Add this test to `tests/Feature/PortalActionControllerTest.php`:

```php
public function test_action_routes_are_rate_limited_per_session_token(): void
{
    config([
        'portal.security.action_rate_limit_attempts' => 1,
        'portal.security.action_rate_limit_decay' => 60,
    ]);

    $this->mockValidCampusSession('token-rate-limited-action');
    $this->mockServiceExpectingSubmitAttendance('token-rate-limited-action');

    $this->withSession(['campus_session_token' => 'token-rate-limited-action'])
        ->postJson('/api/attendance', [
            'courseid' => 8837,
            'modulesid' => 29808,
            'kode' => '35533429',
            'pertemuan' => 2,
        ])
        ->assertOk();

    $this->withSession(['campus_session_token' => 'token-rate-limited-action'])
        ->postJson('/api/attendance', [
            'courseid' => 8837,
            'modulesid' => 29808,
            'kode' => '35533429',
            'pertemuan' => 2,
        ])
        ->assertStatus(429);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/PortalAuthControllerTest.php tests/Feature/PortalActionControllerTest.php
```

Expected: FAIL because no `throttle:portal-login` or `throttle:portal-action` middleware is applied.

- [ ] **Step 3: Register rate limiters**

In `app/Providers/AppServiceProvider.php`, add these imports:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
```

Replace `boot()` with:

```php
public function boot(): void
{
    RateLimiter::for('portal-login', function (Request $request): Limit {
        $username = strtolower((string) $request->input('username', 'anonymous'));

        return Limit::perMinutes(
            (int) ceil(config('portal.security.login_rate_limit_decay', 300) / 60),
            (int) config('portal.security.login_rate_limit_attempts', 5)
        )->by($request->ip().'|'.$username);
    });

    RateLimiter::for('portal-action', function (Request $request): Limit {
        $sessionToken = (string) $request->session()->get('campus_session_token', $request->ip());

        return Limit::perMinutes(
            (int) ceil(config('portal.security.action_rate_limit_decay', 60) / 60),
            (int) config('portal.security.action_rate_limit_attempts', 10)
        )->by($sessionToken);
    });
}
```

In `config/portal.php`, replace the `security` block with:

```php
'security' => [
    'encrypt_credentials' => true,
    'login_rate_limit_attempts' => (int) env('CAMPUS_LOGIN_RATE_LIMIT_ATTEMPTS', 5),
    'login_rate_limit_decay' => (int) env('CAMPUS_LOGIN_RATE_LIMIT_DECAY', 300),
    'action_rate_limit_attempts' => (int) env('CAMPUS_ACTION_RATE_LIMIT_ATTEMPTS', 10),
    'action_rate_limit_decay' => (int) env('CAMPUS_ACTION_RATE_LIMIT_DECAY', 60),
],
```

In `.env.example`, add:

```dotenv
CAMPUS_LOGIN_RATE_LIMIT_ATTEMPTS=5
CAMPUS_LOGIN_RATE_LIMIT_DECAY=300
CAMPUS_ACTION_RATE_LIMIT_ATTEMPTS=10
CAMPUS_ACTION_RATE_LIMIT_DECAY=60
```

- [ ] **Step 4: Apply rate-limit middleware**

In `routes/api.php`, change login route:

```php
Route::post('login', 'login')->middleware('throttle:portal-login');
```

For expensive mutating routes, apply `throttle:portal-action`:

```php
Route::post('session/refresh', 'refreshSession')->middleware('throttle:portal-action');
Route::post('logout', 'logout')->middleware('throttle:portal-action');
Route::post('password', 'changePassword')->middleware('throttle:portal-action');
Route::post('', 'uploadAssignment')->middleware('throttle:portal-action');
Route::delete('', 'deleteAssignment')->middleware('throttle:portal-action');
Route::post('attendance', [PortalActionController::class, 'markAttendance'])->middleware('throttle:portal-action');
Route::post('', 'uploadExam')->middleware('throttle:portal-action');
```

- [ ] **Step 5: Run tests to verify they pass**

Run:

```bash
php artisan test tests/Feature/PortalAuthControllerTest.php tests/Feature/PortalActionControllerTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/AppServiceProvider.php config/portal.php .env.example routes/api.php tests/Feature/PortalAuthControllerTest.php tests/Feature/PortalActionControllerTest.php
git commit -m "fix: throttle campus login and action routes"
```

---

### Task 6: Enforce Portal Host Pinning For Proxied Form Actions

**Files:**
- Create: `app/Services/PortalUrlGuard.php`
- Modify: `app/Services/PortalAuthService.php`
- Modify: `app/Services/PortalPasswordService.php`
- Modify: `app/Services/SipandaiSubmissionService.php`
- Test: `tests/Unit/PortalAuthServiceTest.php`
- Test: `tests/Unit/PortalPasswordServiceTest.php`
- Test: `tests/Unit/SipandaiSubmissionServiceTest.php`

- [ ] **Step 1: Write failing URL guard tests**

Add this test to `tests/Unit/PortalPasswordServiceTest.php`:

```php
public function test_password_form_rejects_external_action_url(): void
{
    $service = $this->mockPasswordService();

    $service->shouldReceive('get')
        ->once()
        ->andReturn(<<<'HTML'
        <html><body>
            <form method="post" action="https://evil.example.test/steal">
                <input type="password" name="data[passwordold]" />
                <input type="password" name="data[password]" />
                <input type="password" name="data[konfirm]" />
            </form>
        </body></html>
        HTML);

    $this->expectException(\App\Exceptions\PortalScrapingException::class);

    $service->inspectPasswordForm('fake-session-token');
}
```

Add this test to `tests/Unit/SipandaiSubmissionServiceTest.php`:

```php
public function test_submission_action_rejects_external_absolute_url(): void
{
    $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

    $this->expectException(\App\Exceptions\PortalScrapingException::class);

    $this->invokeNonPublicMethod($service, 'resolveActionUrl', [
        'https://evil.example.test/upload',
        'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=send',
    ]);
}
```

Add this test to `tests/Unit/PortalAuthServiceTest.php`:

```php
public function test_portal_url_guard_rejects_external_login_action(): void
{
    $this->expectException(\App\Exceptions\PortalScrapingException::class);

    \App\Services\PortalUrlGuard::resolve(
        'https://evil.example.test/login',
        'https://my.cic.ac.id/portal/mahasiswa/'
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
php artisan test tests/Unit/PortalAuthServiceTest.php tests/Unit/PortalPasswordServiceTest.php tests/Unit/SipandaiSubmissionServiceTest.php
```

Expected: FAIL because `PortalUrlGuard` does not exist and existing resolvers accept external absolute URLs.

- [ ] **Step 3: Create the URL guard**

Run:

```bash
php artisan make:class Services/PortalUrlGuard --no-interaction
```

Replace `app/Services/PortalUrlGuard.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalScrapingException;
use Illuminate\Support\Str;

class PortalUrlGuard
{
    public static function resolve(string $action, string $referenceUrl): string
    {
        $action = trim(html_entity_decode($action, ENT_QUOTES, 'UTF-8'));

        if ($action === '' || $action === '#') {
            return self::assertAllowed($referenceUrl);
        }

        if (Str::startsWith($action, ['http://', 'https://'])) {
            return self::assertAllowed($action);
        }

        if (Str::startsWith($action, '?')) {
            return self::assertAllowed(Str::before($referenceUrl, '?').$action);
        }

        if (Str::startsWith($action, '/')) {
            return self::assertAllowed(rtrim((string) config('portal.base_url'), '/').'/'.ltrim($action, '/'));
        }

        return self::assertAllowed(rtrim(Str::beforeLast($referenceUrl, '/'), '/').'/'.$action);
    }

    public static function assertAllowed(string $url): string
    {
        $baseHost = (string) parse_url((string) config('portal.base_url'), PHP_URL_HOST);
        $urlHost = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'https') {
            throw PortalScrapingException::invalidResponse($url, 'Portal URL must use HTTPS');
        }

        if ($baseHost === '' || $urlHost === '' || ! hash_equals($baseHost, $urlHost)) {
            throw PortalScrapingException::invalidResponse($url, 'External portal host is not allowed');
        }

        return $url;
    }
}
```

- [ ] **Step 4: Use the guard in services**

In `PortalAuthService::analyzeLoginForm()`, replace the manual `$formAction` resolution block with:

```php
$formAction = PortalUrlGuard::resolve((string) $loginForm->attr('action'), $loginPageUrl);
```

In `PortalAuthService::isLoginSuccessful()`, replace the meta-refresh URL resolution block with:

```php
$fullRefreshUrl = PortalUrlGuard::resolve($refreshUrl, PortalEndpointRegistry::url('login_page'));
```

In `PortalPasswordService::resolveActionUrl()`, replace the entire method body with:

```php
return PortalUrlGuard::resolve($action !== '' ? $action : PortalEndpointRegistry::url('password_submit'), $baseUrl);
```

In `SipandaiSubmissionService::resolveActionUrl()`, replace the entire method body with:

```php
return PortalUrlGuard::resolve($action, $referenceUrl);
```

In `SipandaiSubmissionService::resolveCampusFileUrl()`, replace the absolute URL branch validation with:

```php
if (Str::startsWith($rawPath, ['http://', 'https://'])) {
    return PortalUrlGuard::assertAllowed($rawPath);
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run:

```bash
php artisan test tests/Unit/PortalAuthServiceTest.php tests/Unit/PortalPasswordServiceTest.php tests/Unit/SipandaiSubmissionServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PortalUrlGuard.php app/Services/PortalAuthService.php app/Services/PortalPasswordService.php app/Services/SipandaiSubmissionService.php tests/Unit/PortalAuthServiceTest.php tests/Unit/PortalPasswordServiceTest.php tests/Unit/SipandaiSubmissionServiceTest.php
git commit -m "fix: pin portal proxy actions to campus host"
```

---

### Task 7: Add Durable Uniqueness For Exam Submission Records

**Files:**
- Create: `database/migrations/2026_06_06_000000_add_record_key_to_exam_submission_records_table.php`
- Modify: `app/Models/ExamSubmissionRecord.php`
- Modify: `app/Services/SipandaiSubmissionService.php`
- Test: `tests/Unit/SipandaiSubmissionServiceTest.php`

- [ ] **Step 1: Write failing deduplication test**

Add these imports to `tests/Unit/SipandaiSubmissionServiceTest.php`:

```php
use App\Models\ExamSubmissionRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
```

Add the trait inside the class:

```php
use RefreshDatabase;
```

Add this test:

```php
public function test_exam_submission_records_are_deduplicated_by_record_key(): void
{
    $this->assertTrue(Schema::hasTable('exam_submission_records'));

    $sessionManager = $this->createMock(PortalSessionManager::class);
    $sessionManager->method('getSession')
        ->with('token-exam-records')
        ->willReturn([
            'username' => '20251030030',
        ]);

    $service = new SipandaiSubmissionService($sessionManager);
    $records = [[
        'exam_id' => 9195,
        'submission_id' => 131828,
        'file_url' => 'https://my.cic.ac.id/portal/files/tugasmhs/9195-UTS-20251030030-785.pdf',
        'file_name' => '9195-UTS-20251030030-785.pdf',
        'delete_url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdelujian&id=131828',
        'uploaded_at_label' => 'Rabu, 20 Mei 2026',
        'exam_type' => 'UTS / Reguler',
        'description' => '-',
    ]];

    $this->invokeNonPublicMethod($service, 'rememberExamSubmissionRecords', [
        'token-exam-records',
        9195,
        $records,
        'detail',
    ]);

    $this->invokeNonPublicMethod($service, 'rememberExamSubmissionRecords', [
        'token-exam-records',
        9195,
        $records,
        'upload_refresh',
    ]);

    $this->assertSame(1, ExamSubmissionRecord::query()->count());
    $this->assertSame('upload_refresh', ExamSubmissionRecord::query()->firstOrFail()->source);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test tests/Unit/SipandaiSubmissionServiceTest.php --filter=deduplicated
```

Expected: FAIL because `record_key` does not exist and duplicate persistence is not backed by a unique key.

- [ ] **Step 3: Create migration**

Run:

```bash
php artisan make:migration add_record_key_to_exam_submission_records_table --table=exam_submission_records --no-interaction
```

Replace the generated migration body with:

```php
public function up(): void
{
    Schema::table('exam_submission_records', function (Blueprint $table): void {
        $table->string('record_key')->nullable()->after('exam_id');
        $table->unique('record_key');
    });
}

public function down(): void
{
    Schema::table('exam_submission_records', function (Blueprint $table): void {
        $table->dropUnique(['record_key']);
        $table->dropColumn('record_key');
    });
}
```

- [ ] **Step 4: Update model fillable fields**

In `app/Models/ExamSubmissionRecord.php`, add `record_key` after `exam_id`:

```php
'exam_id',
'record_key',
'submission_id',
```

- [ ] **Step 5: Persist with canonical record keys**

In `app/Services/SipandaiSubmissionService.php`, add this private method near `examSubmissionRecordKey()`:

```php
private function persistedExamSubmissionRecordKey(?string $username, int $examId, array $record): string
{
    $identity = $this->examSubmissionRecordKey($record);

    return hash('sha256', implode('|', [
        $username ?? 'anonymous',
        (string) $examId,
        $identity,
    ]));
}
```

In `rememberExamSubmissionRecords()`, replace the lookup-building and `updateOrCreate()` block with:

```php
$recordKey = $this->persistedExamSubmissionRecordKey($username, $examId, $record);

ExamSubmissionRecord::query()->updateOrCreate([
    'record_key' => $recordKey,
], [
    'username' => $username,
    'exam_id' => $examId,
    'record_key' => $recordKey,
    'submission_id' => $record['submission_id'] ?? null,
    'file_url' => $record['file_url'] ?? null,
    'file_name' => $record['file_name'] ?? null,
    'delete_url' => $record['delete_url'] ?? null,
    'uploaded_at_label' => $record['uploaded_at_label'] ?? null,
    'exam_type' => $record['exam_type'] ?? null,
    'description' => $record['description'] ?? null,
    'source' => $source,
    'metadata' => [],
    'last_seen_at' => now(),
]);
```

- [ ] **Step 6: Run test to verify it passes**

Run:

```bash
php artisan test tests/Unit/SipandaiSubmissionServiceTest.php --filter=deduplicated
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/ExamSubmissionRecord.php app/Services/SipandaiSubmissionService.php tests/Unit/SipandaiSubmissionServiceTest.php
git commit -m "fix: deduplicate exam submission records"
```

---

### Task 8: Final Verification

**Files:**
- Verify all files changed in Tasks 1-7.

- [ ] **Step 1: Run formatter**

Run:

```bash
vendor/bin/pint --dirty
```

Expected: Pint completes and formats any touched PHP files.

- [ ] **Step 2: Run targeted tests**

Run:

```bash
php artisan test tests/Feature/PortalAuthControllerTest.php tests/Feature/PortalActionControllerTest.php tests/Unit/PortalSessionManagerTest.php tests/Unit/PortalAuthServiceTest.php tests/Unit/PortalPasswordServiceTest.php tests/Unit/SipandaiSubmissionServiceTest.php
```

Expected: PASS.

- [ ] **Step 3: Run full suite**

Run:

```bash
php artisan test
```

Expected: PASS, including the existing 123 tests plus the new tests from this plan.

- [ ] **Step 4: Review git diff**

Run:

```bash
git diff --check
git status --short
```

Expected: `git diff --check` prints no whitespace errors, and `git status --short` lists only files touched by this plan.

- [ ] **Step 5: Commit verification-only formatting if Pint changed files**

If Step 1 changed files after Task 7's commit, run:

```bash
git add .
git commit -m "style: format campus security fixes"
```

If Step 1 did not change files, do not create a formatting commit.

---

## Self-Review

**Spec coverage:** The plan maps all seven code-review findings to tasks: CSRF/session fixation in Task 1, password retention in Task 2, token/log leakage in Task 3, ambiguous upstream auth in Task 4, rate limiting in Task 5, off-host form actions in Task 6, and exam submission deduplication in Task 7.

**Placeholder scan:** The plan does not use open-ended implementation placeholders. Each task names exact files, code snippets, commands, expected failures, expected passes, and commit commands.

**Type consistency:** New helpers are consistently named `PortalLogContext` and `PortalUrlGuard`. New persistence column is consistently named `record_key`. New rate limiters are consistently named `portal-login` and `portal-action`.
