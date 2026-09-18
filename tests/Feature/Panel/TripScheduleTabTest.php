<?php

declare(strict_types=1);

use App\Domain\Availability\Support\WeekdayMask;
use App\Enums\BookingMode;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\RelationManagers\ScheduleRulesRelationManager;
use App\Filament\App\Resources\ScheduleRuleResource;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Support\Tenancy;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Δρομολόγια» inside the trip (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| The timetable left the menu for a tab on the trip. The rules and their
| single writer did not change; these tests hold the new screen to them.
|
*/

it('adds a schedule to the trip it is opened on, without asking which trip', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    tenancy()->initialize($tenant);

    $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

    Livewire::actingAs($owner)
        ->test(ScheduleRulesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->mountTableAction('create')
        ->set('mountedTableActionsData.0.start_times', ['a' => ['time' => '09:00']])
        ->setTableActionData([
            'weekdays' => [2, 4],
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-09-15',
            'is_active' => true,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $rule = ScheduleRule::query()->where('product_id', $product->getKey())->sole();

    expect($rule->weekday_mask)->toBe(WeekdayMask::fromDays([2, 4]))
        ->and(substr((string) $rule->start_time, 0, 5))->toBe('09:00')
        ->and($rule->generate_days_ahead)->toBe(180)
        ->and(ProductResource::scheduleBadge($product))->toBe(trans_choice('catalog.product.tabs.active_schedules', 1, ['count' => 1]));
})->group('fast');

it('adds one schedule row per departure time, with the same days and dates', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

    Livewire::actingAs($owner)
        ->test(ScheduleRulesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->mountTableAction('create')
        // Keyed like the repeater's own items, replacing its one empty default.
        ->set('mountedTableActionsData.0.start_times', [
            'a' => ['time' => '09:00'],
            'b' => ['time' => '13:00'],
            'c' => ['time' => '17:00'],
        ])
        ->setTableActionData([
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-09-15',
            'is_active' => true,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $rules = ScheduleRule::query()->where('product_id', $product->getKey())->orderBy('start_time')->get();

    expect($rules)->toHaveCount(3)
        ->and($rules->map(static fn (ScheduleRule $rule): string => substr((string) $rule->start_time, 0, 5))->all())->toBe(['09:00', '13:00', '17:00'])
        ->and($rules->pluck('weekday_mask')->unique()->all())->toBe([WeekdayMask::fromDays([1, 2, 3, 4, 5, 6, 7])]);
})->group('fast');

it('says «none» on a trip nobody can book on any day', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), function (): void {
        $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

        expect(ProductResource::scheduleBadge($product))->toBe(__('catalog.product.tabs.no_schedule'));
    });
})->group('fast');

it('offers no timetable to a whole-boat charter', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), function () use ($owner): void {
        actingAs($owner);
        $charter = Product::factory()->create(['mode' => BookingMode::PerVessel]);

        expect(ScheduleRulesRelationManager::canViewForRecord($charter, EditProduct::class))->toBeFalse();
    });
})->group('fast');

it('takes «Δρομολόγια» out of the menu but keeps its address', function (): void {
    expect(ScheduleRuleResource::shouldRegisterNavigation())->toBeFalse();

    actingAs(OperatorUser::withRole(Role::Owner))->get('/app/schedule-rules')->assertSuccessful();
})->group('fast');
