<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\UpdateDeparture;
use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Booking\Actions\StartCheckout;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\HoldRefused;
use App\Exceptions\IllegalStateTransition;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| Audit 2: seats taken without a live hold ask what a new hold asks
|--------------------------------------------------------------------------
|
| A payment landing after the checkout lapsed, a checkout pressed after the
| hold ran out, a confirmation racing the sweeper: each took seats on the
| strength of a seat count alone. The boat, the block and the certificate
| are asked now too.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 10:00:00');
    Queue::fake([ExecuteGatewayRefund::class]);
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * The checkout lapsed and the sweeper released its seats: expired, nothing
 * committed on the sailing.
 */
function expireForLatePayment(Tenant $tenant, Booking $booking): void
{
    Tenancy::forTenant($tenant, function () use ($booking): void {
        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => 0]);
        Payment::query()->update(['status' => PaymentStatus::Cancelled->value]);
        $booking->forceFill([
            'status' => BookingStatus::Expired,
            'cancel_reason' => CancelReason::PaymentFailed,
            'hold_expires_at' => null,
        ])->save();
    });
}

function lateRefundFor(Tenant $tenant, Booking $booking): ?Payment
{
    return Tenancy::forTenant($tenant, fn (): ?Payment => Payment::query()
        ->where('booking_id', $booking->getKey())
        ->where('kind', PaymentKind::Refund->value)
        ->first());
}

it('revives a late payment when the boat is still free', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();
    expireForLatePayment($tenant, $booking);

    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(2);
    });
})->group('fast');

it('refunds a late payment when the boat was chartered since the checkout lapsed', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();
    expireForLatePayment($tenant, $booking);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Booking::factory()->perVessel()->create([
            'product_id' => $booking->product_id,
            'vessel_id' => $booking->vessel_id,
            'status' => BookingStatus::Confirmed,
            'local_date' => $booking->local_date->toDateString(),
            'local_time' => (string) $booking->local_time,
            'starts_at_utc' => $booking->starts_at_utc,
            'ends_at_utc' => $booking->ends_at_utc,
        ]);
    });

    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Expired)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(0);
    });

    expect(lateRefundFor($tenant, $booking)?->amount_cents)->toBe(12000);
})->group('fast');

it('refunds a late payment onto a sailing blocked since the checkout lapsed', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();
    expireForLatePayment($tenant, $booking);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        VesselBlock::factory()->create([
            'vessel_id' => $booking->vessel_id,
            'starts_at_utc' => $booking->starts_at_utc->copy()->subHour(),
            'ends_at_utc' => $booking->ends_at_utc->copy()->addHour(),
            'local_date' => $booking->local_date->toDateString(),
            'local_end_date' => $booking->local_date->toDateString(),
        ]);
    });

    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Expired);
    });

    expect(lateRefundFor($tenant, $booking)?->amount_cents)->toBe(12000);
})->group('fast');

it('refunds a late payment that would put the boat over its certificate', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();
    expireForLatePayment($tenant, $booking);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $departure = Departure::query()->findOrFail($booking->departure_id);
        Vessel::query()->whereKey($departure->vessel_id)->update(['capacity_max' => 6]);

        // Two seats and six people: two adults, four infants. The boat is full
        // by its certificate though only two of ten seats are sold.
        Booking::factory()->forDeparture($departure)->withPax(2, 6)->create(['status' => BookingStatus::Confirmed]);
        $departure->forceFill(['seats_sold' => 2])->save();
    });

    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Expired)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(2);
    });

    expect(lateRefundFor($tenant, $booking)?->amount_cents)->toBe(12000);
})->group('fast');

it('refuses to confirm a booking the sweeper expired after the caller read it', function (): void {
    [$tenant, $booking] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // The caller's copy still says `pending_payment`.
        $stale = Booking::query()->findOrFail($booking->getKey());

        Booking::query()->whereKey($booking->getKey())->update([
            'status' => BookingStatus::Expired->value,
            'cancel_reason' => CancelReason::PaymentFailed->value,
        ]);
        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => 0]);

        expect(fn () => app(ConfirmBooking::class)($stale, fromCheckout: true))
            ->toThrow(IllegalStateTransition::class);

        expect(Booking::query()->findOrFail($booking->getKey())->status)->toBe(BookingStatus::Expired);
    });
})->group('fast');

it('refuses to confirm a booking cancelled after the caller read it', function (): void {
    [$tenant, $booking] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $stale = Booking::query()->findOrFail($booking->getKey());

        Booking::query()->whereKey($booking->getKey())->update(['status' => BookingStatus::Cancelled->value]);

        expect(fn () => app(ConfirmBooking::class)($stale, fromCheckout: true))
            ->toThrow(IllegalStateTransition::class);
    });
})->group('fast');

it('commits the seats when the booking went back to a draft after the caller read it', function (): void {
    [$tenant, $booking] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $stale = Booking::query()->findOrFail($booking->getKey());

        // BKG-12 put it back to a holding draft: its seats are held, not sold.
        Booking::query()->whereKey($booking->getKey())->update([
            'status' => BookingStatus::Draft->value,
            'hold_expires_at' => now()->addMinutes(10),
        ]);
        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => 0, 'seats_held' => 2]);

        app(ConfirmBooking::class)($stale, fromCheckout: true);

        expect(Booking::query()->findOrFail($booking->getKey())->status)->toBe(BookingStatus::Confirmed)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(2);
    });
})->group('fast');

it('refuses a checkout on a lapsed hold that would put the boat over its certificate', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->capacity(6)->create();
        $departure = Departure::factory()->create(['vessel_id' => $vessel->getKey(), 'capacity' => 10]);

        // The certificate filled with infants while this guest's hold lapsed.
        Booking::factory()->forDeparture($departure)->withPax(2, 6)->create(['status' => BookingStatus::Confirmed]);
        $departure->forceFill(['seats_sold' => 2])->save();

        $lapsed = Booking::factory()->heldButExpired($departure)->forDeparture($departure)->withPax(2, 2)->create();

        expect(fn () => app(StartCheckout::class)($lapsed))
            ->toThrow(HoldRefused::class, (string) __('booking.hold.legal_capacity'));

        expect($departure->refresh()->seats_sold)->toBe(2)
            ->and($lapsed->refresh()->status)->toBe(BookingStatus::Draft);
    });
})->group('fast');

it('still takes a checkout on a lapsed hold when the boat has room', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->capacity(20)->create();
        $departure = Departure::factory()->create(['vessel_id' => $vessel->getKey(), 'capacity' => 10]);

        $lapsed = Booking::factory()->heldButExpired($departure)->forDeparture($departure)->withPax(2, 2)->create();

        app(StartCheckout::class)($lapsed);

        expect($lapsed->refresh()->status)->toBe(BookingStatus::PendingPayment)
            ->and($departure->refresh()->seats_sold)->toBe(2);
    });
})->group('fast');

it('refuses to lower a departure below its sold and held seats', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 20, 'seats_sold' => 10, 'seats_held' => 4]);
        Booking::factory()->holding($departure)->forDeparture($departure)->withPax(4, 4)->create([
            'mode' => BookingMode::PerSeat,
        ]);

        expect(fn () => app(UpdateDeparture::class)($departure, ['capacity' => 10]))
            ->toThrow(ValidationException::class);

        expect($departure->refresh()->capacity)->toBe(20);

        // Down to what is promised is fine.
        app(UpdateDeparture::class)($departure, ['capacity' => 14]);

        expect($departure->refresh()->capacity)->toBe(14);
    });
})->group('fast');

it('lowers a departure to its sold seats once the holds have lapsed', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 20, 'seats_sold' => 10, 'seats_held' => 4]);
        Booking::factory()->heldButExpired($departure)->forDeparture($departure)->withPax(4, 4)->create();

        app(UpdateDeparture::class)($departure, ['capacity' => 10]);

        expect($departure->refresh()->capacity)->toBe(10);
    });
})->group('fast');
