<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\seed;

use Tests\Support\OperatorUser;
use Tests\Support\TenantIsolationHarness;

/*
|--------------------------------------------------------------------------
| The platform VAT table — spec CAT-11, CAT-11a, CAT-11b, TEN-5, ADR-0002
|--------------------------------------------------------------------------
|
| The one table in M1 that belongs to the platform rather than to an operator.
| Two properties carry the whole design: it is **not tenant-scoped**, so every
| operator selects from the same rows; and **no percentage is authoritative
| anywhere in this repository**, because brief §10 explicitly refuses to fix the
| Greek rates and that refusal has to survive contact with the fixtures.
|
*/

it('creates the table with the columns and indexes §2.3 specifies', function (): void {
    expect(Schema::hasTable('vat_rates'))->toBeTrue();

    foreach (['code', 'rate_bp', 'vat_category', 'description', 'valid_from', 'valid_to', 'is_selectable'] as $column) {
        expect(Schema::hasColumn('vat_rates', $column))->toBeTrue("missing column {$column}");
    }

    // The two columns that must NOT exist. `tenant_id` would make it
    // tenant-owned, and a soft delete would let a rate be removed out from
    // under the products pointing at it.
    expect(Schema::hasColumn('vat_rates', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('vat_rates', 'deleted_at'))->toBeFalse();
})->group('fast');

it('is queryable with no tenant resolved, unlike every tenant-owned model', function (): void {
    // TEN-4: a tenant-owned model throws here. This one must not — a super-admin
    // maintaining the tax table has no tenant, and neither does a console
    // command pricing across operators.
    expect(Tenancy::check())->toBeFalse();

    VatRate::factory()->create(['code' => 'platform_visible']);

    expect(VatRate::query()->count())->toBe(1);
})->group('fast', 'tenancy');

it('shows the same rows to every operator', function (): void {
    // The point of platform ownership. If a global scope ever appeared on this
    // model, each operator would see an empty tax table and no product could be
    // given a rate.
    $rate = VatRate::factory()->create(['code' => 'shared_across_tenants']);

    foreach ([Tenant::factory()->create(), Tenant::factory()->create()] as $tenant) {
        Tenancy::forTenant($tenant, function () use ($rate): void {
            expect(VatRate::query()->whereKey($rate->getKey())->exists())->toBeTrue();
        });
    }
})->group('fast', 'tenancy');

it('is named in the platform-owned allow-list rather than merely unscoped', function (): void {
    // TEN-5 allows no third state. The isolation gate reads this list, so a
    // model that is neither scoped nor listed turns it red — this asserts
    // VatRate passes *because it is listed*, not because it was skipped.
    expect(config('tenancy.platform_owned_models'))->toContain(VatRate::class)
        ->and(TenantIsolationHarness::tenantOwnedModels())->not->toContain(VatRate::class);
})->group('fast', 'tenancy');

it('refuses two rows with the same code and start date', function (): void {
    // `vat_rates_code_from_unique`. A statutory change is a new row with a later
    // `valid_from`; two rows claiming the same code on the same day would make
    // "which rate was in force" ambiguous, which is the one question this table
    // exists to answer.
    VatRate::factory()->create(['code' => 'gr_transport', 'valid_from' => '2026-01-01']);
    VatRate::factory()->create(['code' => 'gr_transport', 'valid_from' => '2026-01-01']);
})->throws(QueryException::class)->group('fast');

it('allows the same code again from a later date', function (): void {
    // The shape of a statutory change: same code, new row, new start date.
    VatRate::factory()->create(['code' => 'gr_transport', 'valid_from' => '2020-01-01', 'valid_to' => '2026-06-30']);
    VatRate::factory()->create(['code' => 'gr_transport', 'valid_from' => '2026-07-01']);

    expect(VatRate::query()->where('code', 'gr_transport')->count())->toBe(2);
})->group('fast');

it('resolves which rate was in force on a date', function (): void {
    $old = VatRate::factory()->create(['code' => 'r', 'valid_from' => '2020-01-01', 'valid_to' => '2026-06-30']);
    $new = VatRate::factory()->create(['code' => 'r', 'valid_from' => '2026-07-01']);

    $before = VatRate::query()->inForceOn(Carbon::parse('2026-03-15'))->pluck('id')->all();
    $after = VatRate::query()->inForceOn(Carbon::parse('2026-09-15'))->pluck('id')->all();

    expect($before)->toBe([$old->getKey()])
        ->and($after)->toBe([$new->getKey()]);
})->group('fast');

it('hides a retired rate from selection while existing references still resolve', function (): void {
    // Asserted through a query rather than through the form, because the form is
    // not the only reader — the API and the importer select rates too.
    $live = VatRate::factory()->create(['code' => 'live']);
    $retired = VatRate::factory()->retired()->create(['code' => 'retired']);

    expect(VatRate::query()->selectable()->pluck('id')->all())->toBe([$live->getKey()])
        // Still there, still readable, still the truth about what was charged.
        ->and(VatRate::query()->whereKey($retired->getKey())->exists())->toBeTrue()
        ->and($retired->isInForce())->toBeFalse();
})->group('fast');

it('keeps the rate in basis points so nothing rounds', function (): void {
    // CNV-1's reasoning applied to a percentage: a rate that renders as
    // 12.999999 on an invoice is a rate that gets argued about with the tax
    // authority.
    $rate = VatRate::factory()->atBasisPoints(1250)->create();

    expect($rate->rate_bp)->toBe(1250)
        ->and($rate->rate_bp)->toBeInt()
        ->and($rate->percentLabel())->toBe('12.50%');
})->group('fast');

it('presents no percentage as authoritative anywhere in the fixtures', function (): void {
    // CAT-11b and MYD-6a. Brief §10 says transport is *typically* 13% and other
    // services *typically* 24%, and refuses to fix them — so a seeded 1300 would
    // be this project quietly deciding an accountant's question, and the first
    // person to read it would reasonably believe it had been decided.
    seed();

    $statutory = [2400, 1300, 600, 1700, 900, 400];

    $seeded = VatRate::query()->pluck('rate_bp')->all();

    foreach ($seeded as $rateBp) {
        expect($statutory)->not->toContain($rateBp, "a seeder wrote {$rateBp} basis points, which reads as a real Greek rate");
    }

    // …and the factory's own default is not one either, so a test that never
    // thought about VAT cannot accidentally assert on a plausible rate.
    expect($statutory)->not->toContain(VatRate::factory()->make()->rate_bp);
})->group('fast');

it('lets a super-admin reach the VAT screen and refuses an operator', function (): void {
    // Platform reference data: an operator selects a rate through the product
    // form and never maintains one. `viewAny` is what keeps it out of /app's
    // navigation as well as its routes.
    seed();

    $superAdmin = User::query()->where('is_super_admin', true)->firstOrFail();

    actingAs($superAdmin)->get('/admin/vat-rates')->assertSuccessful();
    actingAs(OperatorUser::withRole(Role::Owner))->get('/admin/vat-rates')->assertForbidden();
})->group('fast');

it('never lets anyone delete a rate', function (): void {
    // §2.3 has no soft deletes and the FKs from products and extras are
    // restrictOnDelete, so a delete could only ever surface a constraint
    // violation. Retiring is `is_selectable`.
    seed();

    $superAdmin = User::query()->where('is_super_admin', true)->firstOrFail();
    $rate = VatRate::factory()->create();

    expect($superAdmin->can('delete', $rate))->toBeFalse()
        ->and($superAdmin->can('forceDelete', $rate))->toBeFalse()
        ->and($superAdmin->can('update', $rate))->toBeTrue();
})->group('fast');

it('renders the VAT labels in Greek and in English', function (): void {
    seed();

    $superAdmin = User::query()->where('is_super_admin', true)->firstOrFail();

    // Asserted on a **table column header**, not on the model label: Filament
    // title-cases a model label for the heading, so `vat.model.plural` renders
    // as "VAT Rates" while the lang file says "VAT rates" — a test that fails
    // on the casing of a word nobody chose. Column labels render verbatim.
    actingAs($superAdmin)->get('/admin/vat-rates?lang=el')
        ->assertSuccessful()
        ->assertSee(__('vat.table.is_selectable', locale: 'el'))
        ->assertDontSee('vat.table.is_selectable');

    actingAs($superAdmin)->get('/admin/vat-rates?lang=en')
        ->assertSuccessful()
        ->assertSee(__('vat.table.is_selectable', locale: 'en'));
})->group('fast', 'i18n');
