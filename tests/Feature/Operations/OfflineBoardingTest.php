<?php

declare(strict_types=1);

use App\Domain\Booking\Support\TicketQr;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Http\Controllers\App\BoardingController;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Vite;

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

/*
|--------------------------------------------------------------------------
| The camera in the page (Mike, 2026-09-23)
|--------------------------------------------------------------------------
|
| «Σάρωση εισιτηρίων» opens the camera inside the boarding page and reads
| passenger after passenger. The camera itself is the browser's, and the
| decoding is JavaScript; what PHP can assert is what the page and its worker
| promise: the button is there only for an operator who scans, the script is
| served from this origin and precached for a quay with no signal, and the
| home page's button arrives asking for the camera.
|
*/

/** Vite pointed at a fixed dev-server address, so the script's URL is known. */
function boardingViteAt(string $origin): string
{
    $hot = tempnam(sys_get_temp_dir(), 'kaiki-hot');
    file_put_contents($hot, $origin);
    Vite::useHotFile($hot);

    return $origin . '/' . BoardingController::SCANNER_ENTRY;
}

it('offers the camera to an operator who scans, and precaches its script', function (): void {
    [$tenant, $crew] = boardingCrew();
    $script = boardingViteAt('http://vite.test');

    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    expect($body)->toContain('id="camOpen"')
        ->and($body)->toContain(e(__('boarding.camera.open')))
        ->and($body)->toContain(e(__('boarding.camera.close')))
        ->and($body)->toContain('id="scannerScript" src="' . $script . '"')
        // The text box stays: a torn QR is typed.
        ->and($body)->toContain('id="scanForm"')
        ->and($body)->toContain('data-auto="0"');

    // The same URL in the worker, or the first scan on the boat finds no
    // decoder. Not escaped: it is a JavaScript string, and `/` is plain.
    $response = actingAs($crew)->get(route('filament.app.boarding.sw'))->assertOk();

    expect((string) $response->getContent())->toContain('const ASSETS = ["' . $script . '"]');
});

it('lets the worker control the boarding page itself, and nothing wider', function (): void {
    [$tenant, $crew] = boardingCrew();

    // Served from `/app/boarding/sw.js`, a worker may only claim
    // `/app/boarding/` by default — which the page, `/app/boarding`, is not
    // under. Until this header the page was never controlled, and a reload
    // with no signal found nothing.
    $response = actingAs($crew)->get(route('filament.app.boarding.sw'))->assertOk();

    expect($response->headers->get('Service-Worker-Allowed'))->toBe('/app/boarding');

    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    expect($body)->toContain('scope: "\/app\/boarding"');
});

it('offers no camera and loads no decoder when the operator does not scan', function (): void {
    [$tenant, $crew] = boardingCrew();
    $tenant->forceFill(['qr_check_in_enabled' => false])->save();
    boardingViteAt('http://vite.test');

    $body = (string) actingAs($crew)->get(route('filament.app.boarding', ['camera' => 1]))->assertOk()->getContent();

    expect($body)->not->toContain('id="camOpen"')
        ->and($body)->not->toContain('id="scannerScript"')
        ->and($body)->not->toContain(e(__('boarding.camera.open')));
});

it('opens the camera straight away when the home page asks for it', function (): void {
    [$tenant, $crew] = boardingCrew();
    boardingViteAt('http://vite.test');

    $body = (string) actingAs($crew)->get(route('filament.app.boarding', ['camera' => 1]))->assertOk()->getContent();

    expect($body)->toContain('data-auto="1"');
});

it('still boards by typed code when the scripts were never built', function (): void {
    [$tenant, $crew] = boardingCrew();

    // No hot file and no manifest: a deploy that skipped `npm run build`.
    Vite::useHotFile(sys_get_temp_dir() . '/kaiki-no-such-hot-file');
    Vite::useBuildDirectory('kaiki-no-such-build');

    expect(BoardingController::scannerScriptUrl())->toBeNull();

    // A 500 here would take away the one boarding that works with no signal.
    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    expect($body)->toContain('id="scanForm"')
        ->and($body)->not->toContain('id="scannerScript"');

    $worker = (string) actingAs($crew)->get(route('filament.app.boarding.sw'))->assertOk()->getContent();

    expect($worker)->toContain('const ASSETS = []');
});
