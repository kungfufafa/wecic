<?php

namespace App\Providers;

use App\Services\PortalAuthService;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataOwner;
use App\Services\PortalFetchPolicy;
use App\Services\PortalOperationTracker;
use App\Services\PortalSessionContext;
use App\Services\PortalSessionManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
