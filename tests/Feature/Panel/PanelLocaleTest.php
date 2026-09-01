<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Support\Authorization\Capability;

use function Pest\Laravel\get;

/*
 * Spec I18N-1 / VIS-7: every user-facing string exists in Greek and English and
 * comes from a lang file.
 *
 * Greek is the primary locale — the operators this is built for run their
 * business in Greek — so a string that renders in English for them is a defect,
 * not a cosmetic gap. The failure mode is quiet: a hardcoded label looks
 * perfectly fine until somebody opens the panel in Greek.
 */

it('translates the panel navigation groups in both locales', function (): void {
    app()->setLocale('en');
    expect(__('panel.groups.operations'))->toBe('Operations')
        ->and(__('panel.groups.catalogue'))->toBe('Trips & boats');

    app()->setLocale('el');
    expect(__('panel.groups.operations'))->toBe('Λειτουργία')
        ->and(__('panel.groups.catalogue'))->toBe('Εκδρομές & σκάφη');
})->group('fast');

it('translates the super-admin brand name', function (): void {
    app()->setLocale('en');
    expect(__('panel.admin.brand'))->toBe('Kaiki Platform');

    app()->setLocale('el');
    expect(__('panel.admin.brand'))->toBe('Πλατφόρμα Kaiki');
})->group('fast');

it('has both locales for every panel key', function (): void {
    // Key parity, asserted structurally: a key added to one file and forgotten
    // in the other renders as the raw dotted key on screen, which nobody
    // notices until an operator sends a screenshot.
    $en = require lang_path('en/panel.php');
    $el = require lang_path('el/panel.php');

    expect(array_keys(data_get($en, 'groups')))->toBe(array_keys(data_get($el, 'groups')))
        ->and(array_keys($en))->toBe(array_keys($el));
})->group('fast');

it('translates every role label and description in both locales', function (): void {
    foreach (['el', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (Role::cases() as $role) {
            // A missing key returns the key itself, which is the tell.
            expect($role->label())->not->toBe("roles.{$role->value}.label")
                ->and($role->description())->not->toBe("roles.{$role->value}.description");
        }
    }
})->group('fast');

it('renders the login page in Greek when Greek is asked for', function (): void {
    // Was `app()->setLocale('el')` before #12, because no middleware existed to
    // set it — which meant the assertion could only ever prove that Blade reads
    // the locale, never that a real request arrives with the right one. Now it
    // drives the actual path: `?lang=` on an unauthenticated page, which is the
    // whole reason SetLocale sits in the panel's base middleware stack rather
    // than behind authentication.
    $response = get('/app/login?lang=el');

    $response->assertOk();
    expect($response->getContent())->toContain('lang="el"');
})->group('fast', 'i18n');

it('renders the login page in Greek for a Greek browser, with no query string', function (): void {
    get('/app/login', ['Accept-Language' => 'el-GR,el;q=0.9'])
        ->assertOk()
        ->assertSee('lang="el"', escape: false);
})->group('fast', 'i18n');

it('keeps capability values stable, since lang keys and policies are built on them', function (): void {
    // These strings appear in policy names, in audit logs and eventually in
    // operator-facing permission screens. Renaming one is a migration, not a
    // rename, so pinning them here makes that visible in review.
    $values = array_map(static fn (Capability $c): string => $c->value, Capability::cases());

    expect($values)->toContain('manage_billing')
        ->and($values)->toContain('manage_api_keys')
        ->and($values)->toContain('view_departures')
        ->and($values)->toContain('check_in_guests');
})->group('fast');
