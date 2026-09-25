<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\ListProducts;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Filament\Tables\Actions\ForceDeleteAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The bin on a deleted trip (Mike, 25/9)
|--------------------------------------------------------------------------
|
| «Στις διαγραμμένες εκδρομές να έχει κάδο, και από εκεί να πηγαίνει στα
| τελείως deleted.» Gone for good — but only a trip nobody ever booked,
| because a booking keeps its trip ({@see ForceDeleteProduct}).
|
*/

function binPage(User $user): Testable
{
    Filament::setCurrentPanel(Filament::getPanel('app'));
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test(ListProducts::class)->set('activeTab', 'trashed');
}

it('keeps deleted trips in their own tab, out of every other', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    [$kept, $deleted] = Tenancy::forTenant($owner->tenant, function (): array {
        $kept = Product::factory()->create();
        $deleted = Product::factory()->create();
        $deleted->delete();

        return [$kept, $deleted];
    });

    binPage($owner)
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$kept])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$deleted]);
})->group('fast');

it('deletes a deleted trip for good, with its departures', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant($owner->tenant, function (): Product {
        $product = Product::factory()->create();
        Departure::factory()->for($product)->create();
        $product->delete();

        return $product;
    });

    binPage($owner)->callTableAction(ForceDeleteAction::class, $product);

    Tenancy::forTenant($owner->tenant, function () use ($product): void {
        expect(Product::withTrashed()->find($product->getKey()))->toBeNull()
            ->and(Departure::query()->where('product_id', $product->getKey())->exists())->toBeFalse();
    });
})->group('fast');

it('keeps a deleted trip that has bookings, and says why', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant($owner->tenant, function (): Product {
        $product = Product::factory()->create();
        Booking::factory()->create(['product_id' => $product->getKey()]);
        $product->delete();

        return $product;
    });

    binPage($owner)
        ->callTableAction(ForceDeleteAction::class, $product)
        ->assertNotified(__('catalog.product.force_delete.refused'));

    Tenancy::forTenant($owner->tenant, function () use ($product): void {
        expect(Product::withTrashed()->find($product->getKey()))->not->toBeNull();
    });
})->group('fast');

it('offers the bin to the owner alone', function (): void {
    $manager = OperatorUser::withRole(Role::Manager);

    $product = Tenancy::forTenant($manager->tenant, function (): Product {
        $product = Product::factory()->create();
        $product->delete();

        return $product;
    });

    binPage($manager)->assertTableActionHidden(ForceDeleteAction::class, $product);
})->group('fast');
