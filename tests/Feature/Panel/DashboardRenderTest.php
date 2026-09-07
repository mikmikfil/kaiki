<?php

declare(strict_types=1);

use App\Domain\Operations\Support\FirstSteps as Steps;
use App\Enums\BookingStatus;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Widgets\FirstSteps;
use App\Filament\App\Widgets\OperationsOverview;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| OPS-1: the screen itself, not only the arithmetic behind it
|--------------------------------------------------------------------------
|
| `DashboardFiguresTest` proves the numbers. This proves an operator can see
| them — which is a separate failure and a common one: a widget whose query is
| perfect and whose `canView` is wrong shows nothing at all, and every unit test
| still passes.
|
| The other half is the first afternoon. An operator who has just signed up sees
| six zeros and four links to empty lists unless something else takes the space,
| and that is the day they decide whether this product is real.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-08 10:00:00');
});

/**
 * A widget, rendered as the operator, with their tenant resolved.
 *
 * `Livewire::test()` bypasses the panel middleware stack, and tenant resolution
 * lives in that stack — so a widget that queries a tenant-owned model throws
 * instead of rendering. Initialising here is what the middleware would have
 * done, and it keeps the test about the widget.
 */
function dashboardWidget(User $user, string $widget): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test($widget);
}

it('shows the six figures to an operator who has taken a booking', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        Booking::factory()->create(['status' => BookingStatus::Confirmed, 'balance_cents' => 4200]);
        Departure::factory()->at('2026-07-08', '09:00')->withSeats(6)->create();
    });

    dashboardWidget($user, OperationsOverview::class)
        ->assertOk()
        // The definition, on the card. OPS-2 asks for the number to be
        // explicable, and a tooltip is not an explanation somebody reads before
        // they decide the product is wrong about money.
        ->assertSee(__('dashboard.revenue.label'))
        ->assertSee(__('dashboard.balances.label'))
        ->assertSee(__('dashboard.at_risk.definition', ['hours' => 48]));

    expect(Tenancy::forTenant($user->tenant, fn (): bool => OperationsOverview::canView()))->toBeTrue()
        ->and(Tenancy::forTenant($user->tenant, fn (): bool => FirstSteps::canView()))->toBeFalse();
});

it('shows the first steps instead of six zeros to an operator who has not', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        expect(FirstSteps::canView())->toBeTrue()
            ->and(OperationsOverview::canView())->toBeFalse();
    });

    dashboardWidget($user, FirstSteps::class)
        ->assertOk()
        ->assertSee(__('dashboard.first_steps.heading'))
        // The first thing to do, and only the first: a checklist of four open
        // items is a decision about where to start.
        ->assertSee(__('dashboard.first_steps.vessel.action'));
});

it('walks the steps in the order the engine needs them', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        expect(Steps::next())->toBe(Steps::VESSEL);

        Vessel::factory()->create();

        expect(Steps::next())->toBe(Steps::PRODUCT);

        $product = Product::factory()->create(['status' => ProductStatus::Draft]);

        expect(Steps::next())->toBe(Steps::PUBLISHED);

        $product->update(['status' => ProductStatus::Active]);

        expect(Steps::next())->toBe(Steps::DEPARTURE);

        Departure::factory()->for($product)->create();

        expect(Steps::next())->toBeNull();
    });
});

it('does not mistake a quiet November for an operator who has never started', function (): void {
    // The dangerous confusion. An operator with a season behind them and nothing
    // on the calendar must not be shown a getting-started panel — so the test is
    // whether they have *ever* had a booking, not whether they have one now.
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        Booking::factory()->create(['status' => BookingStatus::Completed, 'balance_cents' => 0]);

        expect(Steps::applies())->toBeFalse();
    });
});
