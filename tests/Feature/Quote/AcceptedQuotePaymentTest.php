<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\AcceptQuote;
use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\ExpireAbandonedCheckouts;
use App\Domain\Booking\Actions\SendQuote;
use App\Domain\Booking\Actions\StartCheckout;
use App\Domain\Booking\Support\QuotePaymentDeadline;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Exceptions\CheckoutRefused;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\QuoteScenario;

/*
|--------------------------------------------------------------------------
| An accepted quote is a sale waiting for money (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| `AcceptQuote` leaves the booking in `pending_payment` with no card page. The
| abandoned-checkout sweeper used to expire it an hour later as a failed
| payment, and the checkout refused it outright. It now stays payable until its
| own deadline — never past the trip's start — and a quote can be neither
| accepted nor paid once the trip has started.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    // The factory's charter starts 2026-07-04 06:00 UTC.
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Booking, 2: Quote} */
function acceptedQuote(): array
{
    [$tenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 95000);

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote);
        app(AcceptQuote::class)($quote->refresh());
    });

    return [$tenant, $booking->refresh(), $quote->refresh()];
}

it('keeps an accepted quote payable past the checkout sweeper\'s hour', function (): void {
    [$tenant, $booking] = acceptedQuote();

    Carbon::setTestNow(now()->addHours(3));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(app(ExpireAbandonedCheckouts::class)())->toBe(0)
            ->and($booking->refresh()->status)->toBe(BookingStatus::PendingPayment);

        // And the checkout takes it: a card page for the quote's total.
        $result = app(StartCheckout::class)($booking);

        expect($result['payment']?->amount_cents)->toBe(95000)
            ->and($result['payment']?->status)->toBe(PaymentStatus::Pending)
            ->and($result['booking']->status)->toBe(BookingStatus::PendingPayment);
    });
})->group('fast');

it('lapses an unpaid accepted quote at its deadline, as a hold and not a failed payment', function (): void {
    [$tenant, $booking] = acceptedQuote();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $deadline = QuotePaymentDeadline::for($booking);

        expect($deadline)->not->toBeNull();

        Carbon::setTestNow($deadline?->copy()->addMinute());

        expect(app(ExpireAbandonedCheckouts::class)())->toBe(1);

        $booking->refresh();

        // Not `payment_failed`: that reason sends «δεν ολοκληρώθηκε» and
        // offers a fresh draft, neither of which means anything for a quote.
        expect($booking->status)->toBe(BookingStatus::Expired)
            ->and($booking->cancel_reason)->toBe(CancelReason::HoldExpired);
    });
})->group('fast');

it('gives the guest the later of the quote\'s validity and three days, never past the start', function (): void {
    [$tenant, $booking, $quote] = acceptedQuote();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        // Sent today with the seven-day default, accepted today: the validity wins.
        expect(QuotePaymentDeadline::for($booking)?->toIso8601String())
            ->toBe($quote->valid_until->toIso8601String());

        // Validity already nearly over: three days from acceptance.
        $quote->forceFill(['valid_until' => now()->addHour()])->save();

        expect(QuotePaymentDeadline::for($booking)?->toIso8601String())
            ->toBe(now()->addDays(3)->toIso8601String());

        // A trip two days out caps both.
        $booking->forceFill(['starts_at_utc' => now()->addDays(2), 'ends_at_utc' => now()->addDays(2)->addHours(8)])->save();

        expect(QuotePaymentDeadline::for($booking)?->toIso8601String())
            ->toBe(now()->addDays(2)->toIso8601String());
    });
})->group('fast');

it('refuses to take money for an accepted quote once the trip has started', function (): void {
    [$tenant, $booking] = acceptedQuote();

    Carbon::setTestNow('2026-07-04 07:00:00');

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(StartCheckout::class)($booking->refresh()))->toThrow(CheckoutRefused::class);

        expect(Payment::query()->where('booking_id', $booking->getKey())->exists())->toBeFalse();
    });
})->group('fast');

it('refuses to accept a quote once the trip has started', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote);
        // An operator stretched the validity past the trip by hand.
        $quote->refresh()->forceFill(['valid_until' => Carbon::parse('2026-07-10 00:00:00')])->save();
    });

    Carbon::setTestNow('2026-07-05 09:00:00');

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        expect(fn () => app(AcceptQuote::class)($quote->refresh()))
            ->toThrow(RuntimeException::class, 'trip_started');

        expect($quote->refresh()->status)->toBe(QuoteStatus::Sent)
            ->and($booking->refresh()->status)->toBe(BookingStatus::QuoteSent);
    });

    // And the page offers no button, and says why when one is pressed.
    get('/q/' . $quote->quote_token)
        ->assertOk()
        ->assertDontSee(__('guest.quote.accept'));

    post('/q/' . $quote->quote_token . '/accept')
        ->assertRedirect(route('guest.quote', ['token' => $quote->quote_token]))
        ->assertSessionHas('quote_error', 'trip_started');
})->group('fast');

it('never leaves a quote open past the trip\'s start', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        // A charter in three days: the seven-day default stops at the start.
        $booking->forceFill(['starts_at_utc' => now()->addDays(3), 'ends_at_utc' => now()->addDays(3)->addHours(8)])->save();

        $fresh = app(BuildQuote::class)($booking->refresh());

        expect($fresh->valid_until->toIso8601String())->toBe(now()->addDays(3)->toIso8601String());

        // And a date typed past it is brought back at sending.
        $quote->forceFill(['valid_until' => now()->addDays(10)])->save();

        $sent = app(SendQuote::class)($quote->refresh());

        expect($sent->valid_until->toIso8601String())->toBe(now()->addDays(3)->toIso8601String());
    });
})->group('fast');

it('takes the guest from «Αποδοχή και πληρωμή» straight to the checkout, and keeps the link on the quote page', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, fn () => app(SendQuote::class)($quote));

    post('/q/' . $quote->quote_token . '/accept')
        ->assertRedirect(route('guest.checkout', ['token' => $booking->manage_token]));

    get('/q/' . $quote->quote_token)
        ->assertOk()
        ->assertSee(route('guest.checkout', ['token' => $booking->manage_token]), false)
        ->assertSee(__('guest.quote.pay'));

    // The checkout page itself renders for it rather than bouncing.
    get('/c/' . $booking->manage_token)->assertOk();
})->group('fast');
