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
        $appUrl = (string) config('app.url');
        $forceHttps = (bool) config('app.force_https')
            || (config('app.env') === 'production' && str_starts_with($appUrl, 'https://'));

        if ($forceHttps) {
            URL::forceScheme('https');
        }
    }
}
