<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\PaymentKind;
use App\Enums\VoucherReason;
use App\Models\Payment;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| PRC-19.2, PRC-19.3, ADR-0017: voucher value stays voucher value
|--------------------------------------------------------------------------
|
| The arithmetic in this milestone most likely to be got subtly wrong, and its
| failure mode is refunding cash the operator never received — a guest converts
| credit into money by booking and cancelling, and nothing in the system says
| so.
|
| ADR-0017's own worked example is the first test, with its own numbers rather
| than round ones: €200 paid with a €120 voucher and €80 cash, cancelled under a
| 50% policy, gives **€60 to the voucher and €40 in cash**. A fixture that used
| €100 and €100 would pass under an implementation that simply halved everything.
|
*/

beforeEach(function (): void {
    CancellationScenario::fakeGatewayResponses();

    // The 7-day rung: 50%.
    Carbon::setTestNow('2026-06-27 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('splits the entitlement pro rata across the voucher and the cash', function (): void {
    [$tenant, $booking] = CancellationScenario::paidWithVoucher();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $entitlement = RefundEntitlement::forCancellation($booking);

        // ADR-0017, exactly.
        expect($entitlement->totalCents)->toBe(10000)
            ->and($entitlement->voucherCents)->toBe(6000)
            ->and($entitlement->cashCents)->toBe(4000);
    });
})->group('fast');

it('never refunds more cash than the guest actually paid', function (): void {
    // A booking almost entirely covered by a voucher: €200 of credit, €10 in
    // cash, refunded at 100%. The cash half must be €10 and not a cent more —
    // this is the failure ADR-0017 exists to prevent, and it is silent.
    [$tenant, $booking] = CancellationScenario::paidWithVoucher(
        totalCents: 21000,
        voucherCents: 20000,
        cashCents: 1000,
        ladder: [15 => 100, 7 => 100, 2 => 100],
    );

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $entitlement = RefundEntitlement::forCancellation($booking);

        expect($entitlement->totalCents)->toBe(21000)
            ->and($entitlement->voucherCents)->toBe(20000)
            ->and($entitlement->cashCents)->toBe(1000);
    });
})->group('fast');

it('halves sum to the entitlement rather than rounding apart', function (): void {
    // A ratio that does not divide: €33.33 of credit against €66.67 of cash at
    // 50%. Computed as two independent proportions these round to a cent more
    // or less than the whole, every time, on exactly the bookings where
    // somebody is already unhappy.
    [$tenant, $booking] = CancellationScenario::paidWithVoucher(
        totalCents: 10000,
        voucherCents: 3333,
        cashCents: 6667,
    );

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $entitlement = RefundEntitlement::forCancellation($booking);

        expect($entitlement->voucherCents + $entitlement->cashCents)->toBe($entitlement->totalCents);
    });
})->group('fast');

it('restores the voucher and refunds the cash, in one cancellation', function (): void {
    [$tenant, $booking, $voucher] = CancellationScenario::paidWithVoucher();

    Tenancy::forTenant($tenant, function () use ($booking, $voucher): void {
        app(CancelBooking::class)($booking);

        // PRC-19.4: the movement is amended, never deleted — the direction, the
        // amount and the time of the restoration are all still on the row.
        $redemption = VoucherRedemption::query()
            ->where('booking_id', $booking->getKey())
            ->sole();

        expect($redemption->reversed_amount_cents)->toBe(6000)
            ->and($redemption->reversed_at)->not->toBeNull()
            // `remaining_cents` reconstructed from the ledger, which is the
            // property PRC-19.4 actually protects.
            ->and($voucher->refresh()->remaining_cents)->toBe(6000);

        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        expect($refund->amount_cents)->toBe(4000);
    });
})->group('fast');

it('issues a new voucher when the original has already expired', function (): void {
    [$tenant, $booking, $expired] = CancellationScenario::paidWithVoucher(voucherExpired: true);

    Tenancy::forTenant($tenant, function () use ($booking, $expired): void {
        app(CancelBooking::class)($booking);

        // PRC-19.3. A dead voucher is not revived: the value is issued fresh
        // and linked back, so the trail from the cancelled booking to the new
        // credit survives.
        $replacement = Voucher::query()
            ->where('issued_for_booking_id', $booking->getKey())
            ->sole();

        expect($replacement->getKey())->not->toBe($expired->getKey())
            ->and($replacement->amount_cents)->toBe(6000)
            ->and($replacement->reason)->toBe(VoucherReason::ForceMajeure)
            // CXL-8's eighteen months, from the booking's own frozen snapshot.
            // It was twelve here until #84 — three copies of the default agreed
            // and nobody read the fourth.
            ->and($replacement->expires_at?->toDateString())
            ->toBe(now()->addMonths(18)->toDateString());
    });
})->group('fast');

it('does not extend a live voucher when restoring to it', function (): void {
    [$tenant, $booking, $voucher] = CancellationScenario::paidWithVoucher();

    Tenancy::forTenant($tenant, function () use ($booking, $voucher): void {
        $before = $voucher->expires_at?->toIso8601String();

        app(CancelBooking::class)($booking);

        // PRC-19.3's other half, and the reason it matters both ways: pushing
        // an expiry out quietly rewrites a term the guest already accepted — in
        // their favour today, and against them the first time an operator
        // relies on it.
        expect($voucher->refresh()->expires_at?->toIso8601String())->toBe($before)
            ->and(Voucher::query()->where('issued_for_booking_id', $booking->getKey())->count())->toBe(0);
    });
})->group('fast');

it('restores nothing to a booking that used no voucher', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $entitlement = RefundEntitlement::forCancellation($booking);

        // The other end of the ratio, and the case CXL-3.3 was written about:
        // no voucher, so the whole entitlement is cash and the base is exactly
        // `paid_cents`.
        expect($entitlement->voucherCents)->toBe(0)
            ->and($entitlement->cashCents)->toBe(6000);
    });
})->group('fast');

it('ignores a manual discount, because nobody paid it', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 8000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // §2.5: `discount_cents` is "voucher + manual discount". A manual
        // discount is a **price reduction**, not consideration the guest handed
        // over — splitting against it would refund money nobody ever paid. The
        // ledger is empty, so the base is the cash alone.
        $booking->forceFill(['discount_cents' => 5000])->save();

        expect(RefundEntitlement::forCancellation($booking->refresh())->totalCents)->toBe(4000);
    });
})->group('fast');
