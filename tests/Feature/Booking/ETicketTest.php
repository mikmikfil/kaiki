<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\GenerateETicket;
use App\Domain\Booking\Support\TicketQr;
use App\Events\BookingConfirmed;
use App\Listeners\Booking\GenerateETicketOnConfirmation;
use App\Models\Booking;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Booking\CheckInScenario;

/*
|--------------------------------------------------------------------------
| BKG-13.1 and ENV-20: the PDF, its QR, and the skip that must be loud
|--------------------------------------------------------------------------
|
| > **ENV-20** Browsershot requires Chromium. Locally it points at an installed
| > Chrome through an `.env` path; PDF tests are in the `chromium` group and are
| > skipped when the path is absent. CI always runs them.
|
| The skip is a `->skip()` modifier with a **sentence**, never a silent pass.
| A test that quietly succeeds because the binary is missing is a test that has
| stopped existing, and nobody notices until the ticket a guest opens is blank.
|
| The QR payload is asserted here rather than left to the renderer, because it
| is the one security decision in this issue disguised as a formatting one: the
| square carries `ticket_code`, never the reference and never a uuid, and it
| decodes to a URL a crew member's camera can act on.
|
*/

/**
 * ENV-20's skip, in the shape `requiresMysql()` established.
 *
 * @return array{0: callable(): bool, 1: string}
 */
function requiresChromium(): array
{
    return [
        // Not `static` — Pest binds a `->skip()` closure to the test case, and
        // PHP refuses to bind an instance to a static closure. The lesson from
        // #81's AVL-44 skip, which failed on the skip rather than on the test.
        fn (): bool => ! is_string(config('kaiki.tickets.chrome_path'))
            || config('kaiki.tickets.chrome_path') === '',
        'ENV-20: the e-ticket PDF needs a Chromium binary. Set KAIKI_CHROME_PATH in .env '
        . 'to an installed Chrome, or run this in CI where it is always present. '
        . 'Passing without rendering would prove nothing.',
    ];
}

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-03 08:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| The payload, which needs no browser at all
|--------------------------------------------------------------------------
|
| These run everywhere. The *choice* of what goes in the QR is the part worth
| protecting from a well-meaning refactor, and gating it behind a Chromium
| binary would mean it went unasserted on every developer machine.
|
*/

it('puts the ticket code in the QR, not the reference and not a uuid', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $guest = $booking->guests()->first();

        $payload = TicketQr::payloadFor($guest);

        expect($payload)->toContain($guest->ticket_code)
            // BKG-3's reference is short and human-typeable **on purpose**,
            // which is exactly what makes it unsuitable: its alphabet is about
            // 28.6 million per tenant, and a QR encoding one is a check-in
            // anybody can forge with a printer.
            ->and($payload)->not->toContain($booking->reference)
            // And the uuid is CNV-8's *public* identifier — it travels in API
            // responses and widget payloads, so it is not a credential.
            ->and($payload)->not->toContain($booking->uuid)
            ->and($payload)->not->toContain($guest->uuid)
            // The `manage_token` fails twice over: it is per **booking**, so a
            // family of four would carry four identical codes and per-guest
            // check-in would be impossible — and it is the credential for
            // `/b/{token}`, where a guest can cancel and take a refund.
            ->and($payload)->not->toContain($booking->manage_token);
    });
})->group('fast');

it('gives every guest on a booking a different code', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), pax: 2);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $codes = $booking->guests->map(fn ($guest): string => TicketQr::payloadFor($guest))->all();

        // BKG-21 transitions on the *first* check-in and BKG-23 marks a
        // no-show per person. One code for the booking would put the whole
        // party aboard the moment one of them arrived.
        expect($codes)->toHaveCount(2)
            ->and($codes[0])->not->toBe($codes[1]);
    });
})->group('fast');

it('renders the QR as an inline svg with no xml declaration', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $svg = TicketQr::svgFor($booking->guests()->first());

        expect(str_starts_with(trim($svg), '<svg'))->toBeTrue()
            // Bacon emits a standalone document. An XML declaration is invalid
            // inside an HTML body and Chromium's parser drops the whole element
            // when it sees one — a blank square where the QR should be.
            ->and($svg)->not->toContain('<?xml');
    });
})->group('fast');

it('points the QR at the check-in page rather than at a bare code', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // The thing scanning this is an ordinary phone camera: pointing it at a
        // ticket should open the check-in page with the guest resolved, not
        // show a string somebody has to type in.
        expect(TicketQr::payloadFor($booking->guests()->first()))->toContain('/app/check-in');
    });
})->group('fast');

/*
|--------------------------------------------------------------------------
| The render itself, which needs the browser
|--------------------------------------------------------------------------
*/

it('generates a PDF and stores it on the private disk', function (): void {
    Storage::fake('local');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $path = app(GenerateETicket::class)($booking);

        Storage::disk('local')->assertExists($path);

        $bytes = (string) Storage::disk('local')->get($path);

        expect(str_starts_with($bytes, '%PDF-'))->toBeTrue()
            ->and($booking->refresh()->eticket_path)->toBe($path)
            ->and($booking->eticket_generated_at)->not->toBeNull()
            // Proves the file a guest presents is the file we produced. The
            // alternative, when somebody arrives with a convincing forgery, is
            // an argument.
            ->and($booking->eticket_hash)->toBe(hash('sha256', $bytes));
    });
})->skip(...requiresChromium())->group('chromium');

it('never writes a ticket to the public disk', function (): void {
    Storage::fake('local');
    Storage::fake('public');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(GenerateETicket::class)($booking);
    });

    // A file on the `public` disk is served by the web server to anybody who
    // can guess its path, with no session and no policy in the way — a wider
    // hole than every token page in #86 put together.
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->skip(...requiresChromium())->group('chromium');

it('regenerates in place rather than versioning', function (): void {
    Storage::fake('local');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $first = app(GenerateETicket::class)($booking);
        $second = app(GenerateETicket::class)($booking->refresh());

        // The difference between a ticket and a ναυλοσύμφωνο: a ticket is a
        // convenience and the booking is the truth, so a guest who corrected
        // their name gets a corrected ticket rather than a second one.
        expect($second)->toBe($first)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });
})->skip(...requiresChromium())->group('chromium');

it('is generated by a queued listener on confirmation', function (): void {
    Storage::fake('local');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    // BKG-13.1. The listener resolves the tenant first and the booking second
    // — a queued listener is constructed on a worker, inside whatever tenant
    // the previous job left behind (#53).
    app(GenerateETicketOnConfirmation::class)->handle(
        new BookingConfirmed($booking->getKey(), $tenant->getKey()),
    );

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->eticket_path)->not->toBeNull();
    });
})->skip(...requiresChromium())->group('chromium');

it('queues the ticket rather than blocking the confirmation', function (): void {
    Event::fake([BookingConfirmed::class]);

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    BookingConfirmed::dispatch($booking->getKey(), $tenant->getKey());

    // BKG-14: *"a failure in any listener MUST NOT roll back the confirmation
    // or block the others"*. `ShouldQueue` is what makes that structurally
    // true, and it is one interface away from being lost in a refactor.
    Event::assertDispatched(BookingConfirmed::class);

    expect(app(GenerateETicketOnConfirmation::class))
        ->toBeInstanceOf(ShouldQueue::class);
})->group('fast');

it('carries the guests name, the meeting point and the check-in time', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), offsetMinutes: 30);

    $html = Tenancy::forTenant($tenant, fn (): string => view('pdf.e-ticket', [
        'booking' => $booking->load(['guests', 'product.meetingPoint', 'vessel']),
        'brand' => [],
    ])->render());

    // Asserted against the **markup** rather than the PDF, so it runs
    // everywhere: what a ticket says is a content decision and does not need a
    // browser to check. 09:00 UTC less thirty minutes is 08:30 UTC, which is
    // 11:30 in Athens — CNV-2, the guest's own clock.
    expect($html)->toContain('Μαρία Παπαδοπούλου')
        ->and($html)->toContain('11:30')
        ->and($html)->toContain($booking->reference)
        // No price, no payment status. A ticket is shown to a crew member in
        // the sun; the money lives on `/b/{manage_token}`.
        ->and($html)->not->toContain('€')
        // I18N-2: uppercasing Greek drops the accents, and the guest's own
        // name is printed here.
        ->and(strtolower($html))->not->toContain('uppercase')
        // SEC-14: a locked-down Chromium with no local file access, so there is
        // nothing here to fetch.
        ->and($html)->not->toContain('<img');
})->group('fast');

it('renders one page per guest', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), pax: 2);

    $html = Tenancy::forTenant($tenant, fn (): string => view('pdf.e-ticket', [
        'booking' => $booking->load(['guests', 'product.meetingPoint', 'vessel']),
        'brand' => [],
    ])->render());

    expect(substr_count($html, 'class="ticket"'))->toBe(2)
        ->and($html)->toContain('Γιώργος Παπαδόπουλος');
})->group('fast');

it('shows a dash where the guest has not given a name yet', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->guests()->update(['full_name' => null]);
    });

    $html = Tenancy::forTenant($tenant, fn (): string => view('pdf.e-ticket', [
        'booking' => Booking::query()->findOrFail($booking->getKey())->load(['guests', 'product.meetingPoint', 'vessel']),
        'brand' => [],
    ])->render());

    // §2.5: `full_name` is null until the guest-details form is submitted. A
    // dash rather than a blank, so a crew member can tell "not supplied" from
    // a rendering fault.
    expect($html)->toContain('—');
})->group('fast');
