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
