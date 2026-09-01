<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\RequireApiKeyCapability;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Laravel 11+ does not discover app/Console/Commands unless asked.
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Turns a presented API key into a resolved tenant (#6).
            'api.key' => AuthenticateApiKey::class,
            // Requires a scope on that key before any controller runs, so a
            // publishable key is refused a privileged write without the
            // application ever loading the record (spec SEC-5).
            'api.scope' => RequireApiKeyCapability::class,
            // Resolves exactly one tenant by the fixed TEN-4 order, or 404s.
            'tenant' => ResolveTenant::class,
            // Refuses unsafe methods for a tenant in read-only mode (TEN-9).
            'tenant.writable' => EnsureTenantIsWritable::class,
            // Sets the request locale by the I18N-5 chain. Aliased rather than
            // global because it must run *after* `tenant` wherever a tenant
            // exists — the tenant default is step 5 of that chain.
            'locale' => SetLocale::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
        ]);

        // `SetLocale` must run *after* `ResolveTenant`, because the tenant's
        // `default_locale` is step 5 of the I18N-5 chain.
        //
        // It cannot be done by listing them in order, and this is not obvious:
        // Filament runs its `middleware` stack for every panel route and
        // appends `authMiddleware` only for authenticated ones, and
        // `ResolveTenant` lives in the second list (#9) because it resolves
        // from the signed-in user. Registering `SetLocale` in both lists does
        // not work either — `Router::uniqueMiddleware` deduplicates and keeps
        // the *first* occurrence, so the second registration is silently
        // dropped and the tenant step never runs. That failure is invisible:
        // the page renders, in the wrong language.
        //
        // The priority list is the mechanism for exactly this. One
        // registration per route, ordered correctly wherever it appears —
        // including on routes M1 has not written yet.
        // Anchored *before* `SubstituteBindings`, not after `Authorize`.
        // Appending to the tail of the priority map would pin tenant resolution
        // behind route-model binding for every route in the application: the
        // day M1 writes
        // `Route::middleware(['tenant', 'can:view,booking'])->get('/bookings/{booking}')`
        // the sorter would hoist `SubstituteBindings` and `Authorize` above
        // `ResolveTenant` *even though the route lists `tenant` first*, and the
        // binding would resolve a tenant-owned model with no tenant
        // initialised. It fails closed rather than leaking, but it makes
        // "resolve the tenant first" unachievable by ordering a route, which is
        // the opposite of what this block buys.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->appendToPriorityList(ResolveTenant::class, SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
