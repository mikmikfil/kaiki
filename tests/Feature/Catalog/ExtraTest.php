<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveExtra;
use App\Domain\Catalog\Data\OfferedExtra;
use App\Domain\Catalog\Support\OfferedExtrasResolver;
use App\Enums\ExtraPricing;
use App\Models\Extra;
use App\Models\Product;
use App\Models\ProductExtra;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TenantIsolationHarness;

/*
|--------------------------------------------------------------------------
| Extras — spec CAT-12, PRC-9, PRC-10
|--------------------------------------------------------------------------
|
| Two things carry this table. **Scoping is the pivot**: tenant-wide with no
| pivot rows means every product, and a pivot row adds *terms* rather than
| membership. And **every override is nullable, with null meaning inherit** —
| including `is_required_override`, which is a tri-state boolean where `false`
| must beat the extra's `true`.
|
| That tri-state is the reason `OfferedExtra` exists: `??` resolves it
| correctly and `?:` does not, and four callers writing that chain themselves
| would get it wrong at least once.
|
*/

function extraTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

it('offers a tenant-wide extra on every product with no pivot row at all', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();
        Extra::factory()->tenantWide()->create();

        expect(OfferedExtrasResolver::forProduct($product))->toHaveCount(1);
    });
})->group('fast');

it('does not offer a product-scoped extra to a product that has no pivot row', function (): void {
    extraTenant(function (): void {
        $scoped = Product::factory()->create();
        $other = Product::factory()->create();
        $extra = Extra::factory()->create();

        ProductExtra::factory()->create([
            'product_id' => $scoped->getKey(),
            'extra_id' => $extra->getKey(),
        ]);

        expect(OfferedExtrasResolver::forProduct($scoped))->toHaveCount(1)
            ->and(OfferedExtrasResolver::forProduct($other))->toHaveCount(0);
    });
})->group('fast');

it('still offers a tenant-wide extra to a product with no pivot row of its own', function (): void {
    // The subtle case: a tenant-wide extra with a pivot row on *another*
    // product is still tenant-wide here. The pivot adds terms, not membership,
    // and reading it as membership would silently un-offer an extra everywhere
    // the moment an operator customised it on one trip.
    extraTenant(function (): void {
        $customised = Product::factory()->create();
        $plain = Product::factory()->create();
        $extra = Extra::factory()->tenantWide()->create();

        ProductExtra::factory()->create([
            'product_id' => $customised->getKey(),
            'extra_id' => $extra->getKey(),
            'price_cents_override' => 2500,
        ]);

        expect(OfferedExtrasResolver::forProduct($plain))->toHaveCount(1)
            ->and(OfferedExtrasResolver::forProduct($plain)->first()?->priceCents)->toBe(1500)
            ->and(OfferedExtrasResolver::forProduct($customised)->first()?->priceCents)->toBe(2500);
    });
})->group('fast');

it('inherits every value when the overrides are null', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->limitedTo(4)->requiredExtra()->create();

        ProductExtra::factory()->create([
            'product_id' => $product->getKey(),
            'extra_id' => $extra->getKey(),
        ]);

        $offered = OfferedExtrasResolver::forProduct($product)->first();

        expect($offered?->priceCents)->toBe(1500)
            ->and($offered?->maxQty)->toBe(4)
            ->and($offered?->isRequired)->toBeTrue();
    });
})->group('fast');

it('lets each of the three overrides win', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->limitedTo(4)->requiredExtra()->create();

        ProductExtra::factory()->create([
            'product_id' => $product->getKey(),
            'extra_id' => $extra->getKey(),
            'price_cents_override' => 2500,
            'max_qty_override' => 2,
            'is_required_override' => false,
        ]);

        $offered = OfferedExtrasResolver::forProduct($product)->first();

        expect($offered?->priceCents)->toBe(2500)
            ->and($offered?->maxQty)->toBe(2)
            // The tri-state, and the case `?:` would get wrong: an explicit
            // false must beat the extra's true.
            ->and($offered?->isRequired)->toBeFalse();
    });
})->group('fast');

it('treats a false override as a real answer rather than as absent', function (): void {
    // Stated on its own because it is the bug `OfferedExtra` exists to prevent.
    // With `?:` instead of `??`, a required extra made optional for one product
    // would stay required — and a guest would be charged for something they
    // never chose.
    $extra = Extra::factory()->make(['is_required' => true, 'price_cents' => 1500]);
    $extra->id = 1;
    $extra->uuid = 'x';

    $pivot = new ProductExtra(['is_required_override' => false]);

    expect(OfferedExtra::resolve($extra, $pivot)->isRequired)->toBeFalse();
})->group('fast');

it('refuses an on-request extra that carries a price', function (): void {
    // CAT-12. Refused rather than ignored: a price stored but never charged is
    // a price the next reader of the column will charge.
    extraTenant(function (): void {
        expect(fn () => app(SaveExtra::class)(new Extra, [
            'name' => ['el' => 'Σεφ', 'en' => 'Chef'],
            'pricing_type' => ExtraPricing::OnRequest->value,
            'price_cents' => 20000,
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('accepts an on-request extra with no price and never prices it', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();

        $extra = app(SaveExtra::class)(new Extra, [
            'name' => ['el' => 'Σεφ', 'en' => 'Chef'],
            'pricing_type' => ExtraPricing::OnRequest->value,
            'is_tenant_wide' => true,
        ]);

        $offered = OfferedExtrasResolver::one($product, $extra);

        expect($extra->price_cents)->toBeNull()
            ->and($offered?->priceCents)->toBeNull()
            // Null rather than zero: zero would render as free, which is a
            // different promise from "we will arrange it and tell you".
            ->and($offered?->totalCents(1, 4))->toBeNull();
    });
})->group('fast');

it('ignores a price override on an on-request extra', function (): void {
    // An override must not be able to promote an unpriced extra into the total.
    extraTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->onRequest()->create();

        ProductExtra::factory()->create([
            'product_id' => $product->getKey(),
            'extra_id' => $extra->getKey(),
            'price_cents_override' => 9900,
        ]);

        expect(OfferedExtrasResolver::forProduct($product)->first()?->priceCents)->toBeNull();
    });
})->group('fast');

it('multiplies a per-person extra by the party size and a per-booking one not at all', function (): void {
    // PRC-9, and the arithmetic in integer cents throughout (CNV-1).
    $perPerson = Extra::factory()->make(['pricing_type' => ExtraPricing::PerPerson, 'price_cents' => 1500]);
    $perPerson->id = 1;
    $perPerson->uuid = 'a';

    $perBooking = Extra::factory()->make(['pricing_type' => ExtraPricing::PerBooking, 'price_cents' => 4000]);
    $perBooking->id = 2;
    $perBooking->uuid = 'b';

    expect(OfferedExtra::resolve($perPerson)->totalCents(1, 4))->toBe(6000)
        ->and(OfferedExtra::resolve($perBooking)->totalCents(1, 4))->toBe(4000)
        // Two hotel transfers for a party of four is still two transfers.
        ->and(OfferedExtra::resolve($perBooking)->totalCents(2, 4))->toBe(8000);
})->group('fast');

it('refuses a quantity above the maximum, server-side', function (): void {
    // PRC-10. The widget runs on somebody else's page, so a client-side limit
    // is a suggestion and this is the authority.
    extraTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->tenantWide()->limitedTo(2)->create();

        $offered = OfferedExtrasResolver::one($product, $extra);

        expect($offered?->allowsQuantity(2))->toBeTrue()
            ->and($offered?->allowsQuantity(3))->toBeFalse()
            ->and($offered?->allowsQuantity(-1))->toBeFalse();
    });
})->group('fast');

it('treats a null maximum as unlimited', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->tenantWide()->create();

        expect(OfferedExtrasResolver::one($product, $extra)?->allowsQuantity(99))->toBeTrue();
    });
})->group('fast');

it('honours a max_qty override that is lower than the extra', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->tenantWide()->limitedTo(10)->create();

        ProductExtra::factory()->create([
            'product_id' => $product->getKey(),
            'extra_id' => $extra->getKey(),
            'max_qty_override' => 1,
        ]);

        expect(OfferedExtrasResolver::one($product, $extra)?->allowsQuantity(2))->toBeFalse();
    });
})->group('fast');

it('skips an inactive extra', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();
        Extra::factory()->tenantWide()->create(['is_active' => false]);

        expect(OfferedExtrasResolver::forProduct($product))->toHaveCount(0);
    });
})->group('fast');

it('resolves in a bounded number of queries', function (): void {
    // NFR-6: this runs on every availability response and every checkout
    // render, so it must not scale with the size of the extras catalogue.
    extraTenant(function (): void {
        $product = Product::factory()->create();

        for ($i = 0; $i < 15; $i++) {
            Extra::factory()->tenantWide()->create(['sort_order' => $i]);
        }

        DB::enableQueryLog();
        OfferedExtrasResolver::forProduct($product);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One for the pivot rows, one for the extras.
        expect($queries)->toBeLessThanOrEqual(2);
    });
})->group('fast');

it('refuses two pivot rows for the same product and extra', function (): void {
    // `product_extra_uq`. Two would make "which override applies" a matter of
    // row order.
    extraTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->create();

        ProductExtra::factory()->create(['product_id' => $product->getKey(), 'extra_id' => $extra->getKey()]);
        ProductExtra::factory()->create(['product_id' => $product->getKey(), 'extra_id' => $extra->getKey()]);
    });
})->throws(QueryException::class)->group('fast');

it('scopes the pivot to its tenant like any other row', function (): void {
    // A pivot outside the tenant scope is the one table where a cross-tenant
    // read is invisible, because nothing about a join table looks like a leak.
    expect(TenantIsolationHarness::tenantOwnedModels())->toContain(ProductExtra::class);
})->group('fast', 'tenancy');

it('syncs product overrides through the Action', function (): void {
    extraTenant(function (): void {
        $product = Product::factory()->create();

        $extra = app(SaveExtra::class)(new Extra, [
            'name' => ['el' => 'Μεταφορά', 'en' => 'Transfer'],
            'pricing_type' => ExtraPricing::PerBooking->value,
            'price_cents' => 4000,
        ], [
            $product->getKey() => ['price_cents_override' => 5000, 'is_required_override' => true],
        ]);

        expect(OfferedExtrasResolver::one($product, $extra)?->priceCents)->toBe(5000)
            ->and(OfferedExtrasResolver::one($product, $extra)?->isRequired)->toBeTrue();
    });
})->group('fast');

it('leaves an absent is_required_override as inherit rather than false', function (): void {
    // Casting an absent value to a boolean here would silently force every
    // product to optional — including one an operator had marked required.
    extraTenant(function (): void {
        $product = Product::factory()->create();

        $extra = app(SaveExtra::class)(new Extra, [
            'name' => ['el' => 'Μεταφορά', 'en' => 'Transfer'],
            'pricing_type' => ExtraPricing::PerBooking->value,
            'price_cents' => 4000,
            'is_required' => true,
        ], [
            $product->getKey() => ['price_cents_override' => 5000],
        ]);

        expect(OfferedExtrasResolver::one($product, $extra)?->isRequired)->toBeTrue();
    });
})->group('fast');
