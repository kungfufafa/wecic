<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ToastService;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class ToastServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Share toast data with all views
        view()->composer('*', function (View $view) {
            $view->with('toast', ToastService::peek());
        });
    }
}
