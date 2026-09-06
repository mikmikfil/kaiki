<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Actions\ExpireAbandonedCheckouts;
use App\Domain\Booking\Actions\StartCheckout;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| PRC-26, PAY-10, BKG-9, BKG-10, BKG-19
|--------------------------------------------------------------------------
|
| PRC-26 is a one-line invariant with a nightly integrity check behind it:
|
|     paid_cents + balance_cents = total_cents
|
| for every booking in `confirmed`, `checked_in` or `completed`. It is the sum
| an operator reconciles against their bank, and a booking that fails it is one
| nobody can answer a question about.
|
| PAY-10 is how it stays true: `paid_cents` is **recomputed from `Payment`
| rows** inside the same transaction, never incremented. An increment is only
| correct if every previous one was.
|
*/

/** @return array{0: Tenant, 1: Departure, 2: Booking} */
function paymentScenario(int $total = 12000, int $capacity = 10): array
{
    $tenant = Tenant::factory()->create();

    [$departure, $booking] = Tenancy::forTenant($tenant, static function () use ($total, $capacity): array {
        $departure = Departure::factory()->create([
            'capacity' => $capacity,
            'seats_sold' => 0,
            'seats_held' => 0,
            'min_pax' => 0,
        ]);

        $booking = Booking::factory()
            ->forDeparture($departure)
            ->withPax(2, 2)
            ->holding($departure)
            ->create([
                'subtotal_cents' => $total,
                'extras_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => $total,
                'deposit_cents' => 0,
                'paid_cents' => 0,
                'balance_cents' => $total,
            ]);

        return [$departure, $booking];
    });

    return [$tenant, $departure, $booking];
}

/** PRC-26, asserted directly. */
function assertPaidPlusBalanceIsTotal(Booking $booking): void
{
    $booking->refresh();

    expect($booking->paid_cents + $booking->balance_cents)->toBe(
        $booking->total_cents,
        'PRC-26 broken: paid + balance no longer equals total',
    );
}

it('recomputes paid_cents from the payment rows rather than trusting the column', function (): void {
    [$tenant, , $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Payment::factory()->create(['booking_id' => $booking->getKey(), 'amount_cents' => 12000]);

        // A column that has drifted — a half-finished write, a bad import, a
        // previous increment that ran twice. The recomputation is right
        // whatever happened before it.
        $booking->forceFill(['paid_cents' => 999999])->save();

        app(ConfirmBooking::class)($booking);

        expect($booking->refresh()->paid_cents)->toBe(12000);

        assertPaidPlusBalanceIsTotal($booking);
    });
})->group('fast');

it('nets a refund out of paid_cents', function (): void {
    [$tenant, , $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $charge = Payment::factory()->create(['booking_id' => $booking->getKey(), 'amount_cents' => 12000]);

        Payment::factory()->refundOf($charge, 4000)->create();

        app(ConfirmBooking::class)($booking);

        $booking->refresh();

        // PAY-10: succeeded non-refunds minus succeeded refunds.
        expect($booking->paid_cents)->toBe(8000)
            ->and($booking->refunded_cents)->toBe(4000);

        assertPaidPlusBalanceIsTotal($booking);
    });
})->group('fast');

it('ignores a payment that has not succeeded', function (): void {
    [$tenant, , $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Payment::factory()->pending()->create(['booking_id' => $booking->getKey(), 'amount_cents' => 12000]);

        app(ConfirmBooking::class)($booking);

        // A `pending` row means a guest has *started* to pay. Counting it would
        // mark a booking settled on the strength of a redirect.
        expect($booking->refresh()->paid_cents)->toBe(0)
            ->and($booking->balance_cents)->toBe(12000);

        assertPaidPlusBalanceIsTotal($booking);
    });
})->group('fast');

it('moves the seats from held to sold at checkout, not at the webhook', function (): void {
    [$tenant, $departure, $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        app(HoldSeats::class)($booking, $departure);

        expect($departure->refresh()->seats_held)->toBe(2)
            ->and($departure->seats_sold)->toBe(0);

        $result = app(StartCheckout::class)($booking);

        $departure->refresh();

        // BKG-9, marked RESOLVED: committing at redirect closes the window
        // where a guest on the gateway page loses the seat they are paying for.
        expect($departure->seats_held)->toBe(0)
            ->and($departure->seats_sold)->toBe(2)
            ->and($result['booking']->status)->toBe(BookingStatus::PendingPayment)
            ->and($result['booking']->hold_expires_at)->toBeNull();

        // PAY-9: minted before anything is called, and unique per tenant.
        expect($result['payment']?->status)->toBe(PaymentStatus::Pending)
            ->and($result['payment']?->idempotency_key)->not->toBeEmpty();
    });
})->group('fast');

it('creates a deposit payment when the rate plan asked for one', function (): void {
    [$tenant, , $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['deposit_cents' => 3000])->save();

        $result = app(StartCheckout::class)($booking);

        // The balance is a **second session**, minted on demand months later
        // (ADR-0004 Option D) — not a second row created now for money nobody
        // has been asked for yet.
        expect($result['payment']?->kind)->toBe(PaymentKind::Deposit)
            ->and($result['payment']?->amount_cents)->toBe(3000)
            ->and(Payment::query()->where('booking_id', $booking->getKey())->count())->toBe(1);
    });
})->group('fast');

it('skips the gateway entirely when a voucher covers the whole total', function (): void {
    // BKG-19 and PRC-22. Sending a guest to a payment page for €0.00 produces
    // a checkout that cannot complete.
    [$tenant, $departure, $booking] = paymentScenario(total: 5000);

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        $voucher = Voucher::factory()->create(['amount_cents' => 5000, 'remaining_cents' => 5000]);

        $booking->forceFill(['voucher_id' => $voucher->getKey()])->save();

        $result = app(StartCheckout::class)($booking);

        expect($result['payment'])->toBeNull()
            ->and($result['booking']->status)->toBe(BookingStatus::Confirmed)
            ->and($result['booking']->total_cents)->toBe(0)
            // The seats still moved, and exactly once — confirmation must not
            // commit them a second time on this path.
            ->and($departure->refresh()->seats_sold)->toBe(2);

        assertPaidPlusBalanceIsTotal($result['booking']);
    });
})->group('fast');

it('gives the seats back when a guest abandons the gateway page', function (): void {
    [$tenant, $departure, $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(StartCheckout::class)($booking);
    });

    // BKG-10: sixty minutes total, and the seats are in `seats_sold` where no
    // read-side rule can infer that the guest left.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh()->forceFill(['updated_at' => now()->subMinutes(90)])->saveQuietly();
    });

    expect(app(ExpireAbandonedCheckouts::class)())->toBe(1);

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Expired)
            ->and($departure->refresh()->seats_sold)->toBe(0)
            // The open payment goes with it, rather than sitting in the
            // operator's stuck-payment feed competing with real ones.
            ->and(Payment::query()->where('booking_id', $booking->getKey())->open()->count())->toBe(0);
    });
})->group('fast');

it('is idempotent, so a retried sweep does not release the seats twice', function (): void {
    [$tenant, $departure, $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(StartCheckout::class)($booking);
        $booking->refresh()->forceFill(['updated_at' => now()->subMinutes(90)])->saveQuietly();
    });

    app(ExpireAbandonedCheckouts::class)();

    // BKG-10 asks for it outright, and a scheduler retries.
    expect(app(ExpireAbandonedCheckouts::class)())->toBe(0);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        expect($departure->refresh()->seats_sold)->toBe(0);
    });
})->group('fast');

it('never expires a booking that was confirmed a second earlier', function (): void {
    [$tenant, $departure, $booking] = paymentScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(StartCheckout::class)($booking);

        Payment::factory()->create(['booking_id' => $booking->getKey(), 'amount_cents' => 12000]);

        app(ConfirmBooking::class)($booking->refresh(), fromCheckout: true);

        $booking->refresh()->forceFill(['updated_at' => now()->subMinutes(90)])->saveQuietly();
    });

    // The status is re-checked under the row lock rather than in the query that
    // selected it, so a webhook that landed a moment before the sweep wins.
    expect(app(ExpireAbandonedCheckouts::class)())->toBe(0);

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($departure->refresh()->seats_sold)->toBe(2);

        assertPaidPlusBalanceIsTotal($booking);
    });
})->group('fast');
