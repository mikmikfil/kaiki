<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Enums\AgeBandPricing;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Age bands — spec CAT-7, CAT-8, AVL-23, PRC-7
|--------------------------------------------------------------------------
|
| `counts_toward_capacity` is the flag the availability engine turns on. An
| infant on a lap **consumes no seat** (so it does not reduce what can be sold)
| and **is still a person aboard** (so the legal capacity check counts it) and
| **may still be charged** (so pricing is a third, separate question). Three
| facts, one boolean, and getting it backwards oversells a departure or
| undersells a boat.
|
| Every CAT-8 rule is set-level — none can be judged from a single row — which
| is why they live in the Action and are tested through it.
|
*/

function bandProduct(): Product
{
    return Product::factory()->create();
}

function inBandTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bandInput(array $overrides = []): array
{
    return array_merge([
        'code' => 'adult',
        'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'],
        'min_age' => 12,
        'max_age' => null,
        'counts_toward_capacity' => true,
        'pricing_mode' => AgeBandPricing::Multiplier->value,
        'price_multiplier_bp' => 10000,
        'is_base' => true,
    ], $overrides);
}

it('saves a valid set of bands', function (): void {
    inBandTenant(function (): void {
        $product = bandProduct();

        $bands = app(SaveAgeBands::class)($product, [
            bandInput(),
            bandInput(['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 11, 'price_multiplier_bp' => 5000, 'is_base' => false]),
            bandInput(['code' => 'infant', 'label' => ['el' => 'Βρέφος', 'en' => 'Infant'], 'min_age' => 0, 'max_age' => 2, 'counts_toward_capacity' => false, 'price_multiplier_bp' => 0, 'is_base' => false]),
        ]);

        expect($bands)->toHaveCount(3)
            ->and($product->ageBands()->count())->toBe(3);
    });
})->group('fast');

it('refuses overlapping ranges, naming both bands', function (): void {
    // CAT-8. "Your bands overlap" on a set of five leaves the operator to work
    // out which two, so the message names them.
    inBandTenant(function (): void {
        try {
            app(SaveAgeBands::class)(bandProduct(), [
                bandInput(['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 12, 'is_base' => false, 'price_multiplier_bp' => 5000]),
                bandInput(['min_age' => 12, 'max_age' => null]),
            ]);

            expect(false)->toBeTrue('the overlap was not refused');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->validator->errors()->all());

            expect($message)->toContain('Παιδί')->toContain('Ενήλικας');
        }
    });
})->group('fast');

it('treats a null upper bound as overlapping everything above its minimum', function (): void {
    // The trap: an adult band of 12+ and a senior band of 65+ both have no
    // upper bound, so they overlap for everyone over 65 — which is exactly the
    // band an operator adds later without thinking.
    inBandTenant(function (): void {
        expect(fn () => app(SaveAgeBands::class)(bandProduct(), [
            bandInput(['min_age' => 12, 'max_age' => null]),
            bandInput(['code' => 'senior', 'label' => ['el' => 'Άνω των 65', 'en' => 'Over 65'], 'min_age' => 65, 'max_age' => null, 'is_base' => false, 'price_multiplier_bp' => 8000]),
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('accepts adjacent ranges that touch without overlapping', function (): void {
    // The passing counterpart. 0–2 and 3–11 is how every operator writes it,
    // and an off-by-one in the overlap check would refuse the commonest set in
    // the product.
    inBandTenant(function (): void {
        $bands = app(SaveAgeBands::class)(bandProduct(), [
            bandInput(['code' => 'infant', 'label' => ['el' => 'Βρέφος', 'en' => 'Infant'], 'min_age' => 0, 'max_age' => 2, 'counts_toward_capacity' => false, 'is_base' => false, 'price_multiplier_bp' => 0]),
            bandInput(['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 11, 'is_base' => false, 'price_multiplier_bp' => 5000]),
            bandInput(['min_age' => 12]),
        ]);

        expect($bands)->toHaveCount(3);
    });
})->group('fast');

it('gives zero and two base bands different messages', function (): void {
    // Different mistakes with different fixes: one operator forgot to mark the
    // adult, the other marked two. One message for both would be useless to
    // whichever of them read it.
    inBandTenant(function (): void {
        $none = null;
        $two = null;

        try {
            app(SaveAgeBands::class)(bandProduct(), [bandInput(['is_base' => false])]);
        } catch (ValidationException $e) {
            $none = implode(' ', $e->validator->errors()->all());
        }

        try {
            app(SaveAgeBands::class)(bandProduct(), [
                bandInput(),
                bandInput(['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 11, 'price_multiplier_bp' => 5000]),
            ]);
        } catch (ValidationException $e) {
            $two = implode(' ', $e->validator->errors()->all());
        }

        expect($none)->not->toBeNull()
            ->and($two)->not->toBeNull()
            ->and($none)->not->toBe($two);
    });
})->group('fast');

it('refuses a set where no band takes a seat', function (): void {
    // CAT-8: otherwise the product accepts unlimited passengers on a finite boat.
    inBandTenant(function (): void {
        expect(fn () => app(SaveAgeBands::class)(bandProduct(), [
            bandInput(['counts_toward_capacity' => false]),
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('refuses a multiplier band with no multiplier', function (): void {
    inBandTenant(function (): void {
        expect(fn () => app(SaveAgeBands::class)(bandProduct(), [
            bandInput(['price_multiplier_bp' => null]),
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('accepts a fixed-price band with no multiplier, and clears any stale one', function (): void {
    // A `fixed` band gets its price from `rate_plan_prices` (#21). Storing a
    // stale multiplier would resolve silently if the mode were switched back.
    inBandTenant(function (): void {
        $bands = app(SaveAgeBands::class)(bandProduct(), [
            // Capped at 64, because an uncapped adult band and a 65+ senior
            // band overlap for everyone over 65 — which the rule above proves.
            bandInput(['max_age' => 64]),
            bandInput([
                'code' => 'senior',
                'label' => ['el' => 'Άνω των 65', 'en' => 'Over 65'],
                'min_age' => 65, 'max_age' => null,
                'pricing_mode' => AgeBandPricing::Fixed->value,
                // A stale multiplier that must not survive the save.
                'price_multiplier_bp' => 8000,
                'is_base' => false,
            ]),
        ]);

        expect($bands[1]->pricing_mode)->toBe(AgeBandPricing::Fixed)
            ->and($bands[1]->price_multiplier_bp)->toBeNull();
    });
})->group('fast');

it('accepts a band that costs money and takes no seat', function (): void {
    // PRC-7, stated as its own test because it is the assumption people make.
    // **Not counting toward capacity does not imply free** — an infant may cost
    // €10 and occupy no seat, and refusing that forces the operator to lie in
    // one direction or the other.
    inBandTenant(function (): void {
        $bands = app(SaveAgeBands::class)(bandProduct(), [
            bandInput(),
            bandInput([
                'code' => 'infant', 'label' => ['el' => 'Βρέφος', 'en' => 'Infant'],
                'min_age' => 0, 'max_age' => 2,
                'counts_toward_capacity' => false,
                'price_multiplier_bp' => 1500,
                'is_base' => false,
            ]),
        ]);

        expect($bands[1]->counts_toward_capacity)->toBeFalse()
            ->and($bands[1]->price_multiplier_bp)->toBe(1500);
    });
})->group('fast');

it('refuses a band labelled in only one language', function (): void {
    // §1.6. This model has no search observer to enforce it, so the Action
    // does — a band labelled only in Greek is a blank in the English booking
    // form's passenger picker, which is where a guest chooses what to pay.
    inBandTenant(function (): void {
        expect(fn () => app(SaveAgeBands::class)(bandProduct(), [
            bandInput(['label' => ['el' => 'Ενήλικας']]),
        ]))->toThrow(ValidationException::class);
    });
})->group('fast', 'i18n');

it('refuses an empty set rather than inventing an adult band', function (): void {
    // Defaulting would put a price on something nobody chose.
    inBandTenant(function (): void {
        expect(fn () => app(SaveAgeBands::class)(bandProduct(), []))
            ->toThrow(ValidationException::class);
    });
})->group('fast');

it('reports every problem at once rather than one per round trip', function (): void {
    // An operator setting up bands for the first time typically has two
    // problems, and fixing them one submit at a time is how a form earns its
    // reputation.
    inBandTenant(function (): void {
        try {
            app(SaveAgeBands::class)(bandProduct(), [
                bandInput(['is_base' => false, 'counts_toward_capacity' => false, 'price_multiplier_bp' => null]),
            ]);

            expect(false)->toBeTrue('nothing was refused');
        } catch (ValidationException $e) {
            // No base band, no counted band, and a missing multiplier.
            expect($e->validator->errors()->get('age_bands'))->toHaveCount(3);
        }
    });
})->group('fast');

it('replaces the set rather than merging it', function (): void {
    // A band the operator deleted must stop resolving passengers. Safe because
    // a booking holds the band snapshot in `pax_breakdown`, not a live join.
    inBandTenant(function (): void {
        $product = bandProduct();

        app(SaveAgeBands::class)($product, [
            bandInput(),
            bandInput(['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 11, 'price_multiplier_bp' => 5000, 'is_base' => false]),
        ]);

        app(SaveAgeBands::class)($product, [bandInput()]);

        expect($product->ageBands()->pluck('code')->all())->toBe(['adult']);
    });
})->group('fast');

it('refuses two bands with the same code on one product', function (): void {
    // `age_bands_tenant_product_code_uq`, which is also what makes an importer
    // run idempotent. Surfaced as a sentence rather than a constraint
    // violation, because duplicating a repeater row keeps its code.
    inBandTenant(function (): void {
        expect(fn () => app(SaveAgeBands::class)(bandProduct(), [
            bandInput(),
            bandInput(['min_age' => 3, 'max_age' => 11, 'is_base' => false]),
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('enforces the code uniqueness at the database too', function (): void {
    inBandTenant(function (): void {
        $product = bandProduct();

        AgeBand::factory()->create(['product_id' => $product->getKey(), 'code' => 'adult']);
        AgeBand::factory()->create(['product_id' => $product->getKey(), 'code' => 'adult']);
    });
})->throws(QueryException::class)->group('fast');

it('lets two products each have an adult band', function (): void {
    // The unique is per product, not per tenant — every product has an adult.
    inBandTenant(function (): void {
        $a = bandProduct();
        $b = bandProduct();

        AgeBand::factory()->create(['product_id' => $a->getKey(), 'code' => 'adult']);
        AgeBand::factory()->create(['product_id' => $b->getKey(), 'code' => 'adult']);

        expect(AgeBand::query()->where('code', 'adult')->count())->toBe(2);
    });
})->group('fast');
