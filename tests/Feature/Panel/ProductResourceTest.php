<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\Pages\ListProducts;
use App\Filament\App\Widgets\UnsellableProducts;
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Season;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec CAT-4, CAT-7, CAT-15, SEC-3, TEN-8, I18N-1, CNV-5.
 *
 * The largest form in the panel, and the thinnest: `SaveProduct` and
 * `SaveAgeBands` hold every rule. What is asserted here is the part only the
 * panel does — that the whole band set reaches the Action in one call, that the
 * CAT-15 refusal reaches the operator as a checklist rather than a stack trace,
 * and that crew never get here at all.
 */

function productTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * A mounted page with the tenant resolved, as the panel middleware would.
 *
 * **The record parameter is the `uuid`, not the id.** `HasUuid` makes the uuid
 * the route key so the public API never exposes a sequential id, and Filament
 * resolves a record by the route key — passing the integer finds nothing and
 * reports the row as missing, which reads like a tenancy bug and is not one.
 *
 * @param  array<string, mixed>  $params
 */
function productPageAs(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(productTenantOf($user));

    return Livewire::actingAs($user)->test($page, $params);
}

/**
 * The form state for a per-seat trip with one adult band.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productFormState(array $overrides = []): array
{
    return array_merge([
        'title' => ['el' => 'Ημερήσια κρουαζιέρα', 'en' => 'Full-day cruise'],
        'slug' => 'full-day-cruise',
        'category' => ProductCategory::SharedFullDay->value,
        'mode' => BookingMode::PerSeat->value,
        'status' => ProductStatus::Draft->value,
        // The create page is a guide of steps since 2026-09-22 and asks for
        // both of these before it will make a trip: its promise is a trip that
        // can be published, and neither of them can be filled in later without
        // the publish checklist stopping the operator anyway.
        'vessel_id' => Vessel::factory()->create()->getKey(),
        'meeting_point_id' => Port::factory()->create()->getKey(),
        // …and what the last step asks. Draft here, so the tests that are about
        // creating stay about creating; the two that are about publishing say
        // so themselves.
        'wizard_publish' => 'draft',
        'duration_minutes' => 480,
        'check_in_offset_minutes' => 30,
        'max_pax' => 12,
        'min_pax' => 4,
        'min_booking_pax' => 1,
        'guest_details_deadline_hours' => 48,
        'sort_order' => 0,
        'age_bands' => [
            [
                'code' => 'adult',
                'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'],
                'min_age' => 12,
                'max_age' => null,
                'counts_toward_capacity' => true,
                'pricing_mode' => AgeBandPricing::Multiplier->value,
                'price_multiplier_bp' => 10000,
                'is_base' => true,
                'requires_adult' => false,
            ],
        ],
    ], $overrides);
}

it('lets an owner and a manager reach the products page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/products')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the products page', function (): void {
    // TEN-8: crew are read-only within a departure window. What is sold, to how
    // many people and under which terms is not part of standing on the quay.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/products')->assertForbidden();
})->group('fast');

it('lists only the signed-in operator trips', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $other = Tenant::factory()->create();

    $mine = Tenancy::forTenant(productTenantOf($owner), fn (): Product => Product::factory()->create());
    $theirs = Tenancy::forTenant($other, fn (): Product => Product::factory()->create());

    productPageAs($owner, ListProducts::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
})->group('fast');

it('creates a trip and its age bands in one submit', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    productPageAs($owner, CreateProduct::class)
        ->fillForm(productFormState())
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $product = Product::query()->firstOrFail();

        expect($product->getTranslation('title', 'el'))->toBe('Ημερήσια κρουαζιέρα')
            ->and($product->getTranslation('title', 'en'))->toBe('Full-day cruise')
            ->and($product->ageBands()->count())->toBe(1)
            ->and($product->ageBands()->first()?->is_base)->toBeTrue();
    });
})->group('fast');

it('starts a new trip with Ενήλικας, Παιδί and Βρέφος, without asking for codes', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $page = productPageAs($owner, CreateProduct::class);
    $bands = array_values((array) $page->get('data.age_bands'));

    expect(array_map(static fn (array $band): string => $band['label']['el'], $bands))->toBe(['Ενήλικας', 'Παιδί', 'Βρέφος'])
        ->and($bands[0]['is_base'])->toBeTrue()
        ->and($bands[2]['counts_toward_capacity'])->toBeFalse();

    // Everything but the bands, which stay the three the form started with.
    $state = productFormState();
    unset($state['age_bands']);

    $page->fillForm($state)->call('create');

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        expect(Product::query()->sole()->ageBands()->orderBy('sort_order')->pluck('code')->all())->toBe(['adult', 'child', 'infant']);
    });
})->group('fast');

it('asks for a single departure time only on a whole-boat trip', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    // Per seat: the days and times live on the «Δρομολόγια» tab.
    productPageAs($owner, CreateProduct::class)
        ->fillForm(['mode' => BookingMode::PerSeat->value])
        ->assertFormFieldIsHidden('default_start_time')
        ->assertFormFieldIsVisible('duration_minutes')
        ->assertFormFieldIsVisible('check_in_offset_minutes')
        ->fillForm(['mode' => BookingMode::PerVessel->value])
        ->assertFormFieldIsVisible('default_start_time');
})->group('fast');

it('creates a cancellation policy from the trip form when the operator has none', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    productPageAs($owner, CreateProduct::class)
        ->callFormComponentAction('cancellation_policy_id', 'createOption', [
            'name' => ['el' => 'Κανονική', 'en' => 'Standard'],
            'free_cancellation_hours' => 24,
            'tiers' => [
                ['days_before' => 7, 'refund_percent' => 100],
                ['days_before' => 2, 'refund_percent' => 50],
            ],
        ])
        ->assertHasNoFormComponentActionErrors()
        ->fillForm(productFormState())
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $policy = CancellationPolicy::query()->sole();
        $product = Product::query()->sole();

        expect($product->cancellation_policy_id)->toBe($policy->getKey())
            // The first policy becomes the default through the Action.
            ->and($policy->is_default)->toBeTrue()
            ->and($policy->tiers()->count())->toBe(2);
    });
})->group('fast');

it('refuses publishing a trip that is missing its prerequisites, naming each one', function (): void {
    // On the **edit** page, which is where a half-finished trip is published
    // from. The create page cannot produce this state any more: since the
    // four-step guide (2026-09-22) it asks for the boat and the meeting point
    // before it will make a trip at all.
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(productTenantOf($owner), function (): Product {
        $product = Product::factory()->create(['status' => ProductStatus::Draft, 'vessel_id' => null, 'meeting_point_id' => null]);
        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return $product;
    });

    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('publish')
        ->assertHasActionErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        // The draft is kept. An operator who asked for too much gets the trip
        // they built, not an empty form and a lost afternoon.
        expect($product->refresh()->status)->toBe(ProductStatus::Draft)
            ->and(ProductPublishChecklist::unmet($product))
            ->toContain(ProductPublishChecklist::VESSEL, ProductPublishChecklist::MEETING_POINT);
    });
})->group('fast');

it('publishes a trip once every prerequisite is met', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(productTenantOf($owner), function (): Product {
        $product = Product::factory()->create([
            'vessel_id' => Vessel::factory()->create()->getKey(),
            'meeting_point_id' => Port::factory()->create()->getKey(),
            'cancellation_policy_id' => CancellationPolicy::factory()->create(['is_default' => true])->getKey(),
            'status' => ProductStatus::Draft,
        ]);

        $band = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        RatePlanPrice::factory()->create(['rate_plan_id' => $plan->getKey(), 'age_band_id' => $band->getKey()]);

        return $product;
    });

    productPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->fillForm(['status' => ProductStatus::Active->value])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        expect(Product::query()->findOrFail($product->getKey())->status)->toBe(ProductStatus::Active);
    });
})->group('fast');

it('publishes a brand-new trip, with its prices and its schedule, in one walk', function (): void {
    // The promise the guide makes (2026-09-22, direction Α): answer every step
    // and you have a trip that **sells** — not a draft and a list of what is
    // still missing. The order trap is in here too: the checklist asks whether
    // the trip has bands, and the bands need a product id, so a naive save
    // would refuse to publish on the very submit that creates them.
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        CancellationPolicy::factory()->create(['is_default' => true]);
    });

    $page = productPageAs($owner, CreateProduct::class);

    // Built after the page, because the fixture makes a boat and a port and
    // both of those need the tenant the page just initialised.
    $state = productFormState([
        'wizard_publish' => 'publish',
        'wizard_schedules' => [
            ['days' => [1, 3, 5], 'times' => [['time' => '19:00']], 'valid_from' => null, 'valid_until' => null],
        ],
    ]);

    // The price travels on the band row, the way the step asks for it.
    $state['age_bands'][0]['wizard_price'] = '45,00';

    $page->fillForm($state)
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $product = Product::query()->firstOrFail();
        $plan = $product->ratePlans()->sole();

        expect($product->status)->toBe(ProductStatus::Active)
            ->and($product->ageBands()->count())->toBe(1)
            // «Όλο τον χρόνο»: the list that applies when no period does.
            ->and($plan->season_id)->toBeNull()
            ->and($plan->prices()->value('price_cents'))->toBe(4500)
            // …and a day to sell, which is the part an operator would never
            // think to come back for.
            ->and($product->scheduleRules()->count())->toBe(1)
            ->and(Departure::query()->where('product_id', $product->getKey())->exists())->toBeTrue();
    });
})->group('fast');

it('takes the whole timetable, several times a day, on the first walk', function (): void {
    // Product owner, 2026-09-22: *«και δρομολόγια extra αν υπάρχουν με ημέρες
    // ώρες κλπ»*. One row of checkboxes and one time was a trip that leaves
    // Tuesdays at nine; a summer is two sailings a day plus a weekend one that
    // stops in September.
    //
    // **A rule per time**, sharing the days and the window, which is how the
    // trip's own «Δρομολόγια» tab stores it too — so 18:00 can be paused later
    // without touching the morning.
    $owner = OperatorUser::withRole(Role::Owner);

    $page = productPageAs($owner, CreateProduct::class);

    $page->fillForm(productFormState([
        'wizard_schedules' => [
            ['days' => [1, 2, 3, 4, 5], 'times' => [['time' => '10:00'], ['time' => '18:00']], 'valid_from' => null, 'valid_until' => null],
            ['days' => [6, 7], 'times' => [['time' => '12:00']], 'valid_from' => '2027-06-01', 'valid_until' => '2027-09-30'],
            // Days but no time: not a schedule, and not a reason to refuse the
            // whole submit either.
            ['days' => [1], 'times' => [], 'valid_from' => null, 'valid_until' => null],
        ],
    ]))
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $rules = Product::query()->firstOrFail()->scheduleRules()->orderBy('start_time')->get();

        expect($rules)->toHaveCount(3)
            // Monday–Friday is 0b0011111 = 31, Saturday and Sunday 0b1100000 = 96.
            ->and($rules->pluck('weekday_mask')->all())->toBe([31, 96, 31])
            ->and($rules->map(static fn ($rule): string => substr((string) $rule->start_time, 0, 5))->all())
            ->toBe(['10:00', '12:00', '18:00'])
            // The window belongs to the row, not to the trip.
            ->and($rules[1]->valid_from->toDateString())->toBe('2027-06-01')
            ->and($rules[1]->valid_until?->toDateString())->toBe('2027-09-30')
            ->and($rules[0]->valid_until)->toBeNull();
    });
})->group('fast');

it('takes a period price beside the band, and fills the rest of the list in', function (): void {
    // Product owner, 2026-09-22: *«και τιμές περίοδοι κλπ»*. The question is
    // asked per band — *«ο ενήλικας 45, το καλοκαίρι 55»* — and gathered back
    // into one list per period on save.
    //
    // The band the operator said nothing about keeps its all-year price, which
    // is both what they meant and what PRC-4 needs: a list that prices some of
    // the bands is refused.
    $owner = OperatorUser::withRole(Role::Owner);

    $season = Tenancy::forTenant(productTenantOf($owner), fn (): Season => Season::factory()->create());

    $page = productPageAs($owner, CreateProduct::class);

    $state = productFormState();
    $state['age_bands'][] = [
        'code' => 'child',
        'label' => ['el' => 'Παιδί', 'en' => 'Child'],
        'min_age' => 3,
        'max_age' => 11,
        'counts_toward_capacity' => true,
        'pricing_mode' => AgeBandPricing::Fixed->value,
        'price_multiplier_bp' => null,
        'is_base' => false,
        'requires_adult' => true,
    ];

    $state['age_bands'][0]['wizard_price'] = '45,00';
    $state['age_bands'][0]['wizard_season_prices'] = [
        ['season_id' => $season->getKey(), 'price' => '55,00'],
    ];
    // The child is priced all year and never for the period.
    $state['age_bands'][1]['wizard_price'] = '20,00';

    $page->fillForm($state)->call('create')->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($season): void {
        $product = Product::query()->firstOrFail();
        $bands = $product->ageBands()->orderBy('sort_order')->get();

        $allYear = $product->ratePlans()->whereNull('season_id')->sole();
        $summer = $product->ratePlans()->where('season_id', $season->getKey())->sole();

        $priceFor = static fn ($plan, $band): ?int => $plan->prices()
            ->where('age_band_id', $band->getKey())
            ->value('price_cents');

        expect($priceFor($allYear, $bands[0]))->toBe(4500)
            ->and($priceFor($allYear, $bands[1]))->toBe(2000)
            ->and($priceFor($summer, $bands[0]))->toBe(5500)
            // Inherited, not missing.
            ->and($priceFor($summer, $bands[1]))->toBe(2000);
    });
})->group('fast');

it('takes the trip page texts on the first walk', function (): void {
    // Product owner, 2026-09-22: *«και κείμενα τα πάντα»*. The step is
    // `ProductResource::pageSections()` verbatim, so this is about the wizard
    // carrying them to `SaveProduct` — the fields themselves are covered by
    // `TripPageContentFormTest`.
    $owner = OperatorUser::withRole(Role::Owner);

    productPageAs($owner, CreateProduct::class)
        ->fillForm(productFormState([
            'summary' => ['el' => 'Με γεύμα στο σκάφος.', 'en' => 'Lunch on board.'],
            'badge' => ['el' => 'Δημοφιλές', 'en' => 'Popular'],
            'highlights' => [
                'el' => [['value' => 'Τρεις στάσεις για μπάνιο']],
                'en' => [['value' => 'Three swimming stops']],
            ],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $product = Product::query()->firstOrFail();

        expect($product->getTranslation('summary', 'el'))->toBe('Με γεύμα στο σκάφος.')
            ->and($product->getTranslation('badge', 'en'))->toBe('Popular')
            ->and($product->getTranslation('highlights', 'el'))->toBe(['Τρεις στάσεις για μπάνιο']);
    });
})->group('fast');

it('reports a set-level age band error on the form rather than throwing', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    productPageAs($owner, CreateProduct::class)
        ->fillForm(productFormState([
            'age_bands' => [
                [
                    'code' => 'adult',
                    'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'],
                    'min_age' => 12,
                    'max_age' => null,
                    'counts_toward_capacity' => true,
                    'pricing_mode' => AgeBandPricing::Multiplier->value,
                    'price_multiplier_bp' => 10000,
                    // No base band: a set-level CAT-8 rule, and one no single
                    // row could ever report on its own.
                    'is_base' => false,
                    'requires_adult' => false,
                ],
            ],
        ]))
        ->call('create')
        ->assertHasFormErrors();
})->group('fast');

it('rewrites the band set rather than merging into it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(productTenantOf($owner), function (): Product {
        // A draft: the factory's default is `active`, and re-saving an active
        // product runs the CAT-15 gate, which would refuse this edit for
        // reasons that have nothing to do with age bands.
        $product = Product::factory()->create(['status' => ProductStatus::Draft]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

        return $product;
    });

    productPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->fillForm(['age_bands' => productFormState()['age_bands']])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        // A band the operator removed must stop resolving passengers.
        expect(Product::query()->findOrFail($product->getKey())->ageBands()->count())->toBe(1);
    });
})->group('fast');

it('uploads the gallery in one field, first photograph first', function (): void {
    /*
     * Product owner, 2026-09-22, after trying the first cut: *«ανεβάζουμε όλο
     * το gallery και η πρώτη γίνεται featured»* — a gallery arrives as a folder
     * of a dozen files, and «add a row, choose a file» a dozen times is not how
     * anybody uploads one.
     *
     * The column keeps `{path, alt}` (§3.15) and the uploader edits a list of
     * paths, so what is asserted is the mapping in both directions — including
     * that a photograph's alt text survives the operator adding another one.
     * Nothing on screen would show that loss: a screen reader is the only thing
     * that reads it.
     */
    $owner = OperatorUser::withRole(Role::Owner);

    // The uploader drops a path whose file is gone, which is the right thing on
    // a real panel and would empty this fixture.
    $disk = Storage::fake((string) config('kaiki.catalog.uploads.disk'));

    foreach (['sunset', 'deck', 'bay'] as $name) {
        $disk->put("products/1/{$name}.jpg", 'not really a jpeg');
    }

    $product = Tenancy::forTenant(productTenantOf($owner), function (): Product {
        $product = Product::factory()->create([
            'status' => ProductStatus::Draft,
            'images' => [
                ['path' => 'products/1/sunset.jpg', 'alt' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset']],
                ['path' => 'products/1/deck.jpg', 'alt' => ['el' => 'Κατάστρωμα', 'en' => 'Deck']],
            ],
        ]);

        // The form saves the whole trip, and a per-seat trip with no bands is
        // refused by CAT-8 for reasons that have nothing to do with photographs.
        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return $product;
    });

    $page = productPageAs($owner, EditProduct::class, ['record' => $product->uuid]);

    // The uploader holds paths, in the stored order.
    expect(array_values((array) $page->get('data.gallery')))
        ->toBe(['products/1/sunset.jpg', 'products/1/deck.jpg']);

    // The operator drags the deck shot to the front and adds a third.
    $page->set('data.gallery', [
        'a' => 'products/1/deck.jpg',
        'b' => 'products/1/bay.jpg',
        'c' => 'products/1/sunset.jpg',
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $images = Product::query()->sole()->images;

        expect(array_column($images, 'path'))
            // The order is the only thing that says which one is featured.
            ->toBe(['products/1/deck.jpg', 'products/1/bay.jpg', 'products/1/sunset.jpg'])
            ->and($images[0]['alt'])->toBe(['el' => 'Κατάστρωμα', 'en' => 'Deck'])
            // The new one has none yet, and did not invent one.
            ->and($images[1])->toBe(['path' => 'products/1/bay.jpg'])
            ->and($images[2]['alt']['el'])->toBe('Ηλιοβασίλεμα');
    });
})->group('fast');

it('empties the gallery when the last photograph is removed', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(productTenantOf($owner), function (): Product {
        $product = Product::factory()->create([
            'status' => ProductStatus::Draft,
            'images' => [['path' => 'products/1/sunset.jpg']],
        ]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return $product;
    });

    productPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->set('data.gallery', [])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        expect(Product::query()->sole()->images)->toBe([]);
    });
})->group('fast');

it('loads the existing bands into the repeater for editing', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(productTenantOf($owner), function (): Product {
        $product = Product::factory()->create();

        AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

        return $product;
    });

    $page = productPageAs($owner, EditProduct::class, ['record' => $product->uuid]);

    $page->assertSuccessful();

    /** @var array<string, array<string, mixed>> $rows */
    $rows = $page->get('data.age_bands');
    $codes = array_values(array_map(static fn (array $row): mixed => $row['code'] ?? null, $rows));

    // In the operator's own order, which is `sort_order` and not age.
    expect($codes)->toBe(['adult', 'child']);
})->group('fast');

it('shows a from price, and nothing at all when there is none', function (): void {
    // PRC-5 and §1.9 together: a `quote` product and a product with no
    // resolvable plan both have a null `price_from_cents`, and the list must
    // render nothing rather than "€0.00" — which on a public page would be a
    // free trip.
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $priced = Product::factory()->create(['status' => ProductStatus::Draft]);
        $base = AgeBand::factory()->create(['product_id' => $priced->getKey()]);
        $plan = RatePlan::factory()->create(['product_id' => $priced->getKey()]);
        $plan->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 4500]);

        Product::factory()->create([
            'mode' => BookingMode::Quote,
            'status' => ProductStatus::Draft,
        ]);
    });

    productPageAs($owner, ListProducts::class)
        ->assertSuccessful()
        ->assertSee('45.00')
        ->assertDontSee('0.00');
})->group('fast');

it('warns the operator about a published trip that cannot be priced', function (): void {
    // PRC-5 asks for a panel warning **rather than** a guest-facing error. The
    // guest side is silence, which is right for a tourist and useless for the
    // operator — who would otherwise hear about it in a phone call.
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $product = Product::factory()->create([
            'status' => ProductStatus::Active,
            'vessel_id' => Vessel::factory()->create()->getKey(),
            'meeting_point_id' => Port::factory()->create()->getKey(),
        ]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);
    });

    tenancy()->initialize(productTenantOf($owner));

    expect(UnsellableProducts::canView())->toBeTrue();
})->group('fast');

it('stops warning once the trip has a plan', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $product = Product::factory()->create(['status' => ProductStatus::Active]);
        $base = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 4500]);
    });

    tenancy()->initialize(productTenantOf($owner));

    expect(UnsellableProducts::canView())->toBeFalse();
})->group('fast');

it('saves a badge in both languages, and leaves a trip without one alone', function (): void {
    // The pill on the card's photograph (2026-09-16). Optional, and asked for
    // on the trip's «Σελίδα» tab rather than while creating it: the four-step
    // guide asks only for what a trip cannot sell without (2026-09-22).
    $owner = OperatorUser::withRole(Role::Owner);

    [$labelled, $plain] = Tenancy::forTenant(productTenantOf($owner), function (): array {
        // Drafts: saving an **active** trip re-runs the publish checklist, and
        // these two have no prices — the refusal would be about the checklist
        // rather than about the badge this test is for.
        $trips = [
            Product::factory()->create(['slug' => 'full-day-cruise', 'status' => ProductStatus::Draft]),
            Product::factory()->create(['slug' => 'no-label', 'status' => ProductStatus::Draft]),
        ];

        // A per-seat trip saves only with a band set that holds together
        // (CAT-8), and the page saves the whole form.
        foreach ($trips as $trip) {
            AgeBand::factory()->create(['product_id' => $trip->getKey()]);
        }

        return $trips;
    });

    productPageAs($owner, EditProduct::class, ['record' => $labelled->getRouteKey()])
        ->fillForm(['badge' => ['el' => 'Δημοφιλές', 'en' => 'Popular']])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($labelled, $plain): void {
        expect($labelled->refresh()->getTranslation('badge', 'el'))->toBe('Δημοφιλές')
            ->and($labelled->getTranslation('badge', 'en'))->toBe('Popular')
            ->and($plain->refresh()->getTranslation('badge', 'el', false))->toBeNull();
    });
})->group('fast');

it('refuses a badge longer than twenty-four characters', function (): void {
    // Past two words the pill covers the photograph it sits on. Asserted on the
    // edit page, which is where the badge is typed since the create page became
    // a four-step guide (2026-09-22).
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(productTenantOf($owner), function (): Product {
        $product = Product::factory()->create(['status' => ProductStatus::Draft]);
        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return $product;
    });

    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['badge' => ['el' => str_repeat('α', 25), 'en' => 'Popular']])
        ->call('save')
        ->assertHasFormErrors(['badge.el' => 'max']);

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        expect($product->refresh()->getTranslation('badge', 'el', false))->toBeNull();
    });
})->group('fast');
