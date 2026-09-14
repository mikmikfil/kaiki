<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\App\Pages\Analytics;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The statistics page
|--------------------------------------------------------------------------
|
| The arithmetic is pinned in `AnalyticsFiguresTest`. What is pinned here is the
| page around it: that the period is in the URL so a report can be sent to
| somebody, that a named period is resolved fresh rather than frozen into two
| dates, and that money is behind `ViewFinancials` while the numbers a manager
| runs the quay on are not.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
});

/**
 * An operator with one month of history behind them.
 *
 * `Livewire::test()` never reaches the panel middleware, so the tenant is
 * initialised by hand exactly as `ResolveTenant` would — the same note
 * `BrandingPageTest` carries, and for the same reason.
 *
 * @return array{0: Tenant}
 */
function analyticsTenant(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'pax_total' => 3,
            'total_cents' => 15000,
            'created_at' => Carbon::parse('2026-08-04 08:00:00', 'UTC'),
        ]);

        Payment::factory()->for($booking)->create([
            'amount_cents' => 15000,
            'paid_at' => Carbon::parse('2026-08-04 09:00:00', 'UTC'),
        ]);

        Departure::factory()->at('2026-08-05', '09:00')->withSeats(3)->create(['capacity' => 12]);
    });

    tenancy()->initialize($tenant);

    return [$tenant];
}

it('shows the owner their money', function (): void {
    [$tenant] = analyticsTenant();

    Livewire::actingAs(OperatorUser::withRole(Role::Owner, $tenant))
        ->test(Analytics::class, ['preset' => 'this_month'])
        ->assertOk()
        ->assertSee(__('analytics.headline.revenue'))
        // The basis, on the screen, because a figure nobody can define is a
        // figure nobody can trust (OPS-2).
        ->assertSee(__('analytics.headline.revenue_basis'))
        ->assertSee(__('analytics.occupancy.heading'));
})->group('fast');

it('gates the money on the capability rather than on the role', function (): void {
    [$tenant] = analyticsTenant();

    // TEN-8 gives a manager `ViewFinancials`, so today a manager does see the
    // money — and this test says so rather than pretending otherwise. What is
    // pinned is that the page asks the capability: if the matrix ever moves the
    // page moves with it, instead of needing somebody to remember this screen.
    $manager = OperatorUser::withRole(Role::Manager, $tenant);

    $page = Livewire::actingAs($manager)->test(Analytics::class, ['preset' => 'this_month'])->assertOk();

    expect($manager->hasCapability(Capability::ViewFinancials))->toBeTrue()
        ->and($page->instance()->showsMoney())->toBeTrue();

    $page->assertSee(__('analytics.occupancy.heading'))
        ->assertSee(__('analytics.cancellations.heading'));
})->group('fast');

it('refuses the page to crew, who get a passenger list and nothing else', function (): void {
    [$tenant] = analyticsTenant();

    actingAs(OperatorUser::withRole(Role::Crew, $tenant))
        ->get(route('filament.app.pages.analytics'))
        ->assertForbidden();
})->group('fast');

it('resolves a named period fresh rather than freezing it into two dates', function (): void {
    [$tenant] = analyticsTenant();

    $page = Livewire::actingAs(OperatorUser::withRole(Role::Owner, $tenant))
        ->test(Analytics::class, ['preset' => 'this_month']);

    expect($page->instance()->range()->startLocalDate)->toBe('2026-08-01')
        ->and($page->instance()->range()->endLocalDate)->toBe('2026-08-31');

    // The first of September must mean September, which a stored pair of dates
    // would not.
    Carbon::setTestNow('2026-09-01 09:00:00');

    expect($page->instance()->range()->startLocalDate)->toBe('2026-09-01');
})->group('fast');

it('carries the period in the URL, so a report can be sent to an accountant', function (): void {
    [$tenant] = analyticsTenant();

    $page = Livewire::actingAs(OperatorUser::withRole(Role::Owner, $tenant))
        ->test(Analytics::class, ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-07']);

    expect($page->instance()->range()->startLocalDate)->toBe('2026-08-01')
        ->and($page->instance()->range()->endLocalDate)->toBe('2026-08-07');
})->group('fast');

it('does not fall over on a half-typed date', function (): void {
    [$tenant] = analyticsTenant();

    // The normal state of a date field somebody is still using.
    Livewire::actingAs(OperatorUser::withRole(Role::Owner, $tenant))
        ->test(Analytics::class, ['preset' => 'custom', 'from' => '2026-08', 'to' => ''])
        ->assertOk();
})->group('fast');
