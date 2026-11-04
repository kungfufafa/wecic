<?php

namespace App\Providers;

use App\Http\Controllers\API\PortalAuthController;
use App\Services\PortalAuthService;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataOwner;
use App\Services\PortalFetchPolicy;
use App\Services\PortalMaintainers;
use App\Services\PortalOperationTracker;
use App\Services\PortalSessionContext;
use App\Services\PortalSessionManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerPortalServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('portal-login', function (Request $request): array {
            $username = strtolower((string) $request->input('username', 'anonymous'));
            $decayMinutes = (int) ceil(config('portal.security.login_rate_limit_decay', 300) / 60);

            return [
                Limit::perMinutes($decayMinutes, (int) config('portal.security.login_rate_limit_attempts', 5))
                    ->by($request->ip().'|'.$username)
                    ->response($this->loginThrottledResponse('Terlalu banyak percobaan login untuk akun ini. Coba lagi dalam beberapa menit.')),
                // One address cycling through usernames (a common password sprayed
                // over sequential NIMs) is held up too. Only failed logins count:
                // those the portal rejected (401) and, while it is down, a wrong
                // offline password (503, flagged by the controller), so students
                // sharing a campus address who log in fine never are.
                Limit::perMinutes($decayMinutes, (int) config('portal.security.login_failure_attempts_per_ip', 30))
                    ->by('failed-logins|'.$request->ip())
                    ->after(static fn (Response $response): bool => $response->getStatusCode() === 401
                        || $request->attributes->getBoolean(PortalAuthController::FAILED_LOGIN_ATTRIBUTE))
                    ->response($this->loginThrottledResponse('Terlalu banyak login gagal dari jaringan ini. Coba lagi dalam beberapa menit.')),
            ];
        });

        // Parameterized reads that can make the portal build a page for any id (or ?fresh=1).
        RateLimiter::for('portal-read', function (Request $request): Limit {
            return Limit::perMinute((int) config('portal.security.read_rate_limit_attempts', 60))
                ->by((string) $request->session()->get('campus_session_token', $request->ip()));
        });

        // Realtime channel sign-ins, web and API alike: a client reconnecting in a loop
        // (or a script probing channel names) is held up without touching the portal.
        RateLimiter::for('portal-channel-auth', function (Request $request): Limit {
            return Limit::perMinute((int) config('portal.security.channel_auth_rate_limit_attempts', 30))
                ->by('channel-auth|'.$request->session()->get('campus_session_token', $request->ip()));
        });

        RateLimiter::for('portal-action', function (Request $request): Limit {
            $sessionToken = (string) $request->session()->get('campus_session_token', $request->ip());

            return Limit::perMinutes(
                (int) ceil(config('portal.security.action_rate_limit_decay', 60) / 60),
                (int) config('portal.security.action_rate_limit_attempts', 10)
            )->by($sessionToken);
        });

        // Bursts only; the daily notebook budget is enforced by NotebookAssistant.
        RateLimiter::for('notebook-ai', function (Request $request): Limit {
            return Limit::perMinute(6)->by((string) $request->session()->get('campus_session_token', $request->ip()));
        });

        // Adding notebook sources reads files and meetings (and may reach the portal).
        // Bursts only; the daily budget is notebook.limits.daily_source_reads (NotebookLibrary).
        RateLimiter::for('notebook-sources', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) $request->session()->get('campus_session_token', $request->ip()));
        });

        // Without a check Log Viewer is open to anyone outside production.
        LogViewer::auth(fn (Request $request): bool => $this->app->make(PortalMaintainers::class)->allows($request));
    }

    /**
     * @return \Closure(Request, array<string, string>): JsonResponse
     */
    private function loginThrottledResponse(string $message): \Closure
    {
        return static fn (Request $request, array $headers): JsonResponse => response()->json([
            'status' => 'error',
            'message' => $message,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'campus_session_valid' => false,
            ],
        ], 429, $headers);
    }

    /**
     * Register portal-related services.
     */
    private function registerPortalServices(): void
    {
        // Stateless services, safe to share across requests and queue jobs.
        $this->app->singleton(PortalAuthService::class);
        $this->app->singleton(PortalDataOwner::class);

        $this->app->singleton(PortalSessionManager::class, function ($app) {
            return new PortalSessionManager(
                $app->make(PortalAuthService::class),
                $app->make('encrypter'),
                $app->make(PortalDataOwner::class)
            );
        });

        // Per request / per queue job state: fetch freshness, the shared
        // cookie jar and the page memo must never leak into the next job.
        $this->app->scoped(PortalFetchPolicy::class);
        $this->app->scoped(PortalSessionContext::class);

        $this->app->singleton(PortalBroadcastService::class, function (): PortalBroadcastService {
            return new PortalBroadcastService;
        });

        $this->app->singleton(PortalOperationTracker::class, function (): PortalOperationTracker {
            return new PortalOperationTracker;
        });
    }
}
