<?php

declare(strict_types=1);

namespace App\Providers;

use App\Audit\BraveSearchClient;
use App\Audit\BrowserlessPublicationClient;
use App\Audit\PublicationBrowserClient;
use App\Audit\PublicationSearchClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PublicationSearchClient::class, BraveSearchClient::class);
        $this->app->singleton(PublicationBrowserClient::class, BrowserlessPublicationClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        RateLimiter::for('login', fn (Request $request) => [Limit::perMinute(5)->by('email-'.mb_strtolower($request->input('email', ''))), Limit::perMinute(20)->by('ip-'.$request->ip())]);
    }
}
