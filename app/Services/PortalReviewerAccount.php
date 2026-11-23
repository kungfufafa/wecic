<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;

/**
 * The account app store reviewers sign in with (PORTAL_REVIEWER_USERNAME and
 * PORTAL_REVIEWER_PASSWORD, read as config portal.reviewer), in every
 * environment production included. Off while either is empty, or while the
 * username is all digits (a NIM, which would shadow a real student).
 *
 * Signing in with exactly those credentials never reaches the campus portal:
 * PortalAuthService::login() answers with a session of the fixed student
 * SESSION_USERNAME (never the typed username, so the reviewer can never read
 * a real student's stored data) whose only cookie is a marker for a host
 * that cannot exist (MARKER_DOMAIN, so it is never sent anywhere). Every
 * portal client carries middleware() (PortalHttpClientOptions::forClient()),
 * which answers each request made with that cookie jar from the sanitized
 * fixture pages of tests/Fixtures/portal (PortalDemoFixtures) instead of the
 * network, whatever the configuration says by then; every other request
 * goes on unchanged. The reviewer username with any other password is
 * refused without reaching the portal either.
 */
class PortalReviewerAccount
{
    /**
     * The student every reviewer session belongs to (owner keys derive from
     * it). Not a NIM, so it never names a real student's data.
     */
    public const SESSION_USERNAME = 'store-reviewer';

    /**
     * The marker cookie of a reviewer session's cookie jar.
     */
    private const MARKER_COOKIE = 'wecic_store_reviewer';

    /**
     * The marker's domain: reserved (RFC 2606), so no portal page can set
     * it and no request ever carries it.
     */
    private const MARKER_DOMAIN = 'store-reviewer.invalid';

    /**
     * The name of middleware() in a portal client's handler stack.
     */
    public const MIDDLEWARE_NAME = 'portal_store_reviewer';

    /**
     * Whether reviewer sign-in is on: both credentials set, and a username
     * that is not all digits.
     */
    public static function enabled(): bool
    {
        $username = self::configuredUsername();

        return $username !== ''
            && self::configuredPassword() !== ''
            && preg_match('/^\d+$/', $username) !== 1;
    }

    /**
     * Whether the typed username is the reviewer's (compared in constant
     * time, ignoring surrounding whitespace); false while the mode is off.
     */
    public static function isReviewerUsername(string $username): bool
    {
        return self::enabled() && self::equals(self::configuredUsername(), trim($username));
    }

    /**
     * Whether the credentials are exactly the reviewer's (both compared in
     * constant time); false while the mode is off.
     */
    public static function matches(string $username, string $password): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $usernameMatches = self::equals(self::configuredUsername(), trim($username));
        $passwordMatches = self::equals(self::configuredPassword(), $password);

        return $usernameMatches && $passwordMatches;
    }

    /**
     * What PortalAuthService::login() answers for the reviewer's
     * credentials, without the portal: the fixture student, the marker
     * cookie and the session username to store.
     *
     * @return array{success: true, username: string, user_info: array{name: string, nim: string}, cookies: array<int, array<string, mixed>>, session_data: array{login_time: string, last_activity: string, user_agent: string}, reviewer: true}
     *
     * @throws PortalAuthenticationException when the password is not the reviewer's
     */
    public static function login(string $username, string $password): array
    {
        if (! self::matches($username, $password)) {
            throw PortalAuthenticationException::invalidCredentials();
        }

        $cookieJar = new CookieJar;
        $cookieJar->setCookie(new SetCookie([
            'Name' => self::MARKER_COOKIE,
            'Value' => '1',
            'Domain' => self::MARKER_DOMAIN,
            'Path' => '/',
            'HttpOnly' => true,
        ]));

        return [
            'success' => true,
            'username' => self::SESSION_USERNAME,
            'user_info' => PortalDemoFixtures::USER_INFO,
            'cookies' => $cookieJar->toArray(),
            'session_data' => [
                'login_time' => now()->toISOString(),
                'last_activity' => now()->toISOString(),
                'user_agent' => 'WeCIC store reviewer',
            ],
            'reviewer' => true,
        ];
    }

    /**
     * Whether a session username is a sandbox student's (the reviewer's or
     * the local demo's), whose portal is the fixtures: what it "changes" on
     * the portal is simulated, so nothing may follow it (offline password
     * hashes, dropped credentials, links to the real portal).
     */
    private static function isSandboxUsername(mixed $username): bool
    {
        return in_array($username, [self::SESSION_USERNAME, PortalDemoFixtures::USERNAME], true);
    }

    /**
     * Whether a portal session (by its token) is a sandbox student's. A
     * session that cannot be read counts as a real student's, which only
     * keeps the usual behaviour.
     */
    public static function isSandboxSession(mixed $sessionToken): bool
    {
        if (! is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        try {
            $session = app(PortalSessionManager::class)->getSession($sessionToken);
        } catch (\Throwable) {
            return false;
        }

        return self::isSandboxUsername(is_array($session) ? ($session['username'] ?? null) : null);
    }

    /**
     * Whether a cookie jar (a request's `cookies` option) is a reviewer
     * session's: it holds the marker cookie.
     */
    public static function isReviewerCookieJar(mixed $cookieJar): bool
    {
        if (! $cookieJar instanceof CookieJarInterface) {
            return false;
        }

        foreach ($cookieJar as $cookie) {
            if ($cookie->getName() === self::MARKER_COOKIE && $cookie->getDomain() === self::MARKER_DOMAIN) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guzzle middleware answering every request of a reviewer session from
     * the fixtures (a page without one is refused like an unreachable host)
     * and passing every other request on untouched.
     *
     * @return callable(callable(RequestInterface, array<string, mixed>): PromiseInterface): (callable(RequestInterface, array<string, mixed>): PromiseInterface)
     */
    public static function middleware(): callable
    {
        return static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (self::isReviewerCookieJar($options[RequestOptions::COOKIES] ?? null)) {
                return (new PortalDemoFixtures)($request, $options);
            }

            return $handler($request, $options);
        };
    }

    private static function configuredUsername(): string
    {
        return trim((string) config('portal.reviewer.username', ''));
    }

    private static function configuredPassword(): string
    {
        return (string) config('portal.reviewer.password', '');
    }

    private static function equals(string $expected, string $given): bool
    {
        return hash_equals(hash('sha256', $expected), hash('sha256', $given));
    }
}
