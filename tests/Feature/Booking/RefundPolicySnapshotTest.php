<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingStatus;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| CXL-1 (FIXED), CXL-3: the refund the guest agreed to, not the one on file
|--------------------------------------------------------------------------
|
| > *Refunds are computed from the policy snapshot taken at booking time, never
| > from the current policy. Editing a `CancellationPolicy` MUST NOT affect any
| > existing booking.*
|
| The first test in this file is the one an operator would want to see: edit the
| live policy after the booking, and assert the refund did not move. It is also
| the requirement most likely to break silently, because every other test in the
| suite passes whether or not it holds — a fixture that never edits the policy
| cannot tell a snapshot from a lookup.
|
| The safeguard in the code is a type signature: `RefundCalculator` takes a
| `CancellationPolicyData` and there is no way to obtain one but freezing a
| model at booking time or reading a stored snapshot. This asserts the property
| that signature is protecting, so that removing the signature fails a test
| rather than merely looking untidy.
|
*/

beforeEach(function (): void {
    CancellationScenario::fakeGatewayResponses();

    // Fourteen days and change before the 2026-07-04 06:00 UTC departure, which
    // is the 7-day rung of the standard ladder: 50%.
    Carbon::setTestNow('2026-06-20 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('refunds from the snapshot even after the live policy is rewritten', function (): void {
    [$tenant, $booking, $policy] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking, $policy): void {
        // The operator has a bad season and tightens everything. This is the
        // edit CXL-1 exists for.
        $policy->forceFill(['free_cancellation_hours' => null])->save();

        CancellationPolicyTier::query()
            ->where('cancellation_policy_id', $policy->getKey())
            ->update(['refund_percent' => 0]);

        $entitlement = RefundEntitlement::forCancellation($booking->refresh());

        // 50%, from the terms the guest accepted in June. A calculator that
        // resolved the policy would return 0% here and nothing would report it.
        expect($entitlement->percent)->toBe(50)
            ->and($entitlement->totalCents)->toBe(6000);
    });
})->group('fast');

it('refunds from the snapshot even after the policy is deleted', function (): void {
    [$tenant, $booking, $policy] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking, $policy): void {
        // The harder case, and the one a lookup cannot survive at all: a
        // booking outlives the policy it was made under, and its refund still
        // has to be computable years later.
        CancellationPolicy::query()->whereKey($policy->getKey())->delete();

        expect(RefundEntitlement::forCancellation($booking->refresh())->percent)->toBe(50);
    });
})->group('fast');

it('takes the largest qualifying tier and no other', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // CXL-3.2's ladder — 15 → 100%, 7 → 50%, 2 → 0% — read from each side
        // of every rung. Sixteen days out is the 15-day rung, not the 7-day one
        // below it and not the absent 20-day one above.
        $cases = [
            '2026-06-17 00:00:00' => 100,
            '2026-06-19 06:00:00' => 100,
            '2026-06-19 07:00:00' => 50,
            '2026-06-27 06:00:00' => 50,
            '2026-06-27 07:00:00' => 0,
        ];

        foreach ($cases as $at => $expected) {
            expect(RefundEntitlement::forCancellation($booking, Carbon::parse($at))->percent)
                ->toBe($expected, "cancelling at {$at}");
        }
    });
})->group('fast');

it('refunds nothing when no tier qualifies, rather than the smallest one', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000, ladder: [15 => 100]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // CXL-3.2: *"If no tier qualifies, the refund is 0%"* — not the lowest
        // rung, and not an error. A ladder that starts at 15 days says nothing
        // at all about a cancellation made the day before.
        expect(RefundEntitlement::forCancellation($booking, Carbon::parse('2026-07-03 00:00:00'))->percent)
            ->toBe(0);
    });
})->group('fast');

it('lets free cancellation win outright, without consulting the ladder', function (): void {
    [$tenant, $booking] = CancellationScenario::make(
        paidCents: 10000,
        // A ladder that gives nothing at any distance. If the free-cancellation
        // window is consulted second, this fixture returns 0% and the test that
        // matters passes for the wrong reason.
        ladder: [15 => 0, 7 => 0, 2 => 0],
        freeCancellationHours: 48,
    );

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(RefundEntitlement::forCancellation($booking, Carbon::parse('2026-07-01 00:00:00'))->percent)
            ->toBe(100)
            // And an hour inside the window, the ladder decides after all.
            ->and(RefundEntitlement::forCancellation($booking, Carbon::parse('2026-07-03 00:00:00'))->percent)
            ->toBe(0);
    });
})->group('fast');

it('computes the refund from cash paid, not from the booking total', function (): void {
    // CXL-3.3, and a deposit-only booking is where the difference is the whole
    // point: €120 trip, €36 deposit taken, cancelled under the 50% rung. Half
    // of the deposit is €18; half of the *trip* would be €60 — money the
    // operator never received.
    [$tenant, $booking] = CancellationScenario::make(paidCents: 3600);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['total_cents' => 12000, 'balance_cents' => 8400])->save();

        expect(RefundEntitlement::forCancellation($booking->refresh())->totalCents)->toBe(1800);
    });
})->group('fast');

it('rounds half up, in cents, through brick/money', function (): void {
    // CNV-4. €33.33 at 50% is 1666.5 cents, which is the case native float
    // arithmetic gets wrong in a way nobody notices until an invoice is a cent
    // out against the payment rows.
    [$tenant, $booking] = CancellationScenario::make(paidCents: 3333);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(RefundEntitlement::forCancellation($booking)->totalCents)->toBe(1667);
    });
})->group('fast');

it('cancels the booking and records who and why', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $cancelled = app(CancelBooking::class)($booking);

        expect($cancelled->status)->toBe(BookingStatus::Cancelled)
            ->and($cancelled->cancelled_at)->not->toBeNull()
            // §2.5's fixed list rather than free text, because these get
            // counted: how many trips did the weather cost this season is a
            // question a `varchar` cannot answer.
            ->and($cancelled->cancel_reason?->value)->toBe('guest_request');
    });
})->group('fast');
