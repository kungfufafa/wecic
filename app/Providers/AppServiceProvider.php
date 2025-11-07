<?php

namespace App\Providers;

use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalAuthService;
use App\Services\PortalSessionManager;
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
        //
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
