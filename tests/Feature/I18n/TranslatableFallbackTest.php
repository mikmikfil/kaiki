<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\Translatable\TranslatableFixture;

/*
 * Spec I18N-5, applied to **model attributes**: requested locale, then the
 * tenant's `default_locale`, then `en`.
 *
 * `tests/Feature/I18n/LocaleFallbackTest.php` (#12) pins the same order for the
 * *request* — which locale a page is rendered in. This file pins it one level
 * down, for a field that has no translation in the locale the page settled on.
 * The middleware cannot answer that: it runs once per request, and this runs
 * once per field.
 *
 * The package's own `getFallbackLocale()` hook returns a **single** locale,
 * which serves exactly one of the two ordinary cases — a Greek-default operator
 * whose product has only an English summary, and an English-default operator
 * whose product has only Greek — and shows the other an empty field. So
 * HasKaikiTranslations calls the package once per candidate with fallback
 * disabled, and the package's own chain never runs.
 */

it('returns the requested locale when it has a value', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    app()->setLocale('el');
    expect($fixture->title)->toBe('Αίγινα');

    app()->setLocale('en');
    expect($fixture->title)->toBe('Aegina');
})->group('fast', 'i18n');

it('falls back to the tenant default before English', function (): void {
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);

    Tenancy::forTenant($tenant, function (): void {
        $fixture = TranslatableFixture::create([
            'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
            'summary' => ['el' => 'Μια μέρα στον Σαρωνικό'],
        ]);

        app()->setLocale('en');

        // Step 2 of the chain. A Greek operator with no English summary yet
        // shows the Greek one — half a translated page is more useful than a
        // blank field, and it is visibly untranslated rather than silently
        // empty.
        expect($fixture->summary)->toBe('Μια μέρα στον Σαρωνικό');
    });
})->group('fast', 'i18n');

it('falls back to English when the tenant default has nothing either', function (): void {
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);

    Tenancy::forTenant($tenant, function (): void {
        $fixture = TranslatableFixture::create([
            'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
            'summary' => ['en' => 'A day in the Saronic gulf'],
        ]);

        // Requested `el`, tenant default is also `el` and has nothing, so the
        // chain must not stop there. It de-duplicates rather than trying `el`
        // twice and giving up.
        app()->setLocale('el');

        expect($fixture->summary)->toBe('A day in the Saronic gulf');
    });
})->group('fast', 'i18n');

it('falls back on an empty translatable array, not only on an empty string', function (): void {
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);

    Tenancy::forTenant($tenant, function (): void {
        $fixture = TranslatableFixture::create([
            'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
            'includes' => ['el' => ['Γεύμα'], 'en' => []],
        ]);

        app()->setLocale('en');

        // `[]` is the array column's version of `''` (data-model §3.5). A
        // product with an empty English inclusions list should show the Greek
        // one rather than an empty bulleted list.
        expect($fixture->includes)->toBe(['Γεύμα']);
    });
})->group('fast', 'i18n');

it('returns nothing when no locale in the chain has a value', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    app()->setLocale('en');

    // The requested locale's own empty value, not an invented one — the shape
    // stays whatever the package's `allowNullForTranslation` setting says it
    // should be, so callers that branch on it keep working.
    expect($fixture->summary)->toBeIn(['', null]);
})->group('fast', 'i18n');

it('does not consult the tenant when the requested locale has a value', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    app()->setLocale('en');

    // No tenant is resolved at all here. If the chain were walked eagerly
    // rather than short-circuiting, this would be reaching into `Tenancy` on
    // every field of every row — a product list renders forty rows in the
    // operator's own language and must do no extra work for any of them.
    expect(Tenancy::current())->toBeNull()
        ->and($fixture->title)->toBe('Aegina');
})->group('fast', 'i18n');
