<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Exceptions\ProductModeLocked;
use App\Models\CancellationPolicy;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Rules\FlexibleStartOnlyPerVessel;
use App\Rules\ItineraryStopsShape;
use App\Rules\MaxPaxWithinVesselCapacity;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\Catalog\FakeProductBookingCount;
use Tests\Support\TenantIsolationHarness;

/*
|--------------------------------------------------------------------------
| Products — spec CAT-4, CAT-5, TEN-6, §2.3, §3.5, §3.6
|--------------------------------------------------------------------------
|
| `mode` is the most consequential column in the catalogue: it decides which
| availability service answers, whether departures are generated, whether seats
| are counted or a whole boat is blocked, and whether a price is ever shown.
| Most of what is asserted here is a consequence of it.
|
| The CAT-5 rules are tested one case per rule **plus its passing counterpart**,
| because a rule that refuses everything passes a test that only checks refusal.
|
*/

function forProduct(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, list<object>>  $rules  rule objects, one field per key
 */
function validateProduct(array $data, array $rules): ValidatorContract
{
    return Validator::make($data, $rules);
}

it('creates a product with every column §2.3 names', function (): void {
    forProduct(function (): void {
        $product = Product::factory()->withItinerary()->create();

        expect($product->uuid)->not->toBeNull()
            ->and($product->mode)->toBe(BookingMode::PerSeat)
            ->and($product->category)->toBe(ProductCategory::SharedFullDay)
            ->and($product->status)->toBe(ProductStatus::Active)
            ->and($product->images)->toBe([])
            // The accessor returns the *current locale's* stops, which is
            // correct for a translatable attribute — the coordinates live
            // behind their own method because `getTranslations()` hands back
            // `_geo` as though it were a locale.
            ->and($product->itineraryStopsFor('el'))->toHaveCount(2)
            ->and($product->itineraryGeo())->toHaveKey('s1')
            ->and(array_keys($product->itineraryTranslations()))->toBe(['el', 'en']);
    });
})->group('fast');

it('scopes the slug per tenant, so two operators may use the same one', function (): void {
    // TEN-6. Without the tenant in the key, the second Greek operator to sell a
    // "sunset-cruise" is told the name is taken by a fleet they cannot see.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    Tenancy::forTenant($a, fn (): Product => Product::factory()->create(['slug' => 'sunset-cruise']));
    Tenancy::forTenant($b, fn (): Product => Product::factory()->create(['slug' => 'sunset-cruise']));

    expect(Product::query()->withoutGlobalScopes()->where('slug', 'sunset-cruise')->count())->toBe(2);
})->group('fast', 'tenancy');

it('refuses a duplicate slug within one tenant, including a soft-deleted one', function (): void {
    // Trashed rows are included in the unique index, matching #16's vessels
    // decision: `NULL` is distinct from `NULL` in a unique index on both
    // engines, so adding `deleted_at` to the key would stop every *live* row
    // colliding too. A slug is in somebody's permalink; it stays reserved.
    forProduct(function (): void {
        $first = Product::factory()->create(['slug' => 'sunset-cruise']);
        $first->delete();

        Product::factory()->create(['slug' => 'sunset-cruise']);
    });
})->throws(QueryException::class)->group('fast');

it('refuses max_pax above the vessel capacity, naming both numbers', function (): void {
    // CAT-5, and a legal ceiling rather than a preference: a product that
    // oversells the certificate is discovered by the port authority.
    forProduct(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 12, 'name' => 'Οδυσσέας']);

        $validator = validateProduct(
            ['max_pax' => 20],
            ['max_pax' => [new MaxPaxWithinVesselCapacity($vessel->getKey())]],
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('max_pax'))
            ->toContain('20')->toContain('12')->toContain('Οδυσσέας');
    });
})->group('fast');

it('accepts max_pax at exactly the vessel capacity', function (): void {
    // The passing counterpart. A rule that refused the boundary would stop an
    // operator selling their boat full, which is what they mostly want to do.
    forProduct(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 12]);

        expect(validateProduct(
            ['max_pax' => 12],
            ['max_pax' => [new MaxPaxWithinVesselCapacity($vessel->getKey())]],
        )->fails())->toBeFalse();
    });
})->group('fast');

it('refuses flexible_start on a per-seat or quote product', function (BookingMode $mode): void {
    // CAT-5. A shared departure has one start time by definition — a guest
    // choosing their own would be a different departure, which the availability
    // engine cannot express.
    $validator = validateProduct(
        ['flexible_start' => true],
        ['flexible_start' => [new FlexibleStartOnlyPerVessel($mode)]],
    );

    expect($validator->fails())->toBeTrue();
})->with([[BookingMode::PerSeat], [BookingMode::Quote]])->group('fast');

it('allows flexible_start on a per-vessel product', function (): void {
    expect(validateProduct(
        ['flexible_start' => true],
        ['flexible_start' => [new FlexibleStartOnlyPerVessel(BookingMode::PerVessel)]],
    )->fails())->toBeFalse();
})->group('fast');

it('is not fooled by an absent flexible_start, because the rule is implicit', function (): void {
    // Laravel skips a non-implicit rule on a falsy value — the exact bug #16
    // found in TranslatableRequired. Here it would mean `false` and "not
    // submitted" behaving differently, which is the sort of gap that only
    // surfaces through the API.
    expect((new FlexibleStartOnlyPerVessel(BookingMode::PerSeat))->implicit)->toBeTrue()
        ->and(validateProduct(
            ['flexible_start' => false],
            ['flexible_start' => [new FlexibleStartOnlyPerVessel(BookingMode::PerSeat)]],
        )->fails())->toBeFalse();
})->group('fast');

it('clears min_pax and the flexible window when the mode does not use them', function (): void {
    // CAT-5 says they only apply to certain modes. Cleared rather than refused:
    // an operator switching a draft should not hunt for a field that stopped
    // applying, and a stale value would silently gate departures if they
    // switched back.
    forProduct(function (): void {
        $product = Product::factory()->flexibleStart()->create(['min_pax' => 0]);

        $saved = app(SaveProduct::class)($product, [
            'mode' => BookingMode::PerSeat,
            'min_pax' => 4,
        ]);

        expect($saved->flexible_start)->toBeFalse()
            ->and($saved->earliest_start_time)->toBeNull()
            ->and($saved->latest_start_time)->toBeNull()
            // per_seat *does* use min_pax, so this one survives.
            ->and($saved->min_pax)->toBe(4);

        $charter = app(SaveProduct::class)($saved, ['mode' => BookingMode::PerVessel]);

        expect($charter->min_pax)->toBe(0);
    });
})->group('fast');

it('refuses a mode change once the product has bookings', function (): void {
    // §2.3: a per_seat product that became per_vessel would invalidate every
    // departure and change what every price snapshot meant. Proven through a
    // fake source, because `bookings` does not exist until M2.
    forProduct(function (): void {
        $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

        $action = new SaveProduct([new FakeProductBookingCount(3)]);

        expect(fn () => $action($product, ['mode' => BookingMode::PerVessel]))
            ->toThrow(ProductModeLocked::class);
    });
})->group('fast');

it('allows a mode change while nothing has been booked', function (): void {
    // The passing counterpart, and the ordinary case: a draft being shaped.
    forProduct(function (): void {
        $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

        $saved = app(SaveProduct::class)($product, ['mode' => BookingMode::PerVessel]);

        expect($saved->mode)->toBe(BookingMode::PerVessel);
    });
})->group('fast');

it('names the trip and both modes in the refusal, in Greek', function (): void {
    // CNV-11: the sentence an operator reads comes from a lang file, and it has
    // to say enough to act on — which product, which change, how many bookings.
    app()->setLocale('el');

    forProduct(function (): void {
        $product = Product::factory()->create([
            'mode' => BookingMode::PerSeat,
            'title' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset'],
        ]);

        $message = ProductModeLocked::message($product, BookingMode::PerVessel, 3);

        expect($message)->toContain('Ηλιοβασίλεμα')
            ->and($message)->toContain('3')
            ->and($message)->not->toContain('catalog.product');
    });
})->group('fast', 'i18n');

it('ships the booking-count guard with no sources registered', function (): void {
    // The #16 shape: the guard exists, refuses nothing, and is proven. M2 adds
    // one class and one tag() line. Asserted so that a future implementation
    // being registered is a visible change rather than a silent one.
    forProduct(function (): void {
        $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

        expect(app(SaveProduct::class)($product, ['mode' => BookingMode::Quote])->mode)
            ->toBe(BookingMode::Quote);
    });
})->group('fast');

it('hands back _geo from getTranslations as though it were a locale', function (): void {
    // A trap worth pinning. §3.6 says the leading underscore means the package
    // "ignores" the sidecar — the *accessor* does, but `getTranslations()` does
    // not, so anything walking that array sees a pseudo-locale whose value is a
    // map of coordinates. `itineraryTranslations()` exists so nothing has to
    // rediscover this.
    forProduct(function (): void {
        $product = Product::factory()->withItinerary()->create();

        expect(array_keys($product->getTranslations('itinerary_stops')))->toContain('_geo')
            ->and(array_keys($product->itineraryTranslations()))->not->toContain('_geo');
    });
})->group('fast');

it('accepts the §3.6 itinerary shape', function (): void {
    forProduct(function (): void {
        $product = Product::factory()->withItinerary()->create();

        expect(validateProduct(
            ['itinerary_stops' => $product->getTranslations('itinerary_stops')],
            ['itinerary_stops' => [new ItineraryStopsShape]],
        )->fails())->toBeFalse();
    });
})->group('fast');

it('refuses an itinerary whose stops differ between languages', function (): void {
    // §3.6's one invariant that fails invisibly: a stop added in Greek and
    // forgotten in English is a gap in the English itinerary, and its `_geo`
    // pin points at a stop half the guests never see.
    $validator = validateProduct(['itinerary_stops' => [
        'el' => [
            ['key' => 's1', 'name' => 'Ζέα'],
            ['key' => 's2', 'name' => 'Βλυχάδα'],
        ],
        'en' => [
            ['key' => 's1', 'name' => 'Zea'],
        ],
    ]], ['itinerary_stops' => [new ItineraryStopsShape]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('itinerary_stops'))->toContain('s2');
})->group('fast');

it('refuses coordinates for a stop that does not exist', function (): void {
    $validator = validateProduct(['itinerary_stops' => [
        'el' => [['key' => 's1', 'name' => 'Ζέα']],
        'en' => [['key' => 's1', 'name' => 'Zea']],
        '_geo' => ['s1' => ['lat' => 37.9, 'lng' => 23.6], 's9' => ['lat' => 1, 'lng' => 1]],
    ]], ['itinerary_stops' => [new ItineraryStopsShape]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('itinerary_stops'))->toContain('s9');
})->group('fast');

it('refuses a stop with no name, and a description that is too long', function (): void {
    foreach ([
        [['key' => 's1']],
        [['key' => 's1', 'name' => 'Ζέα', 'description' => str_repeat('α', 501)]],
    ] as $stops) {
        $validator = validateProduct(
            ['itinerary_stops' => ['el' => $stops, 'en' => $stops]],
            ['itinerary_stops' => [new ItineraryStopsShape]],
        );

        expect($validator->fails())->toBeTrue();
    }
})->group('fast');

it('treats a null itinerary as not configured rather than invalid', function (): void {
    // §3.5, §3.6: null means "not configured" and hides the section, which is
    // an ordinary state for a trip that is a boat and a beach.
    expect(validateProduct(
        ['itinerary_stops' => null],
        ['itinerary_stops' => [new ItineraryStopsShape]],
    )->fails())->toBeFalse();
})->group('fast');

it('refuses a malformed itinerary through the Action too, for the importer', function (): void {
    // An importer has no form to attach a rule to, so the Action re-checks.
    forProduct(function (): void {
        $product = Product::factory()->create();

        expect(fn () => app(SaveProduct::class)($product, [
            'itinerary_stops' => ['el' => [['key' => 's1', 'name' => 'Ζέα']], 'en' => []],
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('falls back to the tenant default cancellation policy when it has none', function (): void {
    // §2.3: null means the tenant default, which #23 guarantees always exists.
    forProduct(function (): void {
        $default = CancellationPolicy::factory()->default()->create();
        $product = Product::factory()->create(['cancellation_policy_id' => null]);

        expect($product->effectiveCancellationPolicy()?->getKey())->toBe($default->getKey());
    });
})->group('fast');

it('uses its own cancellation policy when it has one', function (): void {
    forProduct(function (): void {
        CancellationPolicy::factory()->default()->create();
        $strict = CancellationPolicy::factory()->create(['name' => ['el' => 'Αυστηρή', 'en' => 'Strict']]);

        $product = Product::factory()->create(['cancellation_policy_id' => $strict->getKey()]);

        expect($product->effectiveCancellationPolicy()?->getKey())->toBe($strict->getKey());
    });
})->group('fast');

it('builds the search index from the title and summary in both locales', function (): void {
    // #15's observer, adopted by declaring the attribute lists and nothing else.
    forProduct(function (): void {
        $product = Product::factory()->create([
            'title' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset cruise'],
            'summary' => ['el' => 'Στον Σαρωνικό', 'en' => 'In the Saronic'],
        ]);

        $index = (string) $product->fresh()->getAttribute('search_index');

        expect($index)->toContain('sunset')
            // Accent-folded, so an operator typing without tonos still finds it.
            ->and($index)->toContain('ηλιοβασιλεμα');
    });
})->group('fast', 'i18n');

it('is discovered by the cross-tenant isolation gate automatically', function (): void {
    // The criterion is that no hand-written isolation test is needed.
    expect(TenantIsolationHarness::tenantOwnedModels())->toContain(Product::class);
})->group('fast', 'tenancy');
