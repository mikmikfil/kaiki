<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\Pages\ListProducts;
use App\Filament\App\Resources\ProductResource\RelationManagers\RatePlansRelationManager;
use App\Filament\App\Widgets\UnsellableProducts;
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
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
 * What «Νέα εκδρομή» asks: the «Βασικά» tab and nothing else (Mike, 25/9).
 *
 * **The capacity is stated, not left to the factory.** `capacity_max` defaults
 * to a random 8–90, and the create page copies it into «Μέγιστα άτομα» — a
 * number a rule is checked against does not belong to the dice.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productBasics(array $overrides = []): array
{
    return array_merge([
        'title' => ['el' => 'Ημερήσια κρουαζιέρα', 'en' => 'Full-day cruise'],
        'slug' => 'full-day-cruise',
        'category' => ProductCategory::SharedFullDay->value,
        'mode' => BookingMode::PerSeat->value,
        'vessel_id' => Vessel::factory()->create(['capacity_max' => 12])->getKey(),
    ], $overrides);
}

/**
 * One adult band, as the edit page's repeater holds it.
 *
 * @return list<array<string, mixed>>
 */
function productAdultBand(): array
{
    return [
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
    ];
}

/**
 * A draft as «Συνέχεια» leaves it: saved, on its boat, with a band to price —
 * everything past «Βασικά» still to be answered on the edit page.
 *
 * A draft, because re-saving an **active** trip runs the CAT-15 gate, which
 * would refuse these edits for reasons that have nothing to do with them.
 *
 * @param  array<string, mixed>  $attributes
 */
function productDraftFor(User $owner, array $attributes = []): Product
{
    return Tenancy::forTenant(productTenantOf($owner), function () use ($attributes): Product {
        $product = Product::factory()->create([
            'mode' => BookingMode::PerSeat,
            'status' => ProductStatus::Draft,
            'vessel_id' => Vessel::factory()->create(['capacity_max' => 12])->getKey(),
            'max_pax' => 12,
            ...$attributes,
        ]);

        // A per-seat trip saves only with a band set that holds together
        // (CAT-8), and the page saves the whole form.
        if ($product->mode === BookingMode::PerSeat) {
            AgeBand::factory()->create(['product_id' => $product->getKey()]);
        }

        return $product;
    });
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

it('refuses a trip that carries more people than its boat is certified for', function (): void {
    // Mike, 2026-09-23. CAT-5 and `MaxPaxWithinVesselCapacity` have existed
    // since M1, with their own tests — but **no form ever attached the rule**,
    // so the refusal was written, proven against the rule object, and never
    // fired at an operator. `capacity_max` is a legal ceiling: a trip that
    // oversells it gets discovered by the port authority, not by a test.
    //
    // Asked on the edit page's «Πότε φεύγει», which is where «Μέγιστα άτομα»
    // is typed since creating and editing became one form (Mike, 25/9).
    $owner = OperatorUser::withRole(Role::Owner);

    $product = productDraftFor($owner, [
        'vessel_id' => Tenancy::forTenant(productTenantOf($owner), fn (): int => (int) Vessel::factory()->create(['name' => 'Μικρή', 'capacity_max' => 8])->getKey()),
        'max_pax' => 8,
    ]);

    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['max_pax' => 12])
        ->call('save')
        ->assertHasFormErrors(['max_pax']);

    // The passing counterpart, because a rule that refuses everything passes a
    // test that only checks refusal — and the boundary is inclusive.
    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['max_pax' => 8])
        ->call('save')
        ->assertHasNoFormErrors();
})->group('fast');

it('names each age band in its own header', function (): void {
    // Mike, 2026-09-23. Collapsed, every band said «Κατηγορίες» — to find the
    // child's you opened all of them. Rule Δ of the form mockup (2026-09-24)
    // made the header the band's whole summary.
    //
    // The schedules the wizard named the same way are the «Δρομολόγια» list
    // now, one row per rule, and say their days in a column of their own
    // (`TripScheduleTabTest`).
    $owner = OperatorUser::withRole(Role::Owner);

    $product = productDraftFor($owner);

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);
    });

    $page = productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()]);

    // Asserted in English because that is the locale a panel test runs in;
    // the summary is built from lang keys, so a Greek panel is the same path.
    foreach ((array) $page->get('data.age_bands') as $band) {
        $page->assertSee((string) ProductResource::bandSummary($band));
    }

    expect(ProductResource::bandSummary(array_values((array) $page->get('data.age_bands'))[1]))
        ->toStartWith('Child · ');
})->group('fast');

it('makes a draft from «Βασικά» alone, with the boat\'s certificate standing in for «Μέγιστα άτομα»', function (): void {
    // Mike, 25/9: *«Ας τα κάνουμε ίδια τα στοιχεία που ζητούνται, τη δομή.»*
    // The create page asks what a trip cannot be saved without and makes a
    // draft; the columns it did not ask for get the defaults the wizard used to
    // offer, all of them on the next tabs to change. A trip started today and
    // finished tomorrow is how most first trips get made.
    $owner = OperatorUser::withRole(Role::Owner);

    $page = productPageAs($owner, CreateProduct::class);

    $page->fillForm(productBasics())
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Tenancy::forTenant(productTenantOf($owner), fn (): Product => Product::query()->sole());

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->getTranslation('title', 'el'))->toBe('Ημερήσια κρουαζιέρα')
        ->and($product->getTranslation('title', 'en'))->toBe('Full-day cruise')
        ->and($product->max_pax)->toBe(12)
        ->and($product->duration_minutes)->toBe(180)
        ->and($product->check_in_offset_minutes)->toBe(30)
        ->and($product->min_pax)->toBe(0)
        ->and($product->min_booking_pax)->toBe(1)
        // Asked on «Πότε φεύγει», and the publish checklist says so.
        ->and($product->meeting_point_id)->toBeNull();

    // …and straight on to the tab after «Βασικά», on the trip's own page.
    $page->assertRedirect(
        ProductResource::getUrl('edit', ['record' => $product]) . '?tab=' . ProductResource::tabQueryKey('when'),
    );
})->group('fast');

it('starts a new trip with Ενήλικας, Παιδί and Βρέφος, without asking for codes', function (): void {
    // So «Τιμές» opens on rows to price rather than on an empty list.
    $owner = OperatorUser::withRole(Role::Owner);

    productPageAs($owner, CreateProduct::class)
        ->fillForm(productBasics())
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $bands = Product::query()->sole()->ageBands()->orderBy('sort_order')->get();

        expect($bands->pluck('code')->all())->toBe(['adult', 'child', 'infant'])
            ->and($bands[0]->is_base)->toBeTrue()
            ->and($bands[2]->counts_toward_capacity)->toBeFalse();
    });
})->group('fast');

it('gives a whole-boat trip no age bands of its own', function (): void {
    // A charter sells the boat, not seats: the three bands would be a price
    // list for passengers it never counts.
    $owner = OperatorUser::withRole(Role::Owner);

    productPageAs($owner, CreateProduct::class)
        ->fillForm(productBasics(['mode' => BookingMode::PerVessel->value, 'category' => ProductCategory::PrivateFullDay->value]))
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $product = Product::query()->sole();

        expect($product->mode)->toBe(BookingMode::PerVessel)
            ->and($product->ageBands()->count())->toBe(0);
    });
})->group('fast');

it('asks only for «Βασικά» on a new trip', function (): void {
    // Everything else is the edit page's, where it is saved by the edit page's
    // own code — two forms drifted, and the drift cost an operator their
    // prices (25/9).
    $owner = OperatorUser::withRole(Role::Owner);

    $page = productPageAs($owner, CreateProduct::class);

    foreach (['title.el', 'slug', 'vessel_id', 'category', 'mode', 'is_featured'] as $field) {
        $page->assertFormFieldExists($field);
    }

    foreach (['max_pax', 'duration_minutes', 'default_start_time', 'meeting_point_id', 'age_bands', 'cancellation_policy_id', 'summary'] as $field) {
        $page->assertFormFieldDoesNotExist($field);
    }
})->group('fast');

it('asks for a single departure time only on a whole-boat trip', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $perSeat = productDraftFor($owner);
    $charter = productDraftFor($owner, ['mode' => BookingMode::PerVessel, 'category' => ProductCategory::PrivateFullDay]);

    // Per seat: the days and times live on the «Δρομολόγια» list.
    productPageAs($owner, EditProduct::class, ['record' => $perSeat->getRouteKey()])
        ->assertFormFieldIsHidden('default_start_time')
        ->assertFormFieldIsVisible('duration_minutes')
        ->assertFormFieldIsVisible('check_in_offset_minutes');

    productPageAs($owner, EditProduct::class, ['record' => $charter->getRouteKey()])
        ->assertFormFieldIsVisible('default_start_time');
})->group('fast');

it('creates a cancellation policy from the trip form when the operator has none', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = productDraftFor($owner);

    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->callFormComponentAction('cancellation_policy_id', 'createOption', [
            'name' => ['el' => 'Κανονική', 'en' => 'Standard'],
            'free_cancellation_hours' => 24,
            'tiers' => [
                ['days_before' => 7, 'refund_percent' => 100],
                ['days_before' => 2, 'refund_percent' => 50],
            ],
        ])
        ->assertHasNoFormComponentActionErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        $policy = CancellationPolicy::query()->sole();

        expect($product->refresh()->cancellation_policy_id)->toBe($policy->getKey())
            // The first policy becomes the default through the Action.
            ->and($policy->is_default)->toBeTrue()
            ->and($policy->tiers()->count())->toBe(2);
    });
})->group('fast');

it('refuses publishing a trip that is missing its prerequisites, naming each one', function (): void {
    // On the **edit** page, which is where a half-finished trip is published
    // from — and since 25/9 every new trip is one: the create page makes a
    // draft from «Βασικά» and the rest is answered here.
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

it('walks a new trip from «Βασικά» to published, on the one page', function (): void {
    // The promise the wizard made (2026-09-22, direction Α) — answer every step
    // and you have a trip that **sells** — kept by the one form (25/9): make it
    // from «Βασικά», then the edit page's own tabs, its own price table and its
    // own «Δημοσίευση». Nothing typed on the way is held anywhere but where the
    // edit page keeps it.
    $owner = OperatorUser::withRole(Role::Owner);

    [$port, $policy] = Tenancy::forTenant(productTenantOf($owner), fn (): array => [
        Port::factory()->create(),
        CancellationPolicy::factory()->create(['is_default' => true]),
    ]);

    productPageAs($owner, CreateProduct::class)
        ->fillForm(productBasics())
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Tenancy::forTenant(productTenantOf($owner), fn (): Product => Product::query()->sole());

    $page = productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        // «Πότε φεύγει» and «Όροι».
        ->fillForm(['meeting_point_id' => $port->getKey(), 'cancellation_policy_id' => $policy->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    // «Τιμές»: «Όλο τον χρόνο» for each of the three groups the trip started with.
    foreach (array_keys((array) $page->get('priceCells')) as $i => $row) {
        $page->set("priceCells.{$row}." . PriceTable::NEW_DEFAULT, (string) (45 - $i * 15) . ',00');
    }

    $page->call('savePrices')
        ->assertHasNoErrors()
        ->callAction('publish')
        ->assertNotified(__('catalog.product.status_actions.published'));

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        $product->refresh();

        expect($product->status)->toBe(ProductStatus::Active)
            ->and($product->ageBands()->count())->toBe(3)
            // «Όλο τον χρόνο»: the list that applies when no period does.
            ->and($product->ratePlans()->sole()->season_id)->toBeNull()
            ->and($product->ratePlans()->sole()->prices()->max('price_cents'))->toBe(4500);
    });
})->group('fast');

it('prices every group for all year and each ticked period', function (): void {
    // Product owner, 2026-09-24: tick the periods, then a price per group and
    // column, saved through SavePriceTable. Nothing is inherited silently: a
    // ticked period is priced for every group. The wizard asked the same
    // question with a grid of its own (25/9: one form); this is the edit
    // page's table, which is now the only one.
    $owner = OperatorUser::withRole(Role::Owner);

    $product = productDraftFor($owner);

    [$season, $adult, $child] = Tenancy::forTenant(productTenantOf($owner), fn (): array => [
        Season::factory()->create(),
        $product->ageBands()->sole(),
        AgeBand::factory()->child()->create(['product_id' => $product->getKey()]),
    ]);

    $allYear = PriceTable::NEW_DEFAULT;
    $period = PriceTable::seasonKey($season);

    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->call('togglePriceSeason', $season->getKey())
        ->set('priceCells.' . PriceTable::rowKey($adult) . ".{$allYear}", '45,00')
        ->set('priceCells.' . PriceTable::rowKey($adult) . ".{$period}", '55,00')
        ->set('priceCells.' . PriceTable::rowKey($child) . ".{$allYear}", '20,00')
        ->set('priceCells.' . PriceTable::rowKey($child) . ".{$period}", '25,00')
        ->set('priceTerms.deposit_type', 'percent')
        ->set('priceTerms.deposit_percent', 30)
        ->set('priceTerms.min_lead_time_hours', 6)
        ->call('savePrices')
        ->assertHasNoErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product, $season, $adult, $child): void {
        $allYear = $product->ratePlans()->whereNull('season_id')->sole();
        $summer = $product->ratePlans()->where('season_id', $season->getKey())->sole();

        $priceFor = static fn ($plan, $band): ?int => $plan->prices()
            ->where('age_band_id', $band->getKey())
            ->value('price_cents');

        expect($priceFor($allYear, $adult))->toBe(4500)
            ->and($priceFor($allYear, $child))->toBe(2000)
            ->and($priceFor($summer, $adult))->toBe(5500)
            ->and($priceFor($summer, $child))->toBe(2500)
            // The terms once, and the period follows them.
            ->and($summer->deposit_percent)->toBe(30)
            ->and($summer->min_lead_time_hours)->toBe(6)
            ->and($summer->follows_trip_terms)->toBeTrue();
    });
})->group('fast');

it('takes the trip page texts on the «Σελίδα» tab', function (): void {
    // Product owner, 2026-09-22: *«και κείμενα τα πάντα»*. The wizard carried
    // them to `SaveProduct` on the first walk; since 25/9 they are typed on the
    // trip's own «Σελίδα» tab, as the steps a draft walks through. The fields
    // themselves are covered by `TripPageContentFormTest`.
    $owner = OperatorUser::withRole(Role::Owner);

    $product = productDraftFor($owner, ['summary' => null]);

    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'summary' => ['el' => 'Με γεύμα στο σκάφος.', 'en' => 'Lunch on board.'],
            'badge' => ['el' => 'Δημοφιλές', 'en' => 'Popular'],
            'highlights' => [
                'el' => [['value' => 'Τρεις στάσεις για μπάνιο']],
                'en' => [['value' => 'Three swimming stops']],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        $product->refresh();

        expect($product->getTranslation('summary', 'el'))->toBe('Με γεύμα στο σκάφος.')
            ->and($product->getTranslation('badge', 'en'))->toBe('Popular')
            ->and($product->getTranslation('highlights', 'el'))->toBe(['Τρεις στάσεις για μπάνιο']);
    });
})->group('fast');

it('reports a set-level age band error on the form rather than throwing', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = productDraftFor($owner);

    $bands = productAdultBand();
    // No base band: a set-level CAT-8 rule, and one no single row could ever
    // report on its own.
    $bands[0]['is_base'] = false;

    productPageAs($owner, EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['age_bands' => $bands])
        ->call('save')
        ->assertHasFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        // The set that was there is still there.
        expect($product->ageBands()->sole()->is_base)->toBeTrue();
    });
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
        ->fillForm(['age_bands' => productAdultBand()])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($product): void {
        // A band the operator removed must stop resolving passengers.
        expect(Product::query()->findOrFail($product->getKey())->ageBands()->count())->toBe(1);
    });
})->group('fast');

it('takes «up to N people, plus each extra» on a charter, where the operator sells that way', function (): void {
    /*
     * The charter shape of 2026-09-17, and the wizard came to ask for it late:
     * *«have you also considered the price up to N people + per extra
     * person?»* An operator who prices that way finished the guide with half a
     * price list and nothing on screen saying the other half existed.
     *
     * Since 25/9 a charter's prices are the edit page's «Τιμές» list, the one
     * place they were ever saved from, switched on per operator by the
     * platform.
     */
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(productTenantOf($owner), function () use ($owner): void {
        productTenantOf($owner)->forceFill(['extra_person_pricing_enabled' => true])->save();
    });

    $charter = productDraftFor($owner, ['mode' => BookingMode::PerVessel, 'category' => ProductCategory::PrivateFullDay]);

    // The list is on the trip's page…
    productPageAs($owner, EditProduct::class, ['record' => $charter->getRouteKey()])
        ->assertSeeLivewire(RatePlansRelationManager::class);

    // …and takes the three numbers.
    Livewire::actingAs($owner)
        ->test(RatePlansRelationManager::class, ['ownerRecord' => $charter, 'pageClass' => EditProduct::class])
        ->callTableAction('create', data: [
            'vessel_price_cents' => '450,00',
            'included_pax' => 8,
            'extra_pax_price_cents' => '25,00',
            'is_active' => true,
            'deposit_type' => 'none',
        ])
        ->assertHasNoTableActionErrors();

    Tenancy::forTenant(productTenantOf($owner), function () use ($charter): void {
        $plan = $charter->ratePlans()->sole();

        expect($plan->vessel_price_cents)->toBe(45000)
            ->and($plan->included_pax)->toBe(8)
            ->and($plan->extra_pax_price_cents)->toBe(2500);
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

    actingAs($owner);

    expect(UnsellableProducts::canView())->toBeTrue();

    // Crew never see prices (TEN-8), so not this pricing warning either — the
    // stress sweep found it on their home page (2026-09-23).
    $crew = OperatorUser::withRole(Role::Crew, productTenantOf($owner));

    actingAs($crew);

    expect(UnsellableProducts::canView())->toBeFalse();
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
    // on the trip's «Σελίδα» tab rather than while creating it: the create
    // page asks only for «Βασικά» (25/9).
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
    // edit page, which is where the badge is typed: the create page asks only
    // for «Βασικά» (25/9).
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
