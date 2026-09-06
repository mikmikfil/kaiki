<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ApplyGuestChoice;
use App\Domain\Booking\Actions\CancelDeparture;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DepartureCancelReason;
use App\Enums\PaymentKind;
use App\Enums\VoucherReason;
use App\Enums\WeatherChoice;
use App\Events\WeatherChoiceApplied;
use App\Events\WeatherChoiceReminderDue;
use App\Events\WeatherChoiceRequested;
use App\Jobs\ApplyWeatherChoiceDefaults;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| CXL-6, CXL-7: the weather, and the guest who never answers
|--------------------------------------------------------------------------
|
| CXL-7 is marked RESOLVED with its own reason: *"the brief leaves the
| no-response case undefined and it must not strand money indefinitely."* A
| guest who never opens the email otherwise leaves an operator holding money
| that is not theirs on a booking nobody will ever close.
|
| The half most likely to be got wrong is CXL-6's: the percentage comes from
| **each booking's own snapshot**. Two guests on the same cancelled sailing who
| booked in different months, under a policy the operator has since edited, are
| owed different proportions — and the natural implementation reads the
| product's current policy once and applies it to everybody.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    CancellationScenario::fakeGatewayResponses();

    Carbon::setTestNow('2026-06-20 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('applies each booking own snapshot, not one figure for the boat', function (): void {
    Event::fake([WeatherChoiceRequested::class]);

    [$tenant, $generous] = CancellationScenario::make(paidCents: 10000, weatherRefundPercent: 100);

    $departureId = $generous->departure_id;

    Tenancy::forTenant($tenant, function () use ($generous, $departureId): void {
        // A second guest on the same sailing whose frozen snapshot says 50% —
        // booked under an older, meaner policy. A workflow that read the
        // product's current policy would give them both the same figure.
        $snapshot = $generous->policy_snapshot;
        $snapshot['weather_refund_percent'] = 50;

        $mean = Booking::factory()
            ->forDeparture(Departure::query()->findOrFail($departureId))
            ->withPax(2, 2)
            ->create([
                'status' => BookingStatus::Confirmed,
                'total_cents' => 10000,
                'paid_cents' => 10000,
                'balance_cents' => 0,
                'policy_snapshot' => $snapshot,
            ]);

        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($departureId),
            reason: DepartureCancelReason::Weather,
            note: 'Force 8 forecast',
        );

        $entitlements = [];

        Event::assertDispatched(
            WeatherChoiceRequested::class,
            function (WeatherChoiceRequested $event) use (&$entitlements): bool {
                $entitlements[$event->bookingId] = $event->entitlementCents;

                return true;
            },
        );

        expect($entitlements[$generous->getKey()])->toBe(10000)
            ->and($entitlements[$mean->getKey()])->toBe(5000);
    });
})->group('fast');

it('cancels the trip without moving any money yet', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        $cancelled = $booking->refresh();

        // The trip is off and the seats are back (CXL-9) — but the entitlement
        // is waiting on the guest's answer, not sitting in a refund row. This
        // is the whole difference between the weather path and every other one.
        expect($cancelled->status)->toBe(BookingStatus::Cancelled)
            ->and($cancelled->cancel_reason)->toBe(CancelReason::Weather)
            ->and($cancelled->weather_choice_due_at?->toDateString())
            ->toBe(now()->addDays(14)->toDateString())
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0)
            ->and($cancelled->paid_cents)->toBe(10000);
    });
})->group('fast');

it('refunds the cash when the guest asks for their money back', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Refund, ip: '198.51.100.7');

        $chosen = $booking->refresh();

        // CXL-7's evidence: the fact is the timestamp, the address is what makes
        // it investigable. Never a guest's name — this row is kept for years.
        expect($chosen->weather_choice)->toBe(WeatherChoice::Refund)
            ->and($chosen->weather_choice_at)->not->toBeNull()
            ->and($chosen->weather_choice_ip)->toBe('198.51.100.7')
            ->and($chosen->refunded_cents)->toBe(10000);
    });
})->group('fast');

it('issues a voucher valid for the snapshot force-majeure months', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Voucher);

        $voucher = Voucher::query()->where('issued_for_booking_id', $booking->getKey())->sole();

        // CXL-8, from the booking's own frozen snapshot — eighteen months, not
        // the twelve a stray literal in `RestoreVoucher` used to produce.
        expect($voucher->amount_cents)->toBe(10000)
            ->and($voucher->reason)->toBe(VoucherReason::OperatorCancellation)
            ->and($voucher->expires_at?->toDateString())->toBe(now()->addMonths(18)->toDateString())
            // And no cash moved: the operator keeps the money against a future
            // trip, which is what the guest chose.
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0);
    });
})->group('fast');

it('treats rebook as credit too, because there is no seat-transfer flow', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Rebook);

        // A voucher is the only mechanism that carries a guest's money to a new
        // booking. What `rebook` adds is intent — recorded distinctly, so an
        // operator's list can tell "coming back" from "took the credit".
        expect(Voucher::query()->where('issued_for_booking_id', $booking->getKey())->count())->toBe(1)
            ->and($booking->refresh()->weather_choice)->toBe(WeatherChoice::Rebook);
    });
})->group('fast');

it('honours a choice once, however many times it arrives', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Voucher);

        // A second click on a slow connection, or the deadline sweeper racing
        // the guest. The conditional update refuses it, and only the call that
        // wrote the row moves any money.
        $second = app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Refund);

        expect($second)->toBe(0)
            ->and($booking->refresh()->weather_choice)->toBe(WeatherChoice::Voucher)
            ->and(Voucher::query()->where('issued_for_booking_id', $booking->getKey())->count())->toBe(1)
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0);
    });
})->group('fast');

it('reminds once at 72 hours and not before', function (): void {
    Event::fake([WeatherChoiceReminderDue::class]);

    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );
    });

    // Two days later: too early.
    Carbon::setTestNow(now()->addDays(2));
    app(ApplyWeatherChoiceDefaults::class)->handle(app(ApplyGuestChoice::class));
    Event::assertNotDispatched(WeatherChoiceReminderDue::class);

    // Four days: due.
    Carbon::setTestNow(now()->addDays(2));
    app(ApplyWeatherChoiceDefaults::class)->handle(app(ApplyGuestChoice::class));
    Event::assertDispatchedTimes(WeatherChoiceReminderDue::class, 1);

    // And the sweep after that sends nothing more — `weather_choice_reminded_at`
    // is the guard, because `notification_logs` is #87's and a reminder whose
    // idempotency waits on a table nobody has built goes out every hour.
    app(ApplyWeatherChoiceDefaults::class)->handle(app(ApplyGuestChoice::class));
    Event::assertDispatchedTimes(WeatherChoiceReminderDue::class, 1);
})->group('fast');

it('applies the operator default at fourteen days and says it was automatic', function (): void {
    Event::fake([WeatherChoiceApplied::class]);

    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );
    });

    Carbon::setTestNow(now()->addDays(15));

    app(ApplyWeatherChoiceDefaults::class)->handle(app(ApplyGuestChoice::class));

    expect($booking->refresh()->weather_choice)->toBe(WeatherChoice::Refund);

    Event::assertDispatched(
        WeatherChoiceApplied::class,
        // The field that decides what the email says. A guest who chose nothing
        // is being *told*, not confirmed — CXL-7 requires they be notified.
        fn (WeatherChoiceApplied $event): bool => $event->automatic === true
            && $event->bookingId === $booking->getKey(),
    );
})->group('fast');

it('honours the tenant default rather than the platform one', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    $tenant->forceFill(['weather_choice_default' => WeatherChoice::Voucher->value])->save();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );
    });

    Carbon::setTestNow(now()->addDays(15));
    app(ApplyWeatherChoiceDefaults::class)->handle(app(ApplyGuestChoice::class));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->weather_choice)->toBe(WeatherChoice::Voucher)
            ->and(Voucher::query()->where('issued_for_booking_id', $booking->getKey())->count())->toBe(1);
    });
})->group('fast');

it('leaves a guest who already answered alone', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Voucher);
    });

    Carbon::setTestNow(now()->addDays(15));
    app(ApplyWeatherChoiceDefaults::class)->handle(app(ApplyGuestChoice::class));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // The deadline answers for a guest who did not. It must never overrule
        // one who did.
        expect($booking->refresh()->weather_choice)->toBe(WeatherChoice::Voucher)
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0);
    });
})->group('fast');

it('refuses a choice on a booking cancelled for any other reason', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::MinPax,
        );

        // A guest who guesses at the URL for a booking that was cancelled for
        // too few passengers — already refunded by the ordinary path — must not
        // be able to draw a second entitlement out of it.
        expect(app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Voucher))->toBe(0)
            ->and($booking->refresh()->weather_choice)->toBeNull();
    });
})->group('fast');

it('refunds an operator cancellation immediately, with no question to ask', function (): void {
    [$tenant, $booking] = CancellationScenario::make(
        paidCents: 10000,
        ladder: [15 => 100, 7 => 100, 2 => 100],
    );

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::MinPax,
            note: 'Only two booked',
        );

        // Asking somebody whether they would like their money back for a trip
        // that was never going to sail is a question with one answer.
        expect($booking->refresh()->cancel_reason)->toBe(CancelReason::MinPax)
            ->and($booking->refunded_cents)->toBe(10000)
            ->and($booking->weather_choice_due_at)->toBeNull();
    });
})->group('fast');
