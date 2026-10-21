<?php

namespace App\Providers;

use App\Services\Forge\ForgeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ForgeClient::class, fn () => new ForgeClient(
            token: (string) config('forge.token'),
            baseUrl: (string) config('forge.base_url'),
            secondsBetweenRequests: (float) config('forge.seconds_between_requests'),
            maxAttempts: (int) config('forge.max_attempts'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
