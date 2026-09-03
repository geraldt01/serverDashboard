<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $publicUrl = config('dashboard.public_url');

        if ($publicUrl !== '') {
            URL::forceRootUrl($publicUrl);
        }

        if (config('dashboard.force_https')) {
            URL::forceScheme('https');
        }
    }
}
