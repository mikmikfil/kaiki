<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\ApplyVoucher;
use App\Domain\Pricing\Actions\RestoreVoucher;
use App\Enums\VoucherReason;
use App\Enums\VoucherStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| PRC-19: the ledger, and the balance that must always follow from it
|--------------------------------------------------------------------------
|
| PRC-19.4 requires `vouchers.remaining_cents` to be **reconstructible from
| `voucher_redemptions` at all times**, and asks for a reconciliation test. This
| is it, run after every shape of movement.
|
| The failure being guarded is not dramatic: a denormalised balance drifts by a
| few cents, nobody notices, and a guest eventually tries to spend money that is
| not there — or spends money twice that was only there once.
|
| ADR-0017's headline runs through all of it: **voucher value stays voucher
| value and cash stays cash; neither is ever converted into the other.**
|
*/

/** @return array{0: Tenant, 1: Voucher, 2: Booking} */
function ledgerScenario(int $voucherCents = 5000, int $bookingTotal = 12000): array
{
    $tenant = Tenant::factory()->create();

    return Tenancy::forTenant($tenant, static function () use ($voucherCents, $bookingTotal, $tenant): array {
        $voucher = Voucher::factory()->create([
            'amount_cents' => $voucherCents,
            'remaining_cents' => $voucherCents,
        ]);

        $booking = Booking::factory()->create([
            'subtotal_cents' => $bookingTotal,
            'extras_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => $bookingTotal,
            'paid_cents' => 0,
            'balance_cents' => $bookingTotal,
            'voucher_id' => $voucher->getKey(),
        ]);

        return [$tenant, $voucher, $booking];
    });
}

/** The assertion PRC-19.4 asks for, made after every movement below. */
function assertLedgerReconciles(Voucher $voucher): void
{
    $voucher->refresh();

    expect($voucher->remaining_cents)->toBe(
        $voucher->amount_cents - VoucherRedemption::consumedFor($voucher->getKey()),
        'remaining_cents no longer follows from the ledger',
    );
}

it('spends a voucher and leaves the balance reconstructible', function (): void {
    [$tenant, $voucher, $booking] = ledgerScenario();

    Tenancy::forTenant($tenant, function () use ($voucher, $booking): void {
        $applied = app(ApplyVoucher::class)($booking);

        expect($applied)->toBe(5000)
            ->and($booking->refresh()->discount_cents)->toBe(5000)
            ->and($booking->total_cents)->toBe(7000);

        assertLedgerReconciles($voucher);

        expect($voucher->refresh()->remaining_cents)->toBe(0)
            ->and($voucher->status)->toBe(VoucherStatus::Redeemed);
    });
})->group('fast');

it('leaves the surplus on the voucher rather than forfeiting or paying it out', function (): void {
    // PRC-19.1. A €50 voucher against a €30 booking leaves €20 on the voucher
    // for a later trip — not €20 in cash, and not €20 gone.
    [$tenant, $voucher, $booking] = ledgerScenario(voucherCents: 5000, bookingTotal: 3000);

    Tenancy::forTenant($tenant, function () use ($voucher, $booking): void {
        expect(app(ApplyVoucher::class)($booking))->toBe(3000)
            ->and($booking->refresh()->total_cents)->toBe(0);

        assertLedgerReconciles($voucher);

        expect($voucher->refresh()->remaining_cents)->toBe(2000)
            ->and($voucher->status)->toBe(VoucherStatus::Active);
    });
})->group('fast');

it('never takes a booking total below zero', function (): void {
    [$tenant, , $booking] = ledgerScenario(voucherCents: 50000, bookingTotal: 1000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(ApplyVoucher::class)($booking);

        expect($booking->refresh()->total_cents)->toBe(0)
            ->and($booking->discount_cents)->toBe(1000);
    });
})->group('fast');

it('is idempotent, so returning to a failed checkout does not spend twice', function (): void {
    [$tenant, $voucher, $booking] = ledgerScenario();

    Tenancy::forTenant($tenant, function () use ($voucher, $booking): void {
        app(ApplyVoucher::class)($booking);
        app(ApplyVoucher::class)($booking);
        app(ApplyVoucher::class)($booking);

        // Both checkout and confirmation call this, and a guest who bounces off
        // a declined card comes back through the same path.
        expect(VoucherRedemption::query()->where('voucher_id', $voucher->getKey())->count())->toBe(1);

        assertLedgerReconciles($voucher);

        expect($voucher->refresh()->remaining_cents)->toBe(0);

        // **The assertion this test was missing**, and its absence is why it
        // passed against a real bug. Applying a voucher marks it `Redeemed`,
        // which is derived from the ledger — and a spendability check that
        // refused a `Redeemed` voucher therefore refused this booking its own
        // discount on the second call, cleared it, and put the total back up.
        // The redemption row and the balance both looked right throughout.
        $booking->refresh();

        expect($booking->discount_cents)->toBe(5000)
            ->and($booking->total_cents)->toBe(7000);
    });
})->group('fast');

it('will not spend the same voucher twice across two bookings', function (): void {
    [$tenant, $voucher, $first] = ledgerScenario(voucherCents: 5000, bookingTotal: 5000);

    Tenancy::forTenant($tenant, function () use ($voucher, $first): void {
        expect(app(ApplyVoucher::class)($first))->toBe(5000);

        $second = Booking::factory()->create([
            'subtotal_cents' => 5000,
            'extras_cents' => 0,
            'total_cents' => 5000,
            'balance_cents' => 5000,
            'voucher_id' => $voucher->getKey(),
        ]);

        // Nothing left. The second booking pays full price rather than
        // receiving a discount the voucher cannot fund.
        expect(app(ApplyVoucher::class)($second))->toBe(0)
            ->and($second->refresh()->total_cents)->toBe(5000);

        assertLedgerReconciles($voucher);
    });
})->group('fast');

it('refuses an expired voucher without touching the ledger', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $voucher = Voucher::factory()->lapsed()->create();

        $booking = Booking::factory()->create([
            'subtotal_cents' => 10000,
            'extras_cents' => 0,
            'total_cents' => 10000,
            'balance_cents' => 10000,
            'voucher_id' => $voucher->getKey(),
        ]);

        // PRC-21 evaluates expiry at end of day in the tenant timezone; the
        // read-side check here is the same posture the seat hold takes — a
        // voucher is unspendable the moment it expires, not the moment a
        // sweeper next runs.
        expect(app(ApplyVoucher::class)($booking))->toBe(0)
            ->and(VoucherRedemption::query()->count())->toBe(0)
            ->and($booking->refresh()->total_cents)->toBe(10000);
    });
})->group('fast');

it('restores voucher value pro-rata and never as cash', function (): void {
    // ADR-0017's worked example: a €200 booking paid with a €120 voucher and
    // €80 cash, cancelled under a 50% policy. €100 entitlement → €60 to the
    // voucher, €40 in cash.
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $voucher = Voucher::factory()->create(['amount_cents' => 12000, 'remaining_cents' => 12000]);

        $booking = Booking::factory()->create([
            'subtotal_cents' => 20000,
            'extras_cents' => 0,
            'total_cents' => 20000,
            'paid_cents' => 8000,
            'balance_cents' => 0,
            'voucher_id' => $voucher->getKey(),
        ]);

        app(ApplyVoucher::class)($booking);

        // The voucher took €120 of the €200; the guest paid €80 in cash.
        $booking->refresh()->forceFill(['paid_cents' => 8000])->save();

        $restored = app(RestoreVoucher::class)($booking, entitlementCents: 10000);

        expect($restored)->toBe(6000);

        // And the cash half is the remainder of the same calculation, so the
        // two cannot round independently and sum to more than the guest is owed.
        expect(10000 - $restored)->toBe(4000);

        assertLedgerReconciles($voucher);
    });
})->group('fast');

it('writes a reversal and never deletes a redemption row', function (): void {
    [$tenant, $voucher, $booking] = ledgerScenario(voucherCents: 5000, bookingTotal: 5000);

    Tenancy::forTenant($tenant, function () use ($voucher, $booking): void {
        app(ApplyVoucher::class)($booking);

        expect($voucher->refresh()->remaining_cents)->toBe(0);

        $booking->refresh();

        app(RestoreVoucher::class)($booking, entitlementCents: 5000, reason: 'guest_request');

        // PRC-19.4: the row survives, amended rather than replaced or removed.
        $redemption = VoucherRedemption::query()->where('voucher_id', $voucher->getKey())->firstOrFail();

        expect(VoucherRedemption::query()->count())->toBe(1)
            ->and($redemption->amount_cents)->toBe(5000)
            ->and($redemption->reversed_amount_cents)->toBe(5000)
            ->and($redemption->reversed_at)->not->toBeNull()
            ->and($redemption->reason)->toBe('guest_request');

        assertLedgerReconciles($voucher);

        // Spendable again, derived from the ledger rather than flipped by hand.
        expect($voucher->refresh()->remaining_cents)->toBe(5000)
            ->and($voucher->status)->toBe(VoucherStatus::Active);
    });
})->group('fast');

it('issues a replacement rather than reviving an expired voucher', function (): void {
    // PRC-19.3. Extending a dead voucher's expiry would quietly rewrite a term
    // the guest already accepted.
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $voucher = Voucher::factory()->create(['amount_cents' => 5000, 'remaining_cents' => 5000]);

        $booking = Booking::factory()->create([
            'subtotal_cents' => 5000,
            'extras_cents' => 0,
            'total_cents' => 5000,
            // Explicitly zero: the factory's default booking is a paid one, and
            // `voucherShareOf` splits pro-rata across the voucher and cash
            // *actually used*. Leaving the default in place made this a booking
            // paid mostly in cash, and the restored share came back as €14.71
            // rather than the whole €50 — correct arithmetic on the wrong
            // fixture.
            'paid_cents' => 0,
            'balance_cents' => 5000,
            'voucher_id' => $voucher->getKey(),
            'policy_snapshot' => ['force_majeure_voucher_months' => 6],
        ]);

        app(ApplyVoucher::class)($booking);

        // It expires while the booking is live.
        $voucher->forceFill(['expires_at' => now()->subDay()])->save();

        app(RestoreVoucher::class)($booking->refresh(), entitlementCents: 5000);

        $replacement = Voucher::query()
            ->where('issued_for_booking_id', $booking->getKey())
            ->firstOrFail();

        expect($replacement->amount_cents)->toBe(5000)
            ->and($replacement->reason)->toBe(VoucherReason::ForceMajeure)
            ->and($replacement->expires_at?->greaterThan(now()->addMonths(5)))->toBeTrue()
            // The original's expiry is untouched, which is the other half of
            // the same rule.
            ->and($voucher->refresh()->expires_at?->isPast())->toBeTrue();
    });
})->group('fast');
