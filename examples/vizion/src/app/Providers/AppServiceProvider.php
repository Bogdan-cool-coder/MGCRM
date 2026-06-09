<?php

namespace App\Providers;

use App\Contracts\DocumentObjectDataResolver;
use App\Services\MacroData\DocumentObjectDataService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Document object data resolver (M2). Replaced the M1 no-op stub with
        // the real MacroData-backed implementation that fetches EstateSells +
        // related house/complex/restoration/deal and returns a flat field map.
        $this->app->bind(
            DocumentObjectDataResolver::class,
            DocumentObjectDataService::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
