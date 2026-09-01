<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\Support\OperatorUser;

/*
 * The EL / EN switcher and the light / dark control.
 *
 * I18N-5 made the locale resolvable; without a visible control the operator can
 * only change it by hand-editing a URL. These assertions are about the control
 * existing where it can be reached — particularly on the login page, where the
 * person who most needs it has no account yet to store a preference on.
 */

it('renders the switcher on the login page, before anyone signs in', function (): void {
    $response = get('/app/login');

    $response->assertOk()
        ->assertSee('kaiki-locale-switcher', escape: false)
        // Both options, not just the current one.
        ->assertSee('hreflang="el"', escape: false)
        ->assertSee('hreflang="en"', escape: false);
})->group('fast', 'i18n');

it('renders the switcher on the super-admin login page too', function (): void {
    get('/admin/login')->assertOk()->assertSee('kaiki-locale-switcher', escape: false);
})->group('fast', 'i18n');

it('marks the active locale, and only the active one', function (): void {
    $content = (string) get('/app/login?lang=el')->getContent();

    expect(substr_count($content, 'aria-current="true"'))->toBe(1)
        ->and(substr_count($content, 'aria-current="false"'))->toBe(1);
})->group('fast', 'i18n');

it('draws flags as inline SVG rather than emoji', function (): void {
    // Windows Chrome renders `🇬🇷` as the letters "GR", and Windows is what
    // these operators use. If this ever regresses to emoji the flags silently
    // become text on the majority platform.
    $content = (string) get('/app/login')->getContent();

    expect($content)->toContain('<svg class="kaiki-locale-switcher__flag"')
        ->and($content)->not->toContain('🇬🇷')
        ->and($content)->not->toContain('🇬🇧');
})->group('fast', 'i18n');

it('keeps the rest of the query string when switching language', function (): void {
    // Switching language on a filtered table must not silently reset the
    // filters — which is what a bare `?lang=` link would do.
    $content = (string) get('/app/login?tableFilters[status]=active')->getContent();

    expect($content)->toContain('tableFilters');
})->group('fast', 'i18n');

it('renders the switcher in the topbar once signed in', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee('kaiki-locale-switcher', escape: false);
})->group('fast', 'i18n');

it('draws the switcher exactly once per page', function (): void {
    // Filament render hooks are global unless scoped, so registering from both
    // panel providers would draw two switchers side by side. The count is the
    // assertion that catches it.
    $owner = OperatorUser::withRole(Role::Owner);

    $content = (string) actingAs($owner)->get('/app')->getContent();

    expect(substr_count($content, '<nav class="kaiki-locale-switcher"'))->toBe(1);
})->group('fast', 'i18n');

it('offers no switcher to a tenant that sells in one language only', function (): void {
    // A control that half-translates the operator's own page is worse than no
    // control. `supported_locales` narrows the switcher exactly as it narrows
    // what LocaleResolver will honour.
    $tenant = Tenant::factory()->create([
        'default_locale' => 'el',
        'supported_locales' => ['el'],
    ]);

    $owner = OperatorUser::withRole(Role::Owner, $tenant);

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertDontSee('kaiki-locale-switcher', escape: false);
})->group('fast', 'i18n');

it('switches the panel language when the switcher is followed', function (): void {
    $tenant = Tenant::factory()->create(['default_locale' => 'el', 'supported_locales' => ['el', 'en']]);
    $owner = OperatorUser::withRole(Role::Owner, $tenant);

    actingAs($owner)->get('/app?lang=en')
        ->assertSuccessful()
        ->assertSee('lang="en"', escape: false);

    // It sticks for the session, and deliberately does NOT write the account
    // row — a GET has no CSRF token, so that write would be forgeable from any
    // page the operator visits. See LocaleFallbackTest.
    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee('lang="en"', escape: false);

    expect(User::query()->whereKey($owner->getKey())->value('locale'))->toBeNull();
})->group('fast', 'i18n');

it('keeps light and dark mode available in both panels', function (): void {
    // Filament enables dark mode by default and renders the light/dark/system
    // control in the user menu. Asserted rather than assumed: it disappears
    // silently the day a panel gains a `->darkMode(false)` or a forced theme,
    // and nobody notices until someone asks where the toggle went.
    foreach (['app', 'admin'] as $panel) {
        $filament = Filament::getPanel($panel);

        expect($filament->hasDarkMode())->toBeTrue("panel `{$panel}` has dark mode switched off")
            ->and($filament->hasDarkModeForced())->toBeFalse("panel `{$panel}` forces one theme, so the switcher is hidden");
    }
})->group('fast', 'i18n');

it('renders the theme switcher into the signed-in panel', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee('fi-theme-switcher', escape: false);
})->group('fast', 'i18n');

it('labels the theme control in Greek', function (): void {
    // Filament ships Greek translations; this asserts they actually reach the
    // page rather than the panel falling back to English chrome around Greek
    // content, which is the half-translated look I18N-1 exists to prevent.
    $tenant = Tenant::factory()->create(['default_locale' => 'el', 'supported_locales' => ['el', 'en']]);
    $owner = OperatorUser::withRole(Role::Owner, $tenant);

    $content = (string) actingAs($owner)->get('/app?lang=el')->getContent();

    expect($content)->toContain(__('filament-panels::layout.actions.theme_switcher.light.label'));
})->group('fast', 'i18n');
