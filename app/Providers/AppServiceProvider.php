<?php

namespace App\Providers;

use App\Services\CampusAuthService;
use App\Services\CampusSessionManager;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\ProfileExtractor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerCampusServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register campus-related services.
     */
    private function registerCampusServices(): void
    {
        // Register CampusAuthService
        $this->app->singleton(CampusAuthService::class, function ($app) {
            return new CampusAuthService;
        });

        // Register CampusSessionManager
        $this->app->singleton(CampusSessionManager::class, function ($app) {
            return new CampusSessionManager(
                $app->make(CampusAuthService::class),
                $app->make('encrypter')
            );
        });

        // Register extractor services (only essential ones)
        $this->app->singleton(ProfileExtractor::class, function ($app) {
            return new ProfileExtractor($app->make(CampusSessionManager::class));
        });

        $this->app->singleton(ClassesExtractor::class, function ($app) {
            return new ClassesExtractor($app->make(CampusSessionManager::class));
        });
    }
}
