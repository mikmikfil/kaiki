<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Data\RefundOverride;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\AuditAction;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Events\BookingCancelled;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| CXL-4, CXL-5, CXL-9: the seats come back, and somebody has to say why
|--------------------------------------------------------------------------
|
| CXL-9 asks for the release and the status write to be **the same
| transaction**. They are the same write or they are a bug: a cancellation that
| released seats and then failed to write the status puts the same pax on sale
| twice, and one that wrote the status and failed to release takes the seats off
| sale forever, with nothing to show why.
|
| CXL-5 is the other half of this file. An override requires a reason, and the
| reason is enforced by the constructor rather than by a form — a validation
| rule on one Filament field satisfies the requirement for that one screen, and
| the API, the console command and the next screen all get to skip it.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    CancellationScenario::fakeGatewayResponses();

    Carbon::setTestNow('2026-06-27 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('gives the seats back to the departure', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $departure = Departure::query()->findOrFail($booking->departure_id);

        expect($departure->seats_sold)->toBe(2);

        app(CancelBooking::class)($booking);

        // `capacity − seats_sold − seats_held` is the arithmetic every
        // availability read does. Two seats that stayed sold after the booking
        // ended are two seats nobody can ever buy.
        expect($departure->refresh()->seats_sold)->toBe(0)
            ->and($departure->seats_held)->toBe(0);
    });
})->group('fast');

it('releases the seats in the same transaction as the status', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $departure = Departure::query()->findOrFail($booking->departure_id);

        // CXL-9 asserted from the outside: roll the whole thing back, and both
        // halves must be gone. A release that had committed on its own would
        // survive this and leave the seats double-sold.
        DB::beginTransaction();

        try {
            app(CancelBooking::class)($booking);
        } finally {
            DB::rollBack();
        }

        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($departure->refresh()->seats_sold)->toBe(2);
    });
})->group('fast');

it('frees a private charter window by ending the booking', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // CXL-9's second mode. A per-vessel booking occupies a *window* rather
        // than seats, and `Booking::occupiesVesselWindow()` already decides that from
        // the status — so cancelling is the release, with nothing to decrement.
        $booking->forceFill(['departure_id' => null, 'mode' => BookingMode::PerVessel])->save();

        $cancelled = app(CancelBooking::class)($booking->refresh());

        expect($cancelled->occupiesVesselWindow())->toBeFalse();
    });
})->group('fast');

it('cancels an open payment along with the booking', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Payment::factory()->pending()->create([
            'booking_id' => $booking->getKey(),
            'kind' => PaymentKind::Balance,
            'amount_cents' => 2000,
        ]);

        app(CancelBooking::class)($booking);

        // Left open, it would sit in the operator's stuck-payment feed forever,
        // competing for attention with the ones that mean something.
        expect(Payment::query()->where('kind', PaymentKind::Balance->value)->sole()->status)
            ->toBe(PaymentStatus::Cancelled);
    });
})->group('fast');

it('emits BookingCancelled after the commit, with the figure the guest was told', function (): void {
    Event::fake([BookingCancelled::class]);

    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)($booking);

        Event::assertDispatched(
            BookingCancelled::class,
            // Ids and scalars, never a model: a queued listener is constructed
            // on a worker, where a serialised model is re-fetched under whatever
            // tenant the previous job left behind (#53).
            fn (BookingCancelled $event): bool => $event->bookingId === $booking->getKey()
                && $event->reason === CancelReason::GuestRequest
                && $event->refundCents === 5000,
        );
    });
})->group('fast');

it('refuses an override with no reason', function (): void {
    // CXL-5, enforced where every caller is bound by it. The failure is at the
    // point of the mistake rather than three layers down in an audit row that
    // quietly says `null`.
    expect(fn () => new RefundOverride(method: RefundMethod::Cash, reason: '   ', percent: 80))
        ->toThrow(InvalidArgumentException::class);
})->group('fast');

it('applies an operator percentage over the policy and records both', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)(
            booking: $booking,
            reason: CancelReason::Operator,
            by: CancelledBy::Operator,
            override: new RefundOverride(
                method: RefundMethod::Cash,
                reason: 'Regular customer, boat was overbooked by us',
                percent: 100,
            ),
        );

        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->sole()->amount_cents)
            ->toBe(10000);

        $row = AuditLog::query()->where('action', AuditAction::OverrideApplied->value)->sole();

        // Both numbers, not just the applied one: a row saying "100%" says
        // nothing about whether that was the policy or a decision, and a dispute
        // a year later is entirely about which.
        expect($row->reason)->toBe('Regular customer, boat was overbooked by us')
            ->and($row->context['policy_percent'] ?? null)->toBe(50)
            ->and($row->context['applied_percent'] ?? null)->toBe(100);
    });
})->group('fast');

it('issues a voucher instead of cash when the operator chooses that', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)(
            booking: $booking,
            reason: CancelReason::Operator,
            by: CancelledBy::Operator,
            override: new RefundOverride(
                method: RefundMethod::Voucher,
                reason: 'Guest asked for credit towards September',
            ),
        );

        // The whole entitlement becomes credit, not just a cash share: the
        // operator has chosen not to move money at all, and splitting it would
        // leave the guest holding two codes for one decision.
        expect(Voucher::query()->where('issued_for_booking_id', $booking->getKey())->sole()->amount_cents)
            ->toBe(5000)
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0);
    });
})->group('fast');

it('records a waiver, which is not the same as a nil policy', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)(
            booking: $booking,
            reason: CancelReason::Operator,
            by: CancelledBy::Operator,
            override: RefundOverride::waive('No-show, contacted us the next morning'),
        );

        // Nothing moved, and the decision is on the trail. 0% says *the policy
        // gave nothing*; waived says *the policy gave something and the operator
        // kept it* — only the second has to be defensible.
        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0)
            ->and(Voucher::query()->where('issued_for_booking_id', $booking->getKey())->count())->toBe(0);

        $row = AuditLog::query()->where('action', AuditAction::OverrideApplied->value)->sole();

        expect($row->context['method'] ?? null)->toBe('waived')
            ->and($row->context['policy_percent'] ?? null)->toBe(50)
            ->and($row->context['applied_percent'] ?? null)->toBe(0);
    });
})->group('fast');

it('writes a booking.refunded audit row, which ADR-0025 has been waiting for', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)($booking);

        $row = AuditLog::query()->where('action', AuditAction::BookingRefunded->value)->sole();

        // SEC-16's list, complete but for `gdpr.purged`. The label is the
        // reference — what an operator and a guest both say out loud — and
        // never the guest's name: ADR-0025 §3, seven-year retention.
        expect($row->subject_label)->toBe($booking->reference)
            ->and($row->context['amount_cents'] ?? null)->toBe(5000)
            ->and($row->context['method'] ?? null)->toBe('cash');
    });
})->group('fast');

it('refunds nothing for a cancellation after departure', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // CXL-4 keeps this off the guest page entirely; the operator may still
        // record one by hand, which is a different action with its own trail.
        // The calculator's answer on this path is zero, whatever the ladder says.
        $after = Carbon::parse('2026-07-04 07:00:00');

        expect(RefundEntitlement::forCancellation($booking, $after)->totalCents)->toBe(0);

        app(CancelBooking::class)($booking, at: $after);

        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0)
            ->and($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
    });
})->group('fast');

it('still releases the seat when the refund is nil', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000, ladder: [15 => 100]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $departure = Departure::query()->findOrFail($booking->departure_id);

        // The guest-facing case CXL-4 names: the snapshot yields 0%, it is shown
        // plainly, and the guest can **still** release the seat. A cancel button
        // that refuses to work when there is no money in it is a seat left empty
        // on a boat somebody else wanted.
        app(CancelBooking::class)($booking, at: Carbon::parse('2026-07-03 00:00:00'));

        expect($departure->refresh()->seats_sold)->toBe(0)
            ->and($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
    });
})->group('fast');

it('refuses to cancel a booking that has already been refunded', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['status' => BookingStatus::Refunded])->save();

        // §4.1's transition table lives on the enum so it is testable in
        // isolation, and every Action asserts against it. `refunded` is terminal.
        expect(fn () => app(CancelBooking::class)($booking->refresh()))
            ->toThrow(RuntimeException::class);
    });
})->group('fast');

it('does not release the seats twice when cancelled twice', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $departure = Departure::query()->findOrFail($booking->departure_id);

        app(CancelBooking::class)($booking);
        app(CancelBooking::class)($booking->refresh());

        // Releasing twice is how a departure ends up with negative
        // `seats_sold`, which then reads as extra capacity nobody has.
        expect($departure->refresh()->seats_sold)->toBe(0);
    });
})->group('fast');

it('leaves other bookings on the same departure alone', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000, capacity: 12);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $departure = Departure::query()->findOrFail($booking->departure_id);

        $other = Booking::factory()
            ->forDeparture($departure)
            ->withPax(3, 3)
            ->create(['status' => BookingStatus::Confirmed]);

        $departure->forceFill(['seats_sold' => 5])->save();

        app(CancelBooking::class)($booking);

        expect($departure->refresh()->seats_sold)->toBe(3)
            ->and($other->refresh()->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');
