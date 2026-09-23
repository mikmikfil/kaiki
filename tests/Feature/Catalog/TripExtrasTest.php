<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\OfferedExtrasResolver;
use App\Domain\Hosted\Actions\BuildProductPage;
use App\Domain\Pricing\Support\ExtraLineBuilder;
use App\Enums\ExtraPricing;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\RelationManagers\ExtrasRelationManager;
use App\Http\Resources\Api\V1\ProductDetailResource;
use App\Models\Extra;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\VatRate;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Πρόσθετα» on the trip, free or paid (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| A free extra is an amenity: listed with what the trip includes, never sold
| and never on a bill. A paid one is chosen at booking, and a required one is
| on every booking whether or not the client sent it.
|
*/

it('gives a meal on board its own VAT, and leaves the rest on the trip\'s', function (): void {
    // Mike, 2026-09-23: *«ένα trip με πλοίο και ένα γεύμα πάνω στο πλοίο δηλαδή
    // έχουν ξεχωριστό vat?»* — yes, and `extras.vat_rate_id` existed for exactly
    // that from the first migration («a cruise is passenger transport and a
    // barbecue extra is catering», data-model §2.3). No form ever asked for it,
    // so the column could only ever be null.
    //
    // Both halves are asserted: the extra that overrides, and the one that does
    // not. Null is not a missing answer here — it means «whatever the trip
    // says», and an extra that silently inherited a *copy* would stop following
    // the trip the day the trip's own rate changed.
    $owner = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    $product = Product::factory()->create();
    $catering = VatRate::factory()->create();

    $manager = Livewire::actingAs($owner)
        ->test(ExtrasRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);

    $manager->callTableAction('create', data: [
        'name' => ['el' => 'Γεύμα στο σκάφος', 'en' => 'Lunch on board'],
        'kind' => 'paid',
        'pricing_type' => ExtraPricing::PerPerson->value,
        'price_cents' => '25,00',
        'vat_rate_id' => $catering->getKey(),
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    $manager->callTableAction('create', data: [
        'name' => ['el' => 'Πετσέτα', 'en' => 'Towel'],
        'kind' => 'paid',
        'pricing_type' => ExtraPricing::PerPerson->value,
        'price_cents' => '5,00',
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    $extras = $product->extras()->get();

    expect($extras->firstWhere('price_cents', 2500)?->vat_rate_id)->toBe($catering->getKey())
        ->and($extras->firstWhere('price_cents', 500)?->vat_rate_id)->toBeNull();
})->group('fast');

it('lists a free extra with what the trip includes and never prices it', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $product = Product::factory()->create(['includes' => ['el' => ['Καφές'], 'en' => ['Coffee']]]);
        $masks = Extra::factory()->create(['name' => ['el' => 'Μάσκες', 'en' => 'Masks'], 'pricing_type' => ExtraPricing::Free, 'price_cents' => null]);
        $masks->products()->attach($product->getKey());

        $lines = ExtraLineBuilder::build(OfferedExtrasResolver::forProduct($product), [$masks->getKey() => 3], 4, 4);
        $page = app(BuildProductPage::class)($product, 'el');
        $api = (new ProductDetailResource($product))->resolve(Request::create('/'));

        expect($lines)->toBe([])
            ->and($page['includedExtras'])->toBe(['Μάσκες'])
            ->and(array_column((array) $api['extras'], 'uuid'))->not->toContain($masks->uuid);
    });
})->group('fast');

it('puts a required paid extra on the bill even when it was not asked for', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $product = Product::factory()->create();
        $fee = Extra::factory()->perBooking(1500)->requiredExtra()->create();
        $fee->products()->attach($product->getKey());

        $lines = ExtraLineBuilder::build(OfferedExtrasResolver::forProduct($product), [], 2, 2);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->totalCents)->toBe(1500);
    });
})->group('fast');

it('lets the operator add a free and a paid extra on the trip, and remove one', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    tenancy()->initialize($tenant);

    $product = Product::factory()->create();

    $manager = Livewire::actingAs($owner)
        ->test(ExtrasRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);

    $manager->callTableAction('create', data: [
        'name' => ['el' => 'Μάσκες', 'en' => 'Masks'],
        'kind' => 'free',
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    $manager->callTableAction('create', data: [
        'name' => ['el' => 'Γεύμα', 'en' => 'Lunch'],
        'kind' => 'paid',
        'pricing_type' => ExtraPricing::PerPerson->value,
        'price_cents' => '25,00',
        'is_required' => false,
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    $extras = $product->extras()->get();
    $free = $extras->firstWhere('pricing_type', ExtraPricing::Free);
    $lunch = $extras->firstWhere('pricing_type', ExtraPricing::PerPerson);

    expect($extras)->toHaveCount(2)
        ->and($free?->price_cents)->toBeNull()
        ->and($lunch?->price_cents)->toBe(2500)
        ->and($lunch?->is_tenant_wide)->toBeFalse();

    $manager->callTableAction('delete', $lunch);

    expect($product->extras()->count())->toBe(1)
        ->and(Extra::query()->find($lunch?->getKey()))->toBeNull();
})->group('fast');
