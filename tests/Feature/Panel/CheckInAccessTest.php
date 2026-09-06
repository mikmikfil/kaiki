<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Pages\CheckIn;
use App\Models\Booking;
use App\Models\Quote;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

use Tests\Support\Booking\CheckInScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| TEN-8 and BKG-20: crew get this page, and almost nothing else
|--------------------------------------------------------------------------
|
| > **TEN-8** `crew` — read-only access to departures within a configurable
| > window, the pax list, check-in actions and manifest view; **no pricing, no
| > financials, no guest documents beyond what the manifest shows**.
|
| A Filament Page has no model to hang a policy on, and Filament allows what
| nothing forbids — so the access check is explicit and this file asserts it
| from both sides: crew are let in, and the money is not.
|
| The second half is the one worth writing down. A page that merely *hides* the
| price behind a role check is one edit away from showing it; the template has
| no price in it at all, and these tests assert the capabilities that would be
| the way round.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lets crew reach the check-in page', function (): void {
    // BKG-20: this is the one Filament surface crew genuinely need, and the
    // only one they use standing on a pier.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/check-in')->assertSuccessful();
})->group('fast');

it('lets an owner and a manager reach it too', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/check-in')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('gates the page on checking guests in, not on reading the list', function (): void {
    // `ViewPaxList` is the *read* capability. Somebody who may only read the
    // manifest has no business on a page whose every control is a write, and
    // gating on the wrong one of the two is the mistake that looks correct.
    $crew = OperatorUser::withRole(Role::Crew);

    expect($crew->hasCapability(Capability::CheckInGuests))->toBeTrue();

    actingAs($crew);

    expect(CheckIn::canAccess())->toBeTrue();
})->group('fast');

it('refuses crew the price on the booking they are checking in', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    Tenancy::forTenant($crew->tenant, function () use ($crew): void {
        /** @var Booking $booking */
        $booking = Booking::factory()->create();

        // TEN-8's "no pricing, no financials", asserted at the capability that
        // would be the way round it — a quote *is* the price.
        expect($crew->hasCapability(Capability::ViewFinancials))->toBeFalse()
            ->and(Gate::forUser($crew)->allows('viewAny', Quote::class))->toBeFalse()
            // And they still see the booking itself, because they have to know
            // who is aboard today.
            ->and(Gate::forUser($crew)->allows('view', $booking))->toBeTrue();
    });
})->group('fast');

it('refuses crew the guest documents', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    // TEN-8: *"no guest documents beyond what the manifest shows"*. A passport
    // number is not on the manifest, and the check-in page does not render one.
    expect($crew->hasCapability(Capability::ViewGuestDocuments))->toBeFalse();
})->group('fast');

it('renders no price on the page itself', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    $crew = OperatorUser::withRole(Role::Crew);

    [, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    // The booking above belongs to another tenant, so this asserts two things
    // at once: the page renders, and it renders **nothing** from a booking
    // outside the crew member's own tenant (#8's global scope).
    $response = actingAs($crew)->get('/app/check-in');

    $response->assertSuccessful()
        ->assertDontSee($booking->reference)
        ->assertDontSee('€');
})->group('fast');

it('shows the crew member their own tenant bookings', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    actingAs($crew)->get('/app/check-in')
        ->assertSuccessful()
        ->assertSee($booking->reference)
        // The manifest line: a name and a seat, which is exactly TEN-8's grant.
        ->assertSee('Μαρία Παπαδοπούλου', escape: false);
})->group('fast');

it('resolves a scanned ticket to its guest', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    $code = Tenancy::forTenant($tenant, fn (): string => (string) $booking->guests()->first()->ticket_code);

    // A phone camera opening the QR's URL lands here with `?ticket=…` already
    // set, which is the difference between one gesture and four.
    actingAs($crew)->get('/app/check-in?ticket=' . $code)
        ->assertSuccessful()
        ->assertSee('Μαρία Παπαδοπούλου', escape: false);
})->group('fast');

it('will not resolve another tenant ticket code', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$otherTenant, $otherBooking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    $code = Tenancy::forTenant($otherTenant, fn (): string => (string) $otherBooking->guests()->first()->ticket_code);

    $crew = OperatorUser::withRole(Role::Crew);

    // `ticket_code` is globally unique and `BookingGuest::findByTicketCode()`
    // deliberately looks it up **without tenancy** — that is what resolves the
    // tenant for an unauthenticated scan. This page is not that case: it has a
    // signed-in user, so it uses the scoped query, and a code from somebody
    // else's fleet resolves to nothing.
    actingAs($crew)->get('/app/check-in?ticket=' . $code)
        ->assertSuccessful()
        ->assertDontSee($otherBooking->reference);
})->group('fast');
