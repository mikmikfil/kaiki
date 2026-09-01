<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Spec I18N-5: the locale fallback order, asserted one step at a time.
 *
 * "Requested locale, then tenant `default_locale`, then `en`" names three
 * steps, but "requested" is itself three signals that can disagree — a `?lang=`
 * in the URL, the operator's saved panel preference, and the browser's
 * `Accept-Language`. This file pins the full order:
 *
 *   1. ?lang=                     an explicit act, right now
 *   2. session                    an explicit act, a moment ago
 *   3. users.locale               an explicit choice, saved earlier
 *   4. Accept-Language            a browser default they may never have set
 *   5. tenants.default_locale     the operator's configured house language
 *   6. en                         always installed, so the chain always ends
 *
 * Each step is asserted by making the step above it absent, which is the only
 * way a reordering shows up as a failure rather than as a coincidence.
 *
 * ADR-0008's acceptance comment is explicit that this order is implemented
 * once, in the middleware, and that nothing else re-implements it.
 *
 * ---
 *
 * **Every test here sets `Accept-Language` explicitly, including the ones about
 * what happens without it.** `Symfony\Component\HttpFoundation\Request::create()`
 * injects `Accept-Language: en-us,en;q=0.5` when the caller supplies none, so an
 * omitted header in a test is not an absent header — it is a request that asked
 * for English. Three tests here originally read as "no preference, expect the
 * tenant default", passed English straight through step 4, and failed. Send `''`
 * for genuinely absent, or a language the site does not serve for "asked for
 * something unusable".
 */

beforeEach(function (): void {
    config()->set('kaiki.tenancy.hosted_host', 'book.kaiki.test');

    // Two routes, because two different tenant-resolution strategies are needed:
    // the hosted slug resolves a tenant with no user, and the panel session
    // resolves one from the signed-in user. Both run the same middleware.
    Route::middleware(['web', 'tenant', 'locale'])
        ->get('/{slug}', fn (): string => app()->getLocale());

    Route::middleware(['web', 'locale'])
        ->get('/_test/locale', fn (): string => app()->getLocale());
});

/**
 * A tenant whose house language is Greek and who permits both.
 *
 * @param  list<string>  $supported
 */
function bilingualTenant(string $default = 'el', array $supported = ['el', 'en']): Tenant
{
    return Tenant::factory()->create([
        'slug' => 'aegean',
        'default_locale' => $default,
        'supported_locales' => $supported,
    ]);
}

it('prefers an explicit ?lang= over every other signal', function (): void {
    $tenant = bilingualTenant();
    $user = User::factory()->forTenant($tenant)->create(['locale' => 'el']);

    actingAs($user)
        ->withSession(['locale' => 'el'])
        ->get('http://book.kaiki.test/aegean?lang=en', ['Accept-Language' => 'el'])
        ->assertOk()
        ->assertSee('en');
})->group('fast', 'i18n');

it('prefers the session over the saved user preference', function (): void {
    // Someone picked English at the login screen and then signed in. The click
    // they made thirty seconds ago beats the preference they saved last month.
    $tenant = bilingualTenant();
    $user = User::factory()->forTenant($tenant)->create(['locale' => 'el']);

    actingAs($user)
        ->withSession(['locale' => 'en'])
        ->get('http://book.kaiki.test/aegean', ['Accept-Language' => 'el'])
        ->assertOk()
        ->assertSee('en');
})->group('fast', 'i18n');

it('prefers the saved user preference over Accept-Language', function (): void {
    $tenant = bilingualTenant();
    $user = User::factory()->forTenant($tenant)->create(['locale' => 'en']);

    actingAs($user)
        ->get('http://book.kaiki.test/aegean', ['Accept-Language' => 'el,en;q=0.8'])
        ->assertOk()
        ->assertSee('en');
})->group('fast', 'i18n');

it('prefers Accept-Language over the tenant default', function (): void {
    // A guest on the hosted page. No preference exists to consult, so the
    // browser is the best signal available about who is reading.
    bilingualTenant(default: 'el');

    get('http://book.kaiki.test/aegean', ['Accept-Language' => 'en-US,en;q=0.9'])
        ->assertOk()
        ->assertSee('en');
})->group('fast', 'i18n');

it('falls back to the tenant default when the browser asks for nothing it can serve', function (): void {
    // A French browser on a Greek operator's page: nothing in the request is
    // usable, so the operator's own house language is the right answer.
    bilingualTenant(default: 'el');

    get('http://book.kaiki.test/aegean', ['Accept-Language' => 'fr-FR,fr;q=0.9'])
        ->assertOk()
        ->assertSee('el');
})->group('fast', 'i18n');

it('falls back to the tenant default when the browser sends no preference at all', function (): void {
    bilingualTenant(default: 'el');

    get('http://book.kaiki.test/aegean', ['Accept-Language' => ''])
        ->assertOk()
        ->assertSee('el');
})->group('fast', 'i18n');

it('falls back to en when there is no tenant and nothing is requested', function (): void {
    // `/admin` is the real case: a super-admin has no tenant to inherit from.
    get('/_test/locale')->assertOk()->assertSee('en');
})->group('fast', 'i18n');

it('ignores a requested locale the application does not ship', function (): void {
    // Falls through to the next signal rather than erroring (criterion 8). A
    // 400 here would mean a stray `?lang=` in a shared link breaks the page.
    bilingualTenant(default: 'el');

    get('http://book.kaiki.test/aegean?lang=fr', ['Accept-Language' => 'fr'])
        ->assertOk()
        ->assertSee('el');
})->group('fast', 'i18n');

it('ignores a locale the tenant has not enabled', function (): void {
    // The application ships `en`, but this operator sells only in Greek. A
    // widget or a link asking for English must not half-translate their page.
    bilingualTenant(default: 'el', supported: ['el']);

    get('http://book.kaiki.test/aegean?lang=en', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertSee('el');
})->group('fast', 'i18n');

it('never lets an unsupported tenant default escape the installed locales', function (): void {
    // Defence in depth: `default_locale` is a two-char column with no check
    // constraint, so a bad import or a future locale switched off in config
    // must land on `en` rather than on a half-missing translation set.
    bilingualTenant(default: 'fr', supported: ['fr']);

    get('http://book.kaiki.test/aegean')
        ->assertOk()
        ->assertSee('en');
})->group('fast', 'i18n');

it('remembers an explicit choice for the next request', function (): void {
    // Without this the switcher works for exactly one page view, and every
    // link the operator clicks throws them back to Greek.
    bilingualTenant(default: 'el');

    get('http://book.kaiki.test/aegean?lang=en')->assertSee('en');

    get('http://book.kaiki.test/aegean')->assertSee('en');
})->group('fast', 'i18n');

it('never writes the saved account preference from a GET', function (): void {
    // A GET carries no CSRF token, so if `?lang=` wrote `users.locale` then
    // `<img src="https://app.kaiki.gr/app?lang=en">` on any page an
    // authenticated operator loads would permanently flip their panel language
    // — surviving the session, the browser and the device, with nothing to
    // attribute it to. Raised by the #12 security review. Persisting a
    // preference to the account belongs behind a POST on a profile screen (M7).
    $tenant = bilingualTenant();
    $user = User::factory()->forTenant($tenant)->create(['locale' => 'el']);

    actingAs($user)->get('http://book.kaiki.test/aegean?lang=en')
        ->assertOk()
        ->assertSee('en');

    // The request was honoured; the account row was not touched.
    expect($user->refresh()->locale)->toBe('el');
})->group('fast', 'i18n');

it('runs after tenant resolution even when a route lists it first', function (): void {
    // The tenant default is step 5 of the chain, so SetLocale reading a tenant
    // that ResolveTenant has not resolved yet silently skips that step — the
    // page renders, in the wrong language, with nothing in the log.
    //
    // Listing them in the right order is not enough, because Filament splits
    // them across two stacks and puts `ResolveTenant` in the later one, while
    // `Router::uniqueMiddleware` makes registering SetLocale in both a no-op.
    // The middleware priority list in `bootstrap/app.php` is what actually
    // guarantees the order; this route lists them backwards on purpose, and it
    // is the assertion that fails if that configuration is ever removed.
    bilingualTenant(default: 'el');

    // Replaces the route registered in `beforeEach` — same URI, deliberately
    // reversed middleware order.
    Route::middleware(['web', 'locale', 'tenant'])
        ->get('/{slug}', fn (): string => app()->getLocale());

    get('http://book.kaiki.test/aegean', ['Accept-Language' => 'fr'])
        ->assertOk()
        ->assertSee('el');
})->group('fast', 'i18n');

it('reaches the tenant default for a signed-in user who has never chosen', function (): void {
    // The step the review caught as unreachable. `users.locale` used to be
    // `NOT NULL DEFAULT 'el'`, so every authenticated request looked like an
    // explicit Greek preference and steps 4 and 5 were dead code in the panel —
    // an English-speaking operator's staff got a Greek panel and nothing in the
    // chain could correct it. The column is now nullable with no default, so
    // "never chosen" is expressible.
    $tenant = bilingualTenant(default: 'en');
    $user = User::factory()->forTenant($tenant)->create(['locale' => null]);

    actingAs($user)
        ->get('http://book.kaiki.test/aegean', ['Accept-Language' => 'fr'])
        ->assertOk()
        ->assertSee('en');
})->group('fast', 'i18n');

it('lets a signed-in user with no preference follow their browser', function (): void {
    // Step 4 for an authenticated request, which was equally unreachable.
    $tenant = bilingualTenant(default: 'el');
    $user = User::factory()->forTenant($tenant)->create(['locale' => null]);

    actingAs($user)
        ->get('http://book.kaiki.test/aegean', ['Accept-Language' => 'en-GB,en;q=0.9'])
        ->assertOk()
        ->assertSee('en');
})->group('fast', 'i18n');

it('is registered as Livewire-persistent, after tenant resolution', function (): void {
    // Every button in the panel is a `POST /livewire/update`. On that endpoint
    // `SetLocale` runs from the `web` group *before* `ResolveTenant`, which
    // Filament registers as Livewire-persistent and which therefore runs later,
    // inside the request — so the tenant is null when the locale is decided and
    // steps 4 and 5 of the chain vanish on exactly the requests an operator
    // makes most. The symptom is a half-translated panel: right on a full page
    // load, wrong on every sort, filter and modal.
    //
    // Same class of bug as `e62d7e1`, where the tenancy middleware never ran on
    // this endpoint either. Raised by the #12 security review.
    //
    // Asserted structurally rather than by driving a real `POST
    // /livewire/update`: constructing a valid Livewire snapshot and checksum is
    // the harness #43 exists to build, and this test should be upgraded to the
    // end-to-end form once it does.
    $persistent = array_values(Livewire::getPersistentMiddleware());

    $tenantAt = array_search(ResolveTenant::class, $persistent, strict: true);
    $localeAt = array_search(SetLocale::class, $persistent, strict: true);

    expect($localeAt)->not->toBeFalse('SetLocale is not persistent, so it never runs on POST /livewire/update')
        ->and($tenantAt)->not->toBeFalse()
        ->and($localeAt)->toBeGreaterThan($tenantAt, 'SetLocale must run after ResolveTenant, or the tenant is null when the locale is chosen');
})->group('fast', 'i18n');
