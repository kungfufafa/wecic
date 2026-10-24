<?php

namespace App\Providers;

use App\Services\PortalMaintainers;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Only maintainers open the dashboard outside the local environment
     * (PortalMaintainers): MyCIC signs students in with their campus session,
     * not with a Laravel user the default `viewHorizon` gate could check.
     */
    protected function authorization(): void
    {
        Horizon::auth(fn (Request $request): bool => $this->app->make(PortalMaintainers::class)->allows($request));
    }
}
