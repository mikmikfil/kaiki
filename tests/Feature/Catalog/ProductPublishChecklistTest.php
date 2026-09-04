<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Enums\BookingMode;
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| The publish checklist — spec CAT-15
|--------------------------------------------------------------------------
|
| Six prerequisites, and the assertions here are on the returned **keys**
| rather than on rendered sentences. A copy edit to the Greek should not turn
| this file red, and an error code the API will return should not be a
| by-product of panel wording.
|
| One case per prerequisite missing, plus the case where everything is met —
| because a checklist that can only report failure is a checklist nobody can
| ever satisfy, and that is a bug you find in production.
|
*/

function checklistTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/** A product meeting every CAT-15 prerequisite. */
function publishableProduct(): Product
{
    $vessel = Vessel::factory()->create();
    $port = Port::factory()->create();
    $policy = CancellationPolicy::factory()->create(['is_default' => true]);

    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'meeting_point_id' => $port->getKey(),
        'cancellation_policy_id' => $policy->getKey(),
    ]);

    AgeBand::factory()->create(['product_id' => $product->getKey()]);
    RatePlan::factory()->create(['product_id' => $product->getKey()]);

    return $product->refresh();
}

it('reports nothing unmet for a complete product', function (): void {
    checklistTenant(function (): void {
        expect(ProductPublishChecklist::unmet(publishableProduct()))->toBe([])
            ->and(ProductPublishChecklist::isPublishable(publishableProduct()))->toBeTrue();
    });
})->group('fast');

it('names the missing prerequisite, and only that one', function (string $expected, Closure $break): void {
    checklistTenant(function () use ($expected, $break): void {
        $product = publishableProduct();
        $break($product);

        expect(ProductPublishChecklist::unmet($product->refresh()))->toBe([$expected]);
    });
})->with([
    'no vessel' => [
        ProductPublishChecklist::VESSEL,
        fn (Product $p) => $p->forceFill(['vessel_id' => null])->save(),
    ],
    'no meeting point' => [
        ProductPublishChecklist::MEETING_POINT,
        fn (Product $p) => $p->forceFill(['meeting_point_id' => null])->save(),
    ],
    'no age bands' => [
        ProductPublishChecklist::AGE_BANDS,
        fn (Product $p) => $p->ageBands()->forceDelete(),
    ],
    'no rate plan' => [
        ProductPublishChecklist::RATE_PLAN,
        fn (Product $p) => RatePlan::query()->where('product_id', $p->getKey())->forceDelete(),
    ],
])->group('fast');

it('treats an inactive rate plan as no rate plan', function (): void {
    checklistTenant(function (): void {
        // An operator who switched a plan off did so to stop it pricing
        // anything. Counting it here would publish a trip that cannot quote.
        $product = publishableProduct();

        RatePlan::query()->where('product_id', $product->getKey())->update(['is_active' => false]);

        expect(ProductPublishChecklist::unmet($product->refresh()))
            ->toBe([ProductPublishChecklist::RATE_PLAN]);
    });
})->group('fast');

it('falls back to the tenant default cancellation policy', function (): void {
    checklistTenant(function (): void {
        // #23 guarantees a default exists, and §2.3 says a null on the product
        // means that default — so clearing the product's own policy must not
        // fail the checklist.
        $product = publishableProduct();
        $product->forceFill(['cancellation_policy_id' => null])->save();

        expect(ProductPublishChecklist::unmet($product->refresh()))->toBe([]);
    });
})->group('fast');

it('reports a missing cancellation policy when there is no default either', function (): void {
    checklistTenant(function (): void {
        $product = publishableProduct();
        $product->forceFill(['cancellation_policy_id' => null])->save();

        CancellationPolicy::query()->forceDelete();

        expect(ProductPublishChecklist::unmet($product->refresh()))
            ->toBe([ProductPublishChecklist::CANCELLATION_POLICY]);
    });
})->group('fast');

it('requires a title in every required locale', function (): void {
    checklistTenant(function (): void {
        $product = publishableProduct();
        $product->setTranslation('title', 'en', '');
        $product->saveQuietly();

        expect(ProductPublishChecklist::unmet($product->refresh()))
            ->toBe([ProductPublishChecklist::TITLE_LOCALES]);
    });
})->group('fast');

it('does not require age bands on a whole-boat charter', function (): void {
    checklistTenant(function (): void {
        // CAT-15 says "(per-seat)" in its own parenthesis. A charter sells the
        // boat, and an invented Adult band nothing reads is worse than none.
        $product = publishableProduct();
        $product->forceFill(['mode' => BookingMode::PerVessel])->save();
        $product->ageBands()->forceDelete();

        expect(ProductPublishChecklist::unmet($product->refresh()))->toBe([]);
    });
})->group('fast');

it('reports an unsaved product as missing what it cannot yet have', function (): void {
    checklistTenant(function (): void {
        // A rate plan and an age band both need a product id. Answering "met"
        // here would let the gate pass on a product that has neither.
        $unmet = ProductPublishChecklist::unmet(new Product);

        expect($unmet)->toContain(ProductPublishChecklist::RATE_PLAN)
            ->and($unmet)->toContain(ProductPublishChecklist::VESSEL);
    });
})->group('fast');

it('keeps the checklist in a fixed order', function (): void {
    checklistTenant(function (): void {
        // An operator reading the list twice should not have to re-find their
        // place because the order changed.
        $product = Product::factory()->create(['vessel_id' => null, 'meeting_point_id' => null]);

        expect(ProductPublishChecklist::unmet($product))
            ->toBe(array_values(array_intersect(
                ProductPublishChecklist::requirements(),
                ProductPublishChecklist::unmet($product),
            )));
    });
})->group('fast');
