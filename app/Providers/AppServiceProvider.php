<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One id per request (or per queued job), resolved lazily and reused by
        // every log line that request produces (OBS-2). A singleton rather than
        // middleware so it also exists for console commands and jobs, which
        // never pass through the HTTP stack.
        $this->app->singleton('kaiki.request_id', static fn (): string => (string) Str::uuid());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
