<?php

declare(strict_types=1);

use App\Domain\Branding\Support\CssSanitizer;
use App\Enums\FontSource;
use App\Enums\WidgetTheme;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Support\Tenancy;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| A tenant is never without a brand — spec BRD-1, BRD-3
|--------------------------------------------------------------------------
|
| *"A BrandProfile is created with platform defaults when a tenant is created,
| so no surface ever renders unbranded."* The requirement is about **tenant
| creation**, not about finishing an onboarding wizard, which is why it is an
| observer and why this file asserts it through more than one creation path.
|
| Two sources of truth exist for the defaults on purpose — the column default
| protects a row written around the observer by an import or a raw insert, and
| the config is what the observer writes. They must agree, and the test that
| reads the schema to prove it is below.
|
*/

/**
 * The tenant's profile, or a failure that names the tenant.
 *
 * A helper rather than `->brandProfile()->first()` at every call site: the
 * relation is nullable to a static analyser, and `firstOrFail()` turns "BRD-3
 * did not hold" into a clear failure rather than a null-property error three
 * lines later.
 */
function brandOf(Tenant $tenant): BrandProfile
{
    return BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
}

it('creates a profile with the platform defaults when a tenant is created by factory', function (): void {
    $tenant = Tenant::factory()->create();

    $profile = brandOf($tenant);

    expect($profile->color_primary)->toBe('#0F62FE')
        ->and($profile->color_secondary)->toBe('#0B3D91')
        ->and($profile->color_accent)->toBe('#FFB000')
        ->and($profile->color_background)->toBe('#FFFFFF')
        ->and($profile->color_text)->toBe('#101828')
        ->and($profile->font_family)->toBe('Inter')
        ->and($profile->font_source)->toBe(FontSource::System)
        ->and($profile->button_radius_px)->toBe(8)
        ->and($profile->widget_theme)->toBe(WidgetTheme::Auto);
})->group('fast');

it('creates a profile when a tenant is created by the seeder', function (): void {
    // The second creation path the acceptance criteria ask for, and the one
    // that would break silently: a seeder runs with no tenant context, and the
    // observer has to write a row for a tenant that is not the resolved one.
    seed(DemoTenantSeeder::class);

    $tenants = Tenant::query()->get();

    expect($tenants)->not->toBeEmpty();

    foreach ($tenants as $tenant) {
        expect(BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->exists())
            ->toBeTrue("tenant {$tenant->slug} has no brand profile");
    }
})->group('fast');

it('creates a profile with no tenant context resolved at all', function (): void {
    // A super-admin creating an operator in /admin has no tenant context. If
    // the observer queried through the global scope this would throw
    // TenantContextMissingException, and tenant creation would 500 in the one
    // place the platform does it most.
    expect(Tenancy::check())->toBeFalse();

    $tenant = Tenant::factory()->create();

    expect($tenant->brandProfile()->withoutGlobalScopes()->exists())->toBeTrue();
})->group('fast');

it('gives each tenant its own profile and no more than one', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    expect(BrandProfile::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(brandOf($a)->getKey())->not->toBe(brandOf($b)->getKey());
})->group('fast');

it('refuses a second profile for the same tenant at the database level', function (): void {
    // BRD-1's 1:1 is a **database fact**, not a convention. Without the unique
    // index an import could give a tenant two brands and the widget would
    // render whichever the query returned first.
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        BrandProfile::factory()->create();
    });
})->throws(QueryException::class)->group('fast');

it('keeps the column defaults and the config defaults in step', function (): void {
    // The two sources exist for different writers and would be a real problem
    // if they drifted: a tenant's brand would depend on which code path created
    // it, and nothing on screen would say so. Read from the **schema**, so this
    // fails when the migration changes and the config does not.
    $columns = collect(Schema::getColumns('brand_profiles'))->keyBy('name');

    $expected = [
        'color_primary' => config('kaiki.branding.defaults.colors.primary'),
        'color_secondary' => config('kaiki.branding.defaults.colors.secondary'),
        'color_accent' => config('kaiki.branding.defaults.colors.accent'),
        'color_background' => config('kaiki.branding.defaults.colors.background'),
        'color_text' => config('kaiki.branding.defaults.colors.text'),
        'font_family' => config('kaiki.branding.defaults.font_family'),
        'font_source' => config('kaiki.branding.defaults.font_source'),
        'button_radius_px' => (string) config('kaiki.branding.defaults.button_radius_px'),
        'widget_theme' => config('kaiki.branding.defaults.widget_theme'),
    ];

    foreach ($expected as $column => $configured) {
        // SQLite reports a string default quoted; MySQL does not. Trimming the
        // quotes is the whole of the portability difference here, and it is
        // cheaper than branching on the driver (ENV-12).
        $default = trim((string) ($columns[$column]['default'] ?? ''), "'\"");

        expect($default)->toBe((string) $configured, "column default for {$column} disagrees with config");
    }
})->group('fast');

it('starts with an empty contrast result rather than a computed one', function (): void {
    // A row that has never been saved through the form has never been checked,
    // and an empty object says exactly that. Inventing a result at creation
    // would show an operator a badge for a palette they have not chosen.
    $tenant = Tenant::factory()->create();

    expect(brandOf($tenant)->contrast_warnings)->toBe([])
        ->and(brandOf($tenant)->hasContrastWarnings())->toBeFalse();
})->group('fast');

it('stores both JSON maps as objects rather than as empty arrays', function (): void {
    // §2.2 documents the default as `{}` and §3.10 documents a keyed object.
    // The `array` cast writes `[]`, which round-trips fine in PHP — both decode
    // to the same empty array — and is wrong for anything outside it: the
    // column's JSON shape would change the first time an operator added a link.
    // Read with the raw driver, because the cast is exactly what would hide it.
    $tenant = Tenant::factory()->create();

    $raw = DB::table('brand_profiles')->where('tenant_id', $tenant->getKey())->first();

    expect($raw->social_links)->toBe('{}')
        ->and($raw->contrast_warnings)->toBe('{}')
        // …and it still reads back as an array, so nothing downstream changes.
        ->and(brandOf($tenant)->social_links)->toBe([]);
})->group('fast');

it('sanitises custom_css on the way in and on the way out', function (): void {
    // §2.2 stores it raw and sanitises on read as well as write, so tightening
    // the sanitiser later is a one-file change and not a data migration over
    // every operator's stylesheet.
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function () use ($tenant): void {
        $profile = brandOf($tenant);

        $profile->custom_css = 'a{background:url(javascript:alert(1));color:red}';
        $profile->save();

        expect((string) brandOf($tenant)->custom_css)->not->toContain('javascript');
    });
})->group('fast');

it('reads a hostile row written around the model as sanitised', function (): void {
    // The case the read-side sanitisation exists for: a row inserted by an
    // import, a raw query or a tightening of the rules after it was stored.
    $tenant = Tenant::factory()->create();

    BrandProfile::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->update(['custom_css' => 'a{background:url(javascript:alert(1))}']);

    $profile = brandOf($tenant);

    expect((string) $profile->custom_css)->not->toContain('javascript')
        // …and the raw column still holds what was written, which is what makes
        // tightening the sanitiser a one-file change.
        ->and((string) $profile->rawCustomCss())->toContain('javascript')
        ->and(CssSanitizer::sanitize($profile->rawCustomCss()))->toBe($profile->custom_css);
})->group('fast');

it('deletes the profile with its tenant', function (): void {
    // The foreign key cascades. A brand profile whose tenant is gone is a row
    // nothing can ever reach, in a table with no uuid to find it by.
    $tenant = Tenant::factory()->create();
    $profileId = brandOf($tenant)->getKey();

    $tenant->forceDelete();

    expect(BrandProfile::query()->withoutGlobalScopes()->whereKey($profileId)->exists())->toBeFalse();
})->group('fast');
