<?php

declare(strict_types=1);

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
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Drafts you can see, and buttons instead of a status dropdown (2026-09-17)
|--------------------------------------------------------------------------
|
| The list says which trips are drafts and what each is missing; the form
| saves as a draft, publishes, or takes off sale. `SaveProduct` still holds
| the rule; these tests hold the buttons to it.
|
*/

function statusTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/** @param array<string, mixed> $params */
function statusPageAs(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(statusTenantOf($user));

    return Livewire::actingAs($user)->test($page, $params);
}

function completeTripWithStatus(ProductStatus $status): Product
{
    $product = Product::factory()->create([
        'vessel_id' => Vessel::factory()->create()->getKey(),
        'meeting_point_id' => Port::factory()->create()->getKey(),
        'cancellation_policy_id' => CancellationPolicy::factory()->create(['is_default' => true])->getKey(),
        'status' => $status,
    ]);

    $band = AgeBand::factory()->create(['product_id' => $product->getKey()]);
    $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
    RatePlanPrice::factory()->create(['rate_plan_id' => $plan->getKey(), 'age_band_id' => $band->getKey()]);

    return $product;
}

it('opens a new trip on «Βασικά» alone, with «Συνέχεια» and no «create another»', function (): void {
    // Mike, 25/9: creating and editing are one form. The create page is the
    // «Βασικά» tab and a button that goes on to the rest, which is the edit
    // page — so neither the other tabs nor «Δημοσίευση» are here. «Create
    // another» made a second click on a slow connection look like the first
    // one had not worked.
    $owner = OperatorUser::withRole(Role::Owner);

    statusPageAs($owner, CreateProduct::class)
        ->assertSee(__('catalog.product.sections.basics'))
        ->assertSee(__('catalog.product.wizard.continue'))
        ->assertDontSee(__('catalog.product.tabs.prices'))
        ->assertDontSee(__('catalog.product.status_actions.publish'))
        ->assertDontSee(__('filament-panels::resources/pages/create-record.form.actions.create_another.label'));
})->group('fast');

it('walks a draft through its tabs with «Πίσω» and «Επόμενο», and a trip on sale without them', function (): void {
    // What is left of the wizard (25/9): a draft still reads as steps, but the
    // steps are the edit page's own tabs, so moving on is a button at the foot
    // of each. On a trip that is on sale the tabs are just tabs.
    $owner = OperatorUser::withRole(Role::Owner);

    [$draft, $live] = Tenancy::forTenant(statusTenantOf($owner), fn (): array => [
        completeTripWithStatus(ProductStatus::Draft),
        completeTripWithStatus(ProductStatus::Active),
    ]);

    $next = static fn (string $tab): string => __('catalog.product.steps.next', ['tab' => __("catalog.product.tabs.{$tab}")]);

    statusPageAs($owner, EditProduct::class, ['record' => $draft->uuid])
        // Every tab but the last points at the one after it…
        ->assertSee($next('when'))
        ->assertSee($next('prices'))
        ->assertSee($next('terms'))
        ->assertSee($next('page'))
        // …and the last says what comes after the tabs.
        ->assertSee(__('catalog.product.steps.last'));

    statusPageAs($owner, EditProduct::class, ['record' => $live->uuid])
        ->assertDontSee($next('when'))
        ->assertDontSee(__('catalog.product.steps.last'));
})->group('fast');

it('publishes a complete draft from its button', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $product = Tenancy::forTenant(statusTenantOf($owner), fn (): Product => completeTripWithStatus(ProductStatus::Draft));

    statusPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->assertActionVisible('publish')
        ->assertActionHidden('unpublish')
        ->callAction('publish')
        ->assertNotified(__('catalog.product.status_actions.published'));

    Tenancy::forTenant(statusTenantOf($owner), function () use ($product): void {
        expect($product->fresh()->status)->toBe(ProductStatus::Active);
    });
})->group('fast');

it('refuses to publish an incomplete draft, says what is missing, and keeps it a draft', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    // Complete but for its price list, which is not a form field: the save
    // goes through and only publishing is refused.
    $product = Tenancy::forTenant(statusTenantOf($owner), function (): Product {
        $product = completeTripWithStatus(ProductStatus::Draft);
        RatePlan::query()->where('product_id', $product->getKey())->delete();

        return $product;
    });

    statusPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->callAction('publish')
        ->assertSee(__('catalog.product.checklist.rate_plan.unmet'))
        ->assertNotified(__('catalog.product.status_actions.not_published'));

    Tenancy::forTenant(statusTenantOf($owner), function () use ($product): void {
        expect($product->fresh()->status)->toBe(ProductStatus::Draft);
    });
})->group('fast');

it('takes a trip off sale and puts it back', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $product = Tenancy::forTenant(statusTenantOf($owner), fn (): Product => completeTripWithStatus(ProductStatus::Active));

    statusPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->assertActionHidden('publish')
        ->callAction('unpublish');

    Tenancy::forTenant(statusTenantOf($owner), function () use ($product): void {
        expect($product->fresh()->status)->toBe(ProductStatus::Inactive);
    });

    statusPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->assertActionHasLabel('publish', __('catalog.product.status_actions.republish'))
        ->callAction('publish');

    Tenancy::forTenant(statusTenantOf($owner), function () use ($product): void {
        expect($product->fresh()->status)->toBe(ProductStatus::Active);
    });
})->group('fast');

it('archives a trip and brings it back as a draft', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $product = Tenancy::forTenant(statusTenantOf($owner), fn (): Product => completeTripWithStatus(ProductStatus::Active));

    statusPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->callAction('archive');

    Tenancy::forTenant(statusTenantOf($owner), function () use ($product): void {
        expect($product->fresh()->status)->toBe(ProductStatus::Archived);
    });

    statusPageAs($owner, EditProduct::class, ['record' => $product->uuid])
        ->callAction('unarchive');

    Tenancy::forTenant(statusTenantOf($owner), function () use ($product): void {
        expect($product->fresh()->status)->toBe(ProductStatus::Draft);
    });
})->group('fast');

it('lists drafts on their own tab, each saying what it is missing', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    [$draft, $live] = Tenancy::forTenant(statusTenantOf($owner), fn (): array => [
        Product::factory()->create(['vessel_id' => null, 'meeting_point_id' => null, 'status' => ProductStatus::Draft]),
        completeTripWithStatus(ProductStatus::Active),
    ]);

    statusPageAs($owner, ListProducts::class)
        ->set('activeTab', ProductStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$live])
        ->assertSee(__('catalog.product.checklist.vessel.label'));
})->group('fast');
