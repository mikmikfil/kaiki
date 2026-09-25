<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Booking\Actions\ExpireStaleHolds;
use App\Domain\Booking\Actions\RemoveGuestsFromBooking;
use App\Domain\Booking\Actions\StartCheckout;
use App\Domain\Pricing\Actions\ApplyDiscountCode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutRefused;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\DiscountCode;
use App\Models\Payment;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The line money crosses, asked again (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| - AVL-19 at checkout and at the resume link, not only at `POST /bookings`.
| - The status re-read under the booking's lock: a double tap does not sell
|   the seats twice, and the hold sweeper does not expire a checkout in flight.
| - A total that moves under an open card page withdraws that page; the next
|   press mints one at the right amount, and money paid on top goes back.
|
*/

beforeEach(function (): void {
    // The factory's sailing is 2026-07-04 06:00 UTC.
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Departure, 2: Booking} */
function gateScenario(int $total = 12000): array
{
    $tenant = Tenant::factory()->create();

    [$departure, $booking] = Tenancy::forTenant($tenant, static function () use ($total): array {
        $departure = Departure::factory()->create(['capacity' => 10, 'seats_sold' => 0, 'seats_held' => 0, 'min_pax' => 0]);

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

        $departure->forceFill(['seats_held' => 2])->save();

        return [$departure, $booking];
    });

    return [$tenant, $departure, $booking];
}

it('refuses to check out a sailing that has already left', function (): void {
    [$tenant, $departure, $booking] = gateScenario();

    Carbon::setTestNow('2026-07-04 07:00:00');

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        expect(fn () => app(StartCheckout::class)($booking))->toThrow(CheckoutRefused::class);

        expect($departure->refresh()->seats_sold)->toBe(0)
            ->and(Payment::query()->where('booking_id', $booking->getKey())->exists())->toBeFalse();
    });
})->group('fast');

it('refuses a guest\'s draft inside the lead time, as the calendar would', function (): void {
    [$tenant, , $booking] = gateScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        RatePlan::factory()->create(['product_id' => $booking->product_id, 'min_lead_time_hours' => 48]);

        // A day and a half before the sailing, with a 48-hour lead time.
        Carbon::setTestNow('2026-07-02 18:00:00');

        $refused = null;

        try {
            app(StartCheckout::class)($booking);
        } catch (CheckoutRefused $exception) {
            $refused = $exception;
        }

        expect($refused?->errorCode)->toBe('lead_time_too_short');
    });
})->group('fast');

it('does not restart an abandoned checkout for a sailing that has left', function (): void {
    [$tenant, , $booking] = gateScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Expired,
            'cancel_reason' => CancelReason::PaymentFailed,
            'hold_expires_at' => null,
        ])->save();
    });

    Carbon::setTestNow('2026-07-04 08:00:00');

    get('/c/' . $booking->manage_token)
        ->assertRedirect(route('guest.booking', ['token' => $booking->manage_token]))
        ->assertSessionHas('resume_refused');

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(Booking::query()->whereKeyNot($booking->getKey())->exists())->toBeFalse();
    });
})->group('fast');

it('gives a charter back its hold after a declined card, so the hold sweeper can end it', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create();

        $booking = Booking::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::PendingPayment,
            'confirmed_at' => null,
            'paid_cents' => 0,
            'balance_cents' => 12000,
            'hold_expires_at' => null,
        ]);

        $payment = Payment::factory()->pending()->create(['booking_id' => $booking->getKey(), 'amount_cents' => 12000]);

        app(ConfirmFromWebhook::class)($payment, succeeded: false);

        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Draft)
            ->and($booking->hold_expires_at)->not->toBeNull();

        Carbon::setTestNow(now()->addHour());

        app(ExpireStaleHolds::class)();

        expect($booking->refresh()->status)->toBe(BookingStatus::Expired);
    });
})->group('fast');

it('sells the seats once when «Πληρωμή» is pressed twice, and leaves one live order', function (): void {
    [$tenant, $departure, $booking] = gateScenario();

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        // Both requests read the draft before either took the lock.
        $first = app(StartCheckout::class)(clone $booking);
        $second = app(StartCheckout::class)(clone $booking);

        expect($departure->refresh()->seats_sold)->toBe(2)
            ->and($first['payment']?->refresh()->status)->toBe(PaymentStatus::Cancelled)
            ->and($second['payment']?->status)->toBe(PaymentStatus::Pending)
            ->and(Payment::query()->where('booking_id', $booking->getKey())->open()->count())->toBe(1);
    });
})->group('fast');

it('does not let the hold sweeper expire a draft that reached the gateway after it was read', function (): void {
    [$tenant, $departure, $booking] = gateScenario();

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        $booking->forceFill(['hold_expires_at' => now()->subMinute()])->save();

        // The sweeper's read, taken before the guest pressed pay.
        $stale = Booking::query()->findOrFail($booking->getKey());

        $booking->forceFill(['hold_expires_at' => now()->addMinutes(10)])->save();
        app(StartCheckout::class)($booking->refresh());

        $expire = new ReflectionMethod(ExpireStaleHolds::class, 'expire');

        expect($expire->invoke(app(ExpireStaleHolds::class), $stale))->toBeFalse()
            ->and($booking->refresh()->status)->toBe(BookingStatus::PendingPayment)
            ->and($departure->refresh()->seats_sold)->toBe(2);
    });
})->group('fast');

it('withdraws the open order when a code lowers the total, and mints the next at the new amount', function (): void {
    Queue::fake();

    [$tenant, $departure, $booking] = gateScenario(total: 10000);

    Tenancy::forTenant($tenant, function () use ($departure, $booking): void {
        $old = app(StartCheckout::class)($booking)['payment'];

        // Back from Viva, a 50% code.
        $code = DiscountCode::factory()->create(['value' => 50]);
        app(ApplyDiscountCode::class)($booking->refresh(), $code->code);

        expect($old?->refresh()->status)->toBe(PaymentStatus::Cancelled);

        $new = app(StartCheckout::class)($booking->refresh())['payment'];

        expect($new?->amount_cents)->toBe(5000)
            // The seats were sold at the first press and are not sold again.
            ->and($departure->refresh()->seats_sold)->toBe(2);

        // The guest pays the old €100 tab anyway: confirmed, and €50 goes back.
        app(ConfirmFromWebhook::class)($old->refresh(), succeeded: true);

        $booking->refresh();

        $refund = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', PaymentKind::Refund->value)
            ->sole();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($refund->amount_cents)->toBe(5000)
            ->and($refund->refunds_payment_id)->toBe($old->getKey());

        Queue::assertPushed(ExecuteGatewayRefund::class);
    });
})->group('fast');

it('ignores a failure from an order that a newer one replaced', function (): void {
    [$tenant, , $booking] = gateScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $old = app(StartCheckout::class)($booking)['payment'];
        app(StartCheckout::class)($booking->refresh());

        app(ConfirmFromWebhook::class)($old?->refresh() ?? throw new RuntimeException, succeeded: false);

        expect($booking->refresh()->status)->toBe(BookingStatus::PendingPayment);
    });
})->group('fast');

it('withdraws the open order when the operator takes guests off a booking at the gateway', function (): void {
    [$tenant, , $booking] = gateScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'pax_breakdown' => [['code' => 'adult', 'qty' => 2, 'unit_price_cents' => 6000, 'total_cents' => 12000, 'counts_toward_capacity' => true]],
        ])->save();

        $open = app(StartCheckout::class)($booking)['payment'];

        app(RemoveGuestsFromBooking::class)($booking->refresh(), ['adult' => 1]);

        expect($booking->refresh()->total_cents)->toBe(6000)
            ->and($open?->refresh()->status)->toBe(PaymentStatus::Cancelled);

        expect(app(StartCheckout::class)($booking)['payment']?->amount_cents)->toBe(6000);
    });
})->group('fast');
