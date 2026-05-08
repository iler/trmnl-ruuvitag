<?php

namespace App\Providers;

use App\Services\Ruuvi\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client(
            token: config('ruuvi.api_token'),
            baseUrl: config('ruuvi.api_base_url'),
            cacheTtl: config('ruuvi.cache_ttl'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
