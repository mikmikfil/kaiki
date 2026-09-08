<?php

declare(strict_types=1);

use App\Domain\Booking\Support\TicketQr;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| #128 — boarding on a quay with no signal (OPS-12, BKG-20, OOS-6)
|--------------------------------------------------------------------------
|
| The client half — the IndexedDB queue, the service worker, the optimistic
| tick — cannot be asserted from PHP, and #129's Playwright run is where it is
| exercised. What *can* be asserted here is everything the page depends on, and
| it is the half that would fail silently:
|
| - the manifest is **in the response**, because a page that fetched it would
|   work only when it did not need to;
| - it carries a name and a seat and **no document number**, because it sits in
|   a phone's cache on a boat;
| - the endpoint answers **per scan**, so one unknown ticket among nineteen good
|   ones does not lose the nineteen;
| - a replayed scan says `already` rather than failing, which is OPS-12's second
|   clause and the thing a queue guarantees will happen.
|
*/

/** @return array{0: Tenant, 1: User, 2: BookingGuest} */
function boardingCrew(): array
{
    [$tenant, $booking] = GuestPageScenario::booking();

    $guest = Tenancy::forTenant($tenant, function () use ($booking): BookingGuest {
        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            // Inside the check-in window. BKG-22 opens it
            // `check_in_offset_minutes` before departure — thirty by default —
            // so a boat two hours out is refused, correctly, and a test that
            // used one would be testing the window rather than the queue.
            'starts_at_utc' => Carbon::now()->addMinutes(15),
            // Both ends. The window closes on `ends_at_utc`, and a booking
            // that starts in fifteen minutes and finished in July is
            // refused with "this trip has already finished" — which is the
            // window working and the fixture being half-moved.
            'ends_at_utc' => Carbon::now()->addHours(4),
        ])->save();

        // The scenario already seeds a guest at position 1, and
        // `booking_guests` is unique on (tenant, booking, position). Reusing it
        // rather than adding a second is also closer to the real shape: a
        // ticket belongs to a passenger who already exists.
        $guest = BookingGuest::query()->where('booking_id', $booking->getKey())->firstOrFail();

        $guest->forceFill([
            'full_name' => 'Μαρία Παπαδοπούλου',
            'ticket_code' => 'TCK-BOARDING-1',
            'checked_in_at' => null,
        ])->save();

        return $guest;
    });

    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    return [$tenant, $crew, $guest];
}

it('puts today\'s manifest in the page itself', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    // In the bytes, not behind a fetch. A page that asked the server for its
    // manifest on load would be a page that works only when it does not need to.
    expect($body)->toContain('TCK-BOARDING-1')
        ->and($body)->toContain('Μαρία Παπαδοπούλου');
});

it('carries no document number into a phone\'s cache', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    Tenancy::forTenant($tenant, function () use ($guest): void {
        $guest->forceFill(['document_number' => 'AB1234567'])->save();
    });

    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    // TEN-8 gives crew no guest documents, and this payload sits on a phone on
    // a boat. Asserted against the bytes, the way `ExportRows` is: there is no
    // case for a document number in the manifest, so there is no mechanism that
    // could include one.
    expect($body)->not->toContain('AB1234567');
});

it('checks a guest in through the same Action the panel uses', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    $response = actingAs($crew)
        ->postJson(route('filament.app.boarding.scan'), [
            'scans' => [['ticket_code' => 'TCK-BOARDING-1', 'scanned_at' => Carbon::now()->toIso8601String()]],
        ])
        ->assertOk();

    expect($response->json('results.0.status'))->toBe('checked_in');

    Tenancy::forTenant($tenant, function () use ($guest): void {
        expect($guest->fresh()->checked_in_at)->not->toBeNull();
    });
});

it('says a replayed scan is already aboard rather than failing', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    $scan = ['scans' => [['ticket_code' => 'TCK-BOARDING-1']]];

    actingAs($crew)->postJson(route('filament.app.boarding.scan'), $scan)->assertOk();

    $again = actingAs($crew)->postJson(route('filament.app.boarding.scan'), $scan)->assertOk();

    // OPS-12's second clause, and the case a queue guarantees will happen: a
    // scan sent twice because the first response never came back, or two crew
    // on two phones. `CheckInGuest` returns false for the loser, and that is
    // reported as `already` rather than as an error.
    expect($again->json('results.0.status'))->toBe('already');
});

it('answers each scan in a batch on its own', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    $response = actingAs($crew)
        ->postJson(route('filament.app.boarding.scan'), [
            'scans' => [
                ['ticket_code' => 'NOT-A-TICKET'],
                ['ticket_code' => 'TCK-BOARDING-1'],
            ],
        ])
        ->assertOk();

    // One unknown ticket among the good ones must not lose the good ones. A
    // phone that has been offline for twenty minutes sends its whole queue at
    // once, and a batch that failed as a unit would drop a boat's worth of
    // boarding because somebody scanned a supermarket receipt.
    expect($response->json('results.0.status'))->toBe('unknown')
        ->and($response->json('results.1.status'))->toBe('checked_in');
});

it('refuses a crew member from another operator', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    $other = Tenant::factory()->create();
    $stranger = OperatorUser::withRole(Role::Crew, $other);

    $response = actingAs($stranger)
        ->postJson(route('filament.app.boarding.scan'), [
            'scans' => [['ticket_code' => 'TCK-BOARDING-1']],
        ]);

    // The ticket resolves with one indexed read and no tenant context (§2.5),
    // which is what makes a pier scan fast — and is exactly why the *guest*
    // lookup cannot be the thing that isolates operators. `BelongsToTenant`
    // scopes it; this asserts it, because the read is deliberately unusual.
    expect($response->json('results.0.status'))->toBe('unknown');

    Tenancy::forTenant($tenant, function () use ($guest): void {
        expect($guest->fresh()->checked_in_at)->toBeNull();
    });
});

it('refuses somebody without the crew capability', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    // Every operator role carries `CheckInGuests` — a manager boards people
    // too, and an owner certainly does — so the honest negative is somebody
    // holding a panel session with no role in this tenant at all.
    $stranger = User::factory()->create(['tenant_id' => null]);

    expect($stranger->hasCapability(Capability::CheckInGuests))->toBeFalse();

    // A route has no policy to hang authorisation on, so the controller asserts
    // TEN-8's capability itself. Without that line the page would be reachable
    // by anybody who could reach the panel.
    actingAs($stranger)->get(route('filament.app.boarding'))->assertForbidden();
});

it('serves a service worker scoped to the boarding path alone', function (): void {
    [$tenant, $crew] = boardingCrew();

    $response = actingAs($crew)->get(route('filament.app.boarding.sw'))->assertOk();

    // The path is the scope. At `/app/boarding/sw.js` it can only ever control
    // `/app/boarding/…`; served from the root it would cache authenticated
    // panel HTML, and a phone handed on after a sign-out would still render the
    // last operator's screens.
    expect(route('filament.app.boarding.sw'))->toContain('/app/boarding/')
        ->and($response->headers->get('Content-Type'))->toContain('javascript')
        // A stale service worker is the one bug on this page nobody can clear
        // without developer tools.
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('points a new ticket\'s QR at the page that works without a signal', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    $payload = TicketQr::payloadFor($guest);

    expect($payload)->toContain('/app/boarding')
        ->and($payload)->toContain('TCK-BOARDING-1');
});

it('keeps every ticket already printed working', function (): void {
    [$tenant, $crew, $guest] = boardingCrew();

    // A QR on a sheet of paper in somebody's bag cannot be reissued. The old
    // Livewire page still accepts `?ticket=` and still checks people in;
    // nothing was removed from it when the new page arrived.
    actingAs($crew)
        ->get(route('filament.app.pages.check-in', ['ticket' => 'TCK-BOARDING-1']))
        ->assertOk();
});
