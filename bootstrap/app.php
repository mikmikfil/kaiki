<?php

declare(strict_types=1);

use App\Http\Middleware\ApiKeyCors;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateGuestToken;
use App\Http\Middleware\CustomDomainOnly;
use App\Http\Middleware\EnforceIdempotencyKey;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\GuestTokenPage;
use App\Http\Middleware\HostedPageHeaders;
use App\Http\Middleware\HostedRootPipeline;
use App\Http\Middleware\RequireApiKeyCapability;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleTokenLookups;
use App\Http\Responses\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        // `/api/v1` rather than Laravel's `/api` default: the version is part
        // of the contract (§1.3) and lives in the prefix, so a v2 is a second
        // route file rather than an edit to this one.
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
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
            // The four guest pages, where a URL is a credential (TOK-3, TOK-4).
            // Middleware rather than controller code: a header set in a
            // controller is a header the fifth token page forgets, and the
            // failure page is the one most likely to be built outside the
            // group because it is "just an error".
            'guest.token' => GuestTokenPage::class,
            'guest.throttle' => ThrottleTokenLookups::class,
            // `Idempotency-Key` for every write that needs it, once (§3.4).
            // One middleware rather than a guard per endpoint: four
            // hand-rolled copies come out as four dialects, and the fourth is
            // the one missing the in-flight case.
            'idempotency' => EnforceIdempotencyKey::class,
            // `X-Kaiki-Guest-Token` on the three per-booking endpoints, and the
            // first place in the product where a publishable key is refused
            // (§2.1). A uuid alone never authorises anything.
            'api.guest' => AuthenticateGuestToken::class,
            // The hosted operator pages: which operator, may it be served,
            // and HOS-8's Content-Security-Policy. Middleware rather than
            // controller code, for the reason `guest.token` gives — a header
            // set in a controller is a header the fourth page forgets.
            'hosted.page' => HostedPageHeaders::class,

            // #109: lets the hosted pages answer at the **root** of a verified
            // custom domain without a `/{product}` route at the root of every
            // host — which is the mistake #101 made and documented. The routes
            // are registered last and this refuses any host that did not
            // resolve through `CustomDomainResolver`.
            'hosted.custom' => CustomDomainOnly::class,

            // #109: `/` is the platform's front page on the platform's host and
            // an operator's home page on theirs, and `ResolveTenant` 404s when
            // nothing resolves — so the hosted stack is applied conditionally,
            // inside the pipeline, rather than on the route.
            'hosted.root' => HostedRootPipeline::class,
        ]);

        // Per-key CORS from `api_keys.allowed_origins` (SEC-7), and the
        // preflight answer. **Global**, because a browser sends no
        // `Authorization` on a preflight and an `OPTIONS` request with no
        // matching route is a 405 before any group middleware runs. It
        // no-ops outside `api/*`.
        $middleware->prepend(ApiKeyCors::class);

        $middleware->web(append: [
            SetLocale::class,
        ]);

        /*
         * A payment gateway holds no session and no CSRF token (PAY-6).
         *
         * By path rather than by route name, because the exclusion has to
         * apply before routing resolves anything — and narrow, so that adding
         * a second `/webhooks/*` route later is a deliberate act rather than an
         * accident of a wildcard somebody widened.
         */
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

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
        // The priority list is the mechanism for exactly this. One registration
        // per route, ordered correctly wherever it appears — including on
        // routes M1 has not written yet.
        //
        // `ResolveTenant` is anchored *before* `SubstituteBindings` rather than
        // at the tail of the map. Appending would pin tenant resolution behind
        // route-model binding for every route in the application: the day M1
        // writes `Route::middleware(['tenant', 'can:view,booking'])->get('/bookings/{booking}')`
        // the sorter would hoist `SubstituteBindings` and `Authorize` above
        // `ResolveTenant` *even though the route lists `tenant` first*, and the
        // binding would resolve a tenant-owned model with no tenant
        // initialised.
        //
        // **Once one of these is in the priority map, they all have to be.**
        // The sorter reorders only the middleware it knows about, moving them
        // around the ones it does not — so listing `ResolveTenant` alone was
        // enough to hoist it above `AuthenticateApiKey` on `/api/v1/health`,
        // even though `routes/api.php` lists `api.key` first. The symptom was a
        // request with no key at all returning **404 instead of 401**: tenant
        // resolution ran first, found nothing to resolve from, and aborted
        // before the middleware whose job is to say "no key" ever ran. Caught
        // by #11's health test; the order below is asserted by
        // `ApiMiddlewareOrderTest` so it cannot drift back.
        //
        // The chain, and why it is this way:
        //   AuthenticateApiKey       a bad key is 401, before anything loads
        //   RequireApiKeyCapability  a scope refusal is 403 "without the
        //                            application ever loading the record" (SEC-5)
        //   ResolveTenant            exactly one tenant, or 404
        //   SetLocale                needs the tenant for step 5 of I18N-5
        //   EnsureTenantIsWritable   last, so its refusal is already localised
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(ResolveTenant::class, RequireApiKeyCapability::class);
        $middleware->prependToPriorityList(RequireApiKeyCapability::class, AuthenticateApiKey::class);
        $middleware->appendToPriorityList(ResolveTenant::class, SetLocale::class);
        $middleware->appendToPriorityList(SetLocale::class, EnsureTenantIsWritable::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // `docs/api.md` §4.1 promises one envelope for every failure on every
        // endpoint. The failures that break that promise are the ones no
        // controller ever sees — a mistyped path, the wrong verb, a validation
        // failure thrown before any of our code runs. Laravel answers those
        // with an HTML page or its own `{"message": …}`, and a widget branching
        // on `error.code` gets a parse error instead of a reason.
        //
        // Scoped to `/api/v1` on purpose: the panel and the hosted pages are
        // HTML and must keep rendering Laravel's error view.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/v1/*') && $request->path() !== 'api/v1') {
                return null;
            }

            return ApiExceptionRenderer::render($e);
        });
    })->create();
