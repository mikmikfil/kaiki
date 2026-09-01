<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\SetLocale;
use App\Providers\Filament\PanelRenderHooks;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

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
        // Once, not once per panel: Filament render hooks are global unless
        // scoped, so registering from both panel providers would draw the
        // language switcher twice. See PanelRenderHooks.
        PanelRenderHooks::register();

        // **Every button in the panel is a `POST /livewire/update`**, and on
        // that endpoint `SetLocale` would otherwise run from the `web` group
        // only — before `ResolveTenant`, which Filament registers as *Livewire
        // persistent* middleware and which therefore runs later, inside the
        // request. The tenant is null when the locale is decided, so step 5 of
        // the I18N-5 chain and the `supported_locales` narrowing both vanish on
        // exactly the requests an operator makes most.
        //
        // The visible symptom is a half-translated panel: correct on a full
        // page load, wrong on every table sort, filter and modal. This is the
        // same class of bug as `e62d7e1`, where the panel's tenancy middleware
        // never ran on this endpoint either. Registering it here re-runs it
        // after `ResolveTenant`, and the priority list agrees on that order.
        Livewire::addPersistentMiddleware(SetLocale::class);
    }
}
