<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Pages\Calendar;
use App\Filament\App\Resources\BookingResource\Pages\ListBookings;
use App\Filament\App\Widgets\OperationsOverview;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\CrewWindow;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| TEN-8's crew window, on every screen rather than one — spec TEN-8
|--------------------------------------------------------------------------
|
| The window was written once, on `DepartureResource`, and the two screens a
| crew member reaches from it never got it:
|
|   - **Bookings.** `ViewPaxList` opens the list and nothing narrowed it, so a
|     skipper could read every booking the operator had ever taken — each with a
|     name, an email address and a telephone number.
|   - **The calendar.** `ViewDepartures` opens the page and the arrows moved the
|     date freely, so the same person could page back a season a day at a time,
|     with a pax list on each.
|
| And TEN-8's other half — "no pricing, no financials" — was not enforced on the
| dashboard at all: `OperationsOverview` had no capability check, so the panel's
| landing page showed a crew member the operator's outstanding balances and the
| week's takings.
|
| All of it was found while writing the manual's chapter on which role sees
| what, because that chapter needed one answer per screen and there were two.
|
*/

/**
 * A panel screen, rendered as the operator, with their tenant resolved.
 *
 * `Livewire::test()` bypasses the panel middleware stack and tenant resolution
 * lives in that stack, so a screen that queries a tenant-owned model throws
 * instead of rendering. The same helper as `DashboardRenderTest`, for the same
 * reason.
 */
function crewWindowScreen(User $user, string $screen): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test($screen);
}

/** @return array{0: User, 1: Tenant, 2: Product} */
function crewWindowFixture(): array
{
    $crew = OperatorUser::withRole(Role::Crew);
    $tenant = Tenant::query()->findOrFail($crew->tenant_id);

    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create());

    return [$crew, $tenant, $product];
}

function departureOn(Tenant $tenant, Product $product, string $date): Departure
{
    return Tenancy::forTenant($tenant, fn (): Departure => Departure::factory()
        ->at($date, '09:00')
        ->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $product->vessel_id,
        ]));
}

beforeEach(function (): void {
    Queue::fake();
});

it('shows crew only the bookings of departures inside the window', function (): void {
    [$crew, $tenant, $product] = crewWindowFixture();

    $today = Carbon::now('Europe/Athens');

    $near = departureOn($tenant, $product, $today->toDateString());
    $far = departureOn($tenant, $product, $today->copy()->addDays(30)->toDateString());

    [$visible, $hidden] = Tenancy::forTenant($tenant, fn (): array => [
        Booking::factory()->for($near)->create(['guest_name' => 'Ελένη Κοντού']),
        Booking::factory()->for($far)->create(['guest_name' => 'Δημήτρης Αλεξίου']),
    ]);

    // Asserted against the rendered rows rather than the query, because the
    // query was the thing that was right on one screen and absent on this one.
    crewWindowScreen($crew, ListBookings::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);
})->group('fast');

it('still shows an owner every booking', function (): void {
    // The window is a restriction on crew, not a filter on the screen. An owner
    // who lost their history to a fix for somebody else's problem would notice
    // in about a minute.
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create());

    $far = departureOn($tenant, $product, Carbon::now('Europe/Athens')->addDays(30)->toDateString());
    $booking = Tenancy::forTenant($tenant, fn (): Booking => Booking::factory()->for($far)->create());

    crewWindowScreen($owner, ListBookings::class)->assertCanSeeTableRecords([$booking]);
})->group('fast');

it('will not let crew page the calendar out of the window', function (): void {
    [$crew] = crewWindowFixture();

    $today = Carbon::now('Europe/Athens')->toDateString();

    crewWindowScreen($crew, Calendar::class)
        ->assertSet('date', $today)
        // Backwards is always outside it: the window starts at today.
        ->call('shiftDays', -1)
        ->assertSet('date', $today)
        ->call('shiftDays', 30)
        ->assertSet('date', $today);
})->group('fast');

it('lets crew reach tomorrow, because that is what the window is for', function (): void {
    // The evening before a 07:00 sailing is the case the window exists to
    // serve, so a fix that clamped to today alone would break the point of it.
    [$crew] = crewWindowFixture();

    $tomorrow = Carbon::now('Europe/Athens')->addDay()->toDateString();

    crewWindowScreen($crew, Calendar::class)
        ->call('shiftDays', 1)
        ->assertSet('date', $tomorrow);
})->group('fast');

it('lets an owner page the calendar anywhere', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $lastMonth = Carbon::now('Europe/Athens')->subDays(30)->toDateString();

    crewWindowScreen($owner, Calendar::class)
        ->call('shiftDays', -30)
        ->assertSet('date', $lastMonth);
})->group('fast');

it('keeps money off the dashboard of somebody who may not see money', function (): void {
    // The two financial figures by their own labels, so a rename cannot make
    // this pass by accident.
    [$crew] = crewWindowFixture();

    crewWindowScreen($crew, OperationsOverview::class)
        ->assertDontSee(__('dashboard.balances.label'))
        ->assertDontSee(__('dashboard.revenue.label'))
        // And the four that are not money are still there — a crew member's
        // dashboard must not become an empty box.
        ->assertSee(__('dashboard.sailing.label'))
        ->assertSee(__('dashboard.at_risk.label'));
})->group('fast');

it('shows an owner all six figures', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    crewWindowScreen($owner, OperationsOverview::class)
        ->assertSee(__('dashboard.balances.label'))
        ->assertSee(__('dashboard.revenue.label'));
})->group('fast');

it('reads the window from configuration rather than a literal', function (): void {
    [$crew] = crewWindowFixture();

    $this->actingAs($crew);

    config()->set('kaiki.panel.crew_departure_window_days', 3);

    expect(CrewWindow::days())->toBe(3)
        ->and(CrewWindow::lastDay()->toDateString())
        ->toBe(Carbon::now('Europe/Athens')->addDays(3)->toDateString());
})->group('fast');
