<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| TOK-6's e-ticket, on the page and on demand (2026-09-18)
|--------------------------------------------------------------------------
|
| The page carried «Το εισιτήριό σας θα είναι διαθέσιμο εδώ λίγο πριν την
| αναχώρηση» from M2 until today, and never grew the button — although #88 had
| built the PDF, the route and the translation for it. So the first thing here
| is that the button exists.
|
| The second is the harder one. Rendering is queued on purpose (a Chromium that
| will not start must not unwind a payment), which leaves a window where the
| booking is confirmed and the file is not there — seconds on a healthy host,
| for ever on one whose worker is stopped. The download renders it then and
| there rather than answering "this link is not valid", which is a sentence
| nobody at a quay can act on.
|
| And two bookings still get nothing: a cancelled one, and an operator who
| boards nobody. Both are asserted on the page *and* on the route, because a
| hidden button is not a rule — the URL is guessable by anyone holding the
| token, and it is the same token the guest already has.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** The disk the tickets live on, faked, so nothing touches real storage. */
function ticketDisk(): string
{
    $disk = (string) config('kaiki.tickets.disk', 'local');

    Storage::fake($disk);

    return $disk;
}

it('offers the ticket on the booking page', function (): void {
    [, $booking] = GuestPageScenario::booking();

    get('/b/' . $booking->manage_token)
        ->assertOk()
        // Β2 puts the ticket and the calendar together under one label — both
        // are «take this with you» — so the assertion is on the button and the
        // link rather than on a heading of its own.
        ->assertSee(__('guest.booking.take_with_you'))
        ->assertSee(__('guest.booking.ticket'))
        ->assertSee('/b/' . $booking->manage_token . '/ticket', escape: false);
})->group('fast');

it('offers no ticket for a cancelled booking, and serves none either', function (): void {
    ticketDisk();

    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.booking.ticket'))
        ->assertDontSee('/b/' . $booking->manage_token . '/ticket', escape: false);

    // The button being absent is not the guarantee: the URL is guessable from
    // the page's own address, so the route refuses it too rather than boarding
    // somebody onto a trip they are not on.
    get('/b/' . $booking->manage_token . '/ticket')->assertNotFound();
})->group('fast');

it('offers no ticket when the operator boards nobody', function (): void {
    ticketDisk();

    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($tenant->getKey())
        ->update(['check_in_enabled' => false]));

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.booking.ticket'))
        ->assertDontSee('/b/' . $booking->manage_token . '/ticket', escape: false);

    get('/b/' . $booking->manage_token . '/ticket')->assertNotFound();
})->group('fast');

it('serves the ticket the queue already rendered, without rendering a second one', function (): void {
    $disk = ticketDisk();

    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, static function () use ($booking, $disk): void {
        Storage::disk($disk)->put('tickets/already-there.pdf', '%PDF-1.4 the one the queue made');

        $booking->forceFill(['eticket_path' => 'tickets/already-there.pdf'])->save();
    });

    $response = get('/b/' . $booking->manage_token . '/ticket')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))
        // The reference, because that is the string a guest recognises in a
        // downloads folder three weeks later.
        ->toContain($booking->reference . '.pdf')
        ->and($response->getContent())->toBe('%PDF-1.4 the one the queue made')
        // Untouched: a render here would replace a file that was already right.
        ->and($booking->refresh()->eticket_path)->toBe('tickets/already-there.pdf');
})->group('fast');

it('renders the ticket on demand when the queue never did', function (): void {
    $disk = ticketDisk();

    [, $booking] = GuestPageScenario::booking();

    expect($booking->eticket_path)->toBeNull();

    $response = get('/b/' . $booking->manage_token . '/ticket')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF')
        // And it is kept, so the next tap is a file read rather than a browser.
        ->and($booking->refresh()->eticket_path)->not->toBeNull()
        ->and(Storage::disk($disk)->exists((string) $booking->refresh()->eticket_path))->toBeTrue();
})->skip(
    // ENV-20's skip, spelled out here rather than borrowed from `ETicketTest`:
    // a global function declared in another Pest file exists only once that
    // file has been loaded, and the order is the runner's business.
    fn (): bool => ! is_string(config('kaiki.tickets.chrome_path')) || config('kaiki.tickets.chrome_path') === '',
    'ENV-20: rendering a ticket needs a Chromium binary. Set KAIKI_CHROME_PATH in .env, '
    . 'or run this in CI where it is always present.',
)->group('chromium');

/*
|--------------------------------------------------------------------------
| The boarding codes on the page (product owner, 2026-09-18)
|--------------------------------------------------------------------------
|
| «Να φαίνεται όποτε υπάρχει.» The codes have existed inside the PDF since #88,
| which put a download, a PDF reader and a pinch-zoom between a guest and the
| square the crew scans.
|
| "Whenever there is one" is three conditions, and the assertions below are the
| three of them, because each is somebody else's decision and any of them can
| change after the page was last looked at: the platform switches QR boarding on
| per operator, a booking can be cancelled, and a trip sails.
|
*/

it('shows a code per passenger when the operator scans them', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($tenant->getKey())
        ->update(['check_in_enabled' => true, 'qr_check_in_enabled' => true]));

    $codes = Tenancy::forTenant($tenant, static fn (): array => $booking->guests()
        ->orderBy('position')
        ->pluck('ticket_code')
        ->all());

    $response = get('/b/' . $booking->manage_token)->assertOk();

    $response->assertSee(__('guest.booking.boarding.heading'));

    foreach ($codes as $code) {
        // Each passenger's own code, not the booking's reference and not one
        // square for the party: the crew scans one per person.
        $response->assertSee((string) $code);
    }

    // A booking of two draws two squares.
    expect(substr_count($response->getContent(), 'class="pass"'))->toBe(2);
})->group('fast');

it('shows no code where the operator boards from a list', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($tenant->getKey())
        ->update(['check_in_enabled' => true, 'qr_check_in_enabled' => false]));

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.booking.boarding.heading'));
})->group('fast');

it('shows no code for a cancelled booking or a trip that has sailed', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($tenant->getKey())
        ->update(['check_in_enabled' => true, 'qr_check_in_enabled' => true]));

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();
    });

    // A code that still scanned green at a gangway is the one outcome worth
    // engineering against.
    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.booking.boarding.heading'));

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            'starts_at_utc' => Carbon::now()->subDay(),
        ])->save();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.booking.boarding.heading'));
})->group('fast');
