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
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
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

it('refuses publishing a trip that is missing its prerequisites, naming each one', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    // No vessel, no meeting point, no rate plan — three of the six.
    productPageAs($owner, CreateProduct::class)
        ->fillForm(productFormState(['status' => ProductStatus::Active->value]))
        ->call('create')
        ->assertHasFormErrors(['status']);

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        // The draft is kept. An operator who asked for too much gets the trip
        // they built, not an empty form and a lost afternoon.
        $product = Product::query()->firstOrFail();

        expect($product->status)->toBe(ProductStatus::Draft)
            ->and($product->ageBands()->count())->toBe(1);
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

        AgeBand::factory()->create(['product_id' => $product->getKey()]);
        RatePlan::factory()->create(['product_id' => $product->getKey()]);

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

it('publishes a brand-new trip whose bands are created in the same submit', function (): void {
    // The order trap: the checklist asks whether the product has bands, and the
    // bands need a product id. Saved naively, publishing on creation would be
    // refused for having no bands on the very submit that creates them.
    $owner = OperatorUser::withRole(Role::Owner);

    [$vesselId, $portId] = Tenancy::forTenant(productTenantOf($owner), function (): array {
        CancellationPolicy::factory()->create(['is_default' => true]);

        return [Vessel::factory()->create()->getKey(), Port::factory()->create()->getKey()];
    });

    productPageAs($owner, CreateProduct::class)
        ->fillForm(productFormState([
            'vessel_id' => $vesselId,
            'meeting_point_id' => $portId,
            'status' => ProductStatus::Active->value,
        ]))
        ->call('create')
        // Still refused, but for the *rate plan* alone — the bands landed.
        ->assertHasFormErrors(['status']);

    Tenancy::forTenant(productTenantOf($owner), function (): void {
        $product = Product::query()->firstOrFail();

        expect($product->ageBands()->count())->toBe(1)
            ->and(ProductPublishChecklist::unmet($product))
            ->toBe([ProductPublishChecklist::RATE_PLAN]);
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
