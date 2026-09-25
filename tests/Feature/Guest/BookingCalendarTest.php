<?php

declare(strict_types=1);

use App\Domain\Booking\Support\BookingCalendarInvite;
use App\Enums\BookingStatus;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| The trip in the guest's own calendar (2026-09-18)
|--------------------------------------------------------------------------
|
| Three properties carry this file, and they are the three a guest can be hurt
| by.
|
| **The entry starts at check-in.** A file that said 10:00 because the boat
| leaves at 10:00 is a guest watching it go from the quay. The assertion is on
| the instant, and it also asserts the departure instant is *not* there, so a
| later refactor cannot quietly make them the same again.
|
| **One booking is one entry, for ever.** The confirmation, a change and the
| day-before reminder all carry a calendar file; with a UID that moved, a guest
| would end up with three copies of the same morning. So the UID is asserted to
| be the booking's `uuid` and the `SEQUENCE` to rise when the trip changes,
| which is what makes the second file overwrite the first rather than be
| dropped as a duplicate.
|
| **The manage token never reaches Google.** It is a bearer credential. It
| belongs in a file on the guest's own device and not in a URL typed into
| somebody else's servers and stored on a shareable event.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Unfold an `.ics` back into whole values.
 *
 * iCalendar folds every line at 75 octets, so a 40-character token or a Greek
 * meeting point can be split across two lines with a leading space. Asserting
 * against the folded text would make these tests fail on a longer operator
 * name rather than on the behaviour they are about.
 */
function unfolded(string $ics): string
{
    return str_replace(["\r\n ", "\r\n\t"], '', $ics);
}

/**
 * A trip that checks in 45 minutes early, at a quay with coordinates.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function calendarBooking(int $checkInOffset = 45): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    $booking = Tenancy::forTenant($tenant, static function () use ($booking, $checkInOffset): Booking {
        $booking->loadMissing('product.meetingPoint');

        $booking->product->forceFill([
            'title' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset cruise'],
            'check_in_offset_minutes' => $checkInOffset,
        ])->save();

        $booking->product->meetingPoint->forceFill([
            'name' => ['el' => 'Λιμάνι Παροικιάς', 'en' => 'Parikia port'],
            'address' => 'Προκυμαία Παροικιάς, Πάρος 844 00',
            'lat' => 37.0853,
            'lng' => 25.1497,
        ])->save();

        $booking->forceFill([
            'locale' => 'el',
            // 07:00 UTC, which is 10:00 in Athens: check-in at 06:15 UTC.
            'starts_at_utc' => '2026-07-18 07:00:00',
            'ends_at_utc' => '2026-07-18 11:00:00',
        ])->save();

        return $booking->refresh();
    });

    return [$tenant, $booking];
}

it('serves the trip as a calendar file timed at check-in, not at departure', function (): void {
    [, $booking] = calendarBooking();

    $response = get('/b/' . $booking->manage_token . '/calendar.ics')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/calendar; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain(str((string) $booking->reference)->slug()->value() . '.ics')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $ics = unfolded($response->getContent());

    expect($ics)
        ->toContain('BEGIN:VCALENDAR')
        // PUBLISH, so no client draws accept/decline on a paid trip.
        ->toContain('METHOD:PUBLISH')
        // 07:00 UTC less the product's 45 minutes. The one fact a guest acts on.
        ->toContain('DTSTART:20260718T061500Z')
        ->toContain('DTEND:20260718T110000Z')
        ->toContain('Ηλιοβασίλεμα')
        ->toContain('Λιμάνι Παροικιάς')
        // The quay pins on a map, whatever its postal address claims.
        ->toContain('GEO:37.0853000;25.1497000')
        // The reminder the day before, for the guest who books in March.
        ->toContain('BEGIN:VALARM')
        ->toContain('TRIGGER:-P1D')
        ->toContain('STATUS:CONFIRMED')
        // The description spells out all three times in the operator's own
        // zone, so the 45-minute shift hides nothing.
        ->toContain('09:15')
        ->toContain('10:00')
        ->toContain('14:00')
        ->toContain((string) $booking->reference);

    // Asserted separately, because it is the mistake this whole class exists to
    // prevent: the departure instant must not be what the calendar is told.
    expect($ics)->not->toContain('DTSTART:20260718T070000Z');
})->group('fast');

it('keeps the whole booking to one entry, and overwrites it when the trip moves', function (): void {
    [$tenant, $booking] = calendarBooking();

    $first = unfolded(get('/b/' . $booking->manage_token . '/calendar.ics')->assertOk()->getContent());

    expect($first)->toContain('UID:booking-' . $booking->uuid . '@');

    preg_match('/SEQUENCE:(\d+)/', $first, $before);

    Carbon::setTestNow('2026-06-21 09:00:00');

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill([
            'starts_at_utc' => '2026-07-18 08:00:00',
            'ends_at_utc' => '2026-07-18 12:00:00',
        ])->save();
    });

    $second = unfolded(get('/b/' . $booking->manage_token . '/calendar.ics')->assertOk()->getContent());

    preg_match('/SEQUENCE:(\d+)/', $second, $after);

    expect($second)
        // Same identity …
        ->toContain('UID:booking-' . $booking->uuid . '@')
        // … new time, served from the route rather than from an old attachment.
        ->toContain('DTSTART:20260718T071500Z')
        ->and((int) $after[1])->toBeGreaterThan((int) $before[1]);
})->group('fast');

it('tells the calendar to drop a trip that was cancelled', function (): void {
    [$tenant, $booking] = calendarBooking();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();
    });

    $ics = unfolded(get('/b/' . $booking->manage_token . '/calendar.ics')->assertOk()->getContent());

    expect($ics)->toContain('STATUS:CANCELLED');

    // No alarm for a morning that is not happening.
    expect($ics)->not->toContain('BEGIN:VALARM');

    // And the page stops offering it, so nobody re-adds the trip from there.
    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee('/calendar.ics', escape: false);
})->group('fast');

it('offers nothing to diary for a booking that holds no ticket yet', function (BookingStatus $status): void {
    [$tenant, $booking] = calendarBooking();

    // A draft has a start time too, and «Προσθήκη στο ημερολόγιο» on one put a
    // trip nobody had paid for in the guest's diary (roadmap, 25/9).
    Tenancy::forTenant($tenant, static function () use ($booking, $status): void {
        $booking->forceFill(['status' => $status])->save();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee('/calendar.ics', escape: false)
        ->assertDontSee('calendar.google.com/calendar/render', escape: false);
})->with([BookingStatus::Draft, BookingStatus::PendingPayment])->group('fast');

it('offers both ways from the page, and sends the token to neither Google nor a referrer', function (): void {
    [$tenant, $booking] = calendarBooking();

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('/b/' . $booking->manage_token . '/calendar.ics', escape: false)
        ->assertSee('calendar.google.com/calendar/render', escape: false)
        ->assertSee('noreferrer', escape: false);

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $invite = BookingCalendarInvite::for($booking, 'el');

        expect($invite->googleUrl())
            // The credential never leaves the guest's own device.
            ->not->toContain((string) $booking->manage_token)
            ->and($invite->ics())
            // While the file, which does live on that device, carries the way back.
            ->toContain((string) $booking->manage_token);
    });
})->group('fast');

it('answers a token that is not a booking with the link-not-valid page', function (): void {
    get('/b/' . str_repeat('a', 40) . '/calendar.ics')->assertNotFound();
})->group('fast');

it('has nothing to diary for a booking with no departure', function (): void {
    [$tenant] = calendarBooking();

    Tenancy::forTenant($tenant, static function (): void {
        expect(BookingCalendarInvite::for(new Booking)->isAvailable())->toBeFalse();
    });
})->group('fast');

it('attaches the trip to the three emails that are the whole trip, and to nothing else', function (): void {
    [$tenant, $booking] = calendarBooking();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $invite = BookingCalendarInvite::for($booking, 'el');
        $ics = $invite->ics();
        $filename = $invite->filename();

        $carriers = [
            NotificationTemplate::BookingConfirmed,
            NotificationTemplate::BookingChanged,
            NotificationTemplate::PreDeparture24h,
        ];

        foreach ($carriers as $template) {
            (new GuestMail($booking, $template))->assertHasAttachedData(
                $ics,
                $filename,
                // The MIME type matters as much as the file: without
                // `method=PUBLISH` some clients read the part as a meeting
                // invitation and draw accept/decline on a paid trip.
                ['mime' => 'text/calendar; charset=UTF-8; method=PUBLISH'],
            );
        }

        // A balance reminder is not a statement about when to be somewhere, so
        // it does not offer the guest a second copy of a morning they already
        // have.
        expect((new GuestMail($booking, NotificationTemplate::BalanceDueReminder))->attachments())->toBe([]);
    });
})->group('fast');
