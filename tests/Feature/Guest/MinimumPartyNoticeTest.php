<?php

declare(strict_types=1);

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| «Πραγματοποιείται με τουλάχιστον Χ άτομα» (Mike, 2026-09-25)
|--------------------------------------------------------------------------
|
| The guest is told the minimum where they decide (trip page, checkout) and
| where they keep the booking (email, booking page). Once the departure has
| reached it, checkout says so instead. Never on a trip sold by the boat, and
| never for a minimum of one or less — see `MinimumParty`.
|
*/

const MIN_PAX_EL = 'Πραγματοποιείται με τουλάχιστον 8 άτομα. Αν δεν συμπληρωθούν, σας επιστρέφουμε όλα τα χρήματα.';
const GUARANTEED_EL = 'Η αναχώρηση είναι εγγυημένη.';

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-03 11:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A booking whose departure carries `min_pax` 8, in the state asked for.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function minimumPartyBooking(DepartureStatus $status, BookingStatus $bookingStatus = BookingStatus::Confirmed, int $minPax = 8): array
{
    [$tenant, $booking] = GuestPageScenario::booking();

    // Refreshed inside the tenancy: the loaded departure is reloaded with it.
    $booking = Tenancy::forTenant($tenant, static function () use ($booking, $status, $bookingStatus, $minPax): Booking {
        $booking->departure->forceFill(['min_pax' => $minPax, 'status' => $status])->save();
        $booking->forceFill([
            'status' => $bookingStatus,
            'locale' => 'el',
            'hold_expires_at' => $bookingStatus === BookingStatus::Draft ? now()->addMinutes(15) : null,
        ])->save();

        return $booking->refresh();
    });

    return [$tenant, $booking];
}

/** @return array{0: string, 1: string} the html and the text half */
function minimumPartyEmail(Tenant $tenant, Booking $booking): array
{
    return Tenancy::forTenant($tenant, static function () use ($booking): array {
        $mail = new GuestMail($booking, NotificationTemplate::BookingConfirmed);
        $html = $mail->render();

        app()->setLocale('el');
        $text = view((string) $mail->textView, [
            'booking' => $booking,
            'template' => NotificationTemplate::BookingConfirmed,
            'brand' => [],
            'extra' => [],
        ])->render();

        return [$html, $text];
    });
}

it('tells the guest the minimum on a per-seat trip page', function (): void {
    $tenant = OperatorPage::operator('minimum-trip');
    $product = TripPage::create($tenant, ['mode' => BookingMode::PerSeat, 'min_pax' => 8]);
    TripPage::departure($tenant, $product);

    get(TripPage::url($tenant, $product, 'el'))->assertOk()->assertSee(MIN_PAX_EL);
    get(TripPage::url($tenant, $product, 'en'))->assertOk()
        ->assertSee('Runs with at least 8 people. If not enough book, you get all your money back.');
});

it('says nothing on the trip page for a minimum of one or none', function (int $minPax): void {
    $tenant = OperatorPage::operator('no-minimum-' . $minPax);
    $product = TripPage::create($tenant, ['mode' => BookingMode::PerSeat, 'min_pax' => $minPax]);
    TripPage::departure($tenant, $product);

    get(TripPage::url($tenant, $product, 'el'))->assertOk()->assertDontSee('Πραγματοποιείται με τουλάχιστον');
})->with([0, 1]);

it('says nothing on the trip page of a boat sold whole', function (): void {
    $tenant = OperatorPage::operator('whole-boat');
    // Set straight on the row: `SaveProduct` would zero it, and this is the
    // page's own guard being tested.
    $product = TripPage::create($tenant, ['mode' => BookingMode::PerVessel, 'min_pax' => 8]);

    get(TripPage::url($tenant, $product, 'el'))->assertOk()->assertDontSee('Πραγματοποιείται με τουλάχιστον');
});

it('gives the minimum at checkout while the departure is not guaranteed', function (): void {
    [, $booking] = minimumPartyBooking(DepartureStatus::Scheduled, BookingStatus::Draft);

    get('/c/' . $booking->manage_token)->assertOk()
        ->assertSee(MIN_PAX_EL)
        ->assertDontSee(GUARANTEED_EL);
});

it('says the departure is guaranteed at checkout once it is', function (): void {
    [, $booking] = minimumPartyBooking(DepartureStatus::Guaranteed, BookingStatus::Draft);

    get('/c/' . $booking->manage_token)->assertOk()
        ->assertSee(GUARANTEED_EL)
        ->assertDontSee('Πραγματοποιείται με τουλάχιστον');
});

it('says neither at checkout for a minimum of one', function (): void {
    [, $booking] = minimumPartyBooking(DepartureStatus::Guaranteed, BookingStatus::Draft, minPax: 1);

    get('/c/' . $booking->manage_token)->assertOk()
        ->assertDontSee(GUARANTEED_EL)
        ->assertDontSee('Πραγματοποιείται με τουλάχιστον');
});

it('carries the minimum in the confirmation email and on the booking page until it is reached', function (): void {
    [$tenant, $booking] = minimumPartyBooking(DepartureStatus::Scheduled);

    [$html, $text] = minimumPartyEmail($tenant, $booking);

    expect($html)->toContain(MIN_PAX_EL)
        ->and($text)->toContain(MIN_PAX_EL);

    get('/b/' . $booking->manage_token)->assertOk()->assertSee(MIN_PAX_EL);
});

it('drops the minimum from the email and the booking page once guaranteed', function (): void {
    [$tenant, $booking] = minimumPartyBooking(DepartureStatus::Guaranteed);

    [$html, $text] = minimumPartyEmail($tenant, $booking);

    expect($html)->not->toContain('Πραγματοποιείται με τουλάχιστον')
        ->and($text)->not->toContain('Πραγματοποιείται με τουλάχιστον');

    get('/b/' . $booking->manage_token)->assertOk()->assertDontSee('Πραγματοποιείται με τουλάχιστον');
});
