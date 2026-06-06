<?php

namespace App\Providers;

use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalAuthService;
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
        // Register PortalAuthService
        $this->app->singleton(PortalAuthService::class, function ($app) {
            return new PortalAuthService;
        });

        // Register PortalSessionManager
        $this->app->singleton(PortalSessionManager::class, function ($app) {
            return new PortalSessionManager(
                $app->make(PortalAuthService::class),
                $app->make('encrypter')
            );
        });

        // Register extractor services (only essential ones)
        $this->app->singleton(ProfileExtractor::class, function ($app) {
            return new ProfileExtractor($app->make(PortalSessionManager::class));
        });

        $this->app->singleton(ClassesExtractor::class, function ($app) {
            return new ClassesExtractor($app->make(PortalSessionManager::class));
        });
    }
}
