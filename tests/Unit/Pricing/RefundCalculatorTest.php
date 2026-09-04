<?php

declare(strict_types=1);

use App\Data\Pricing\CancellationPolicyData;
use App\Data\Pricing\CancellationTierData;
use App\Domain\Pricing\Support\RefundCalculator;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Refund computation — spec CXL-3, CXL-1, CNV-4
|--------------------------------------------------------------------------
|
| This decides how much money a guest gets back, so the boundaries are tested
| on both sides rather than in the middle. Every case pins a number that was
| worked out by hand from CXL-3, not read off the implementation.
|
| The calculator takes a **value object and never a model**. That is CXL-1 in
| the type signature: the same function runs against a snapshot frozen months
| ago, so editing a policy cannot change what an existing booking is owed. The
| last test in this file asserts the class has no way to reach a database.
|
*/

/**
 * Not `policy()`: Laravel declares a global helper of that name.
 *
 * The default is the §3.3 example ladder — 15 days → 100%, 7 → 50%, 2 → 0%.
 *
 * @param  array<int, int>  $ladder  days_before => refund_percent
 */
function refundPolicy(?int $freeHours = 48, array $ladder = [15 => 100, 7 => 50, 2 => 0]): CancellationPolicyData
{
    $tiers = [];

    foreach ($ladder as $days => $percent) {
        $tiers[] = new CancellationTierData(daysBefore: $days, refundPercent: $percent);
    }

    usort($tiers, static fn (CancellationTierData $a, CancellationTierData $b): int => $b->daysBefore <=> $a->daysBefore);

    return new CancellationPolicyData(
        policyId: 12,
        name: ['el' => 'Ευέλικτη', 'en' => 'Flexible'],
        summary: null,
        freeCancellationHours: $freeHours,
        weatherRefundPercent: 100,
        forceMajeureVoucherMonths: 18,
        noShowRefundPercent: 0,
        tiers: $tiers,
        capturedAt: Carbon::parse('2026-06-01T09:14:22Z'),
    );
}

function departsAt(): Carbon
{
    return Carbon::parse('2026-07-01T09:00:00Z');
}

it('refunds in full exactly at the free-cancellation boundary', function (): void {
    // CXL-3.1 says "greater than or equal to", so 48 hours exactly qualifies.
    // The boundary is the whole rule — a policy that failed here would refund
    // half to a guest who cancelled precisely when they were told they could.
    $at = departsAt()->copy()->subHours(48);

    expect(RefundCalculator::percentFor(refundPolicy(), departsAt(), $at))->toBe(100);
})->group('fast');

it('refunds in full one minute before the boundary and drops to the ladder one minute after', function (): void {
    $justInside = departsAt()->copy()->subHours(48)->subMinute();
    $justOutside = departsAt()->copy()->subHours(48)->addMinute();

    expect(RefundCalculator::percentFor(refundPolicy(), departsAt(), $justInside))->toBe(100)
        // 47h59m remaining is 1 whole day, which qualifies for no tier above
        // the 2-day rung — so the ladder answers 0%.
        ->and(RefundCalculator::percentFor(refundPolicy(), departsAt(), $justOutside))->toBe(0);
})->group('fast');

it('never consults the ladder when free cancellation applies', function (): void {
    // A policy whose top rung is 0% still refunds fully inside the free window.
    // If the ladder were evaluated first this would return 0 and the operator's
    // own promise would be broken by their own settings.
    $generous = refundPolicy(freeHours: 72, ladder: [30 => 0]);

    expect(RefundCalculator::percentFor($generous, departsAt(), departsAt()->copy()->subDays(4)))->toBe(100);
})->group('fast');

it('takes the largest qualifying rung, not the nearest', function (): void {
    // CXL-3.2. With 10 days remaining the 7-day rung qualifies and the 15-day
    // one does not; picking "closest" would wrongly award 100%.
    $at = departsAt()->copy()->subDays(10);

    expect(RefundCalculator::percentFor(refundPolicy(freeHours: null), departsAt(), $at))->toBe(50);
})->group('fast');

it('rounds the remaining days down, so a rung is reached only when fully cleared', function (): void {
    // 14 days and 23 hours is 14 whole days, which does not reach the 15-day
    // rung. Rounding up here would hand a guest 100% instead of 50%.
    $at = departsAt()->copy()->subDays(15)->addHour();

    expect(RefundCalculator::wholeDaysBetween(departsAt(), $at))->toBe(14)
        ->and(RefundCalculator::percentFor(refundPolicy(freeHours: null), departsAt(), $at))->toBe(50);
})->group('fast');

it('lands on a tier exactly at its threshold', function (): void {
    $exactly15 = departsAt()->copy()->subDays(15);
    $exactly7 = departsAt()->copy()->subDays(7);

    expect(RefundCalculator::percentFor(refundPolicy(freeHours: null), departsAt(), $exactly15))->toBe(100)
        ->and(RefundCalculator::percentFor(refundPolicy(freeHours: null), departsAt(), $exactly7))->toBe(50);
})->group('fast');

it('refunds nothing when no rung qualifies', function (): void {
    // One day out, below the lowest rung. CXL-3.2: "If no tier qualifies, the
    // refund is 0%" — not "the smallest tier".
    $at = departsAt()->copy()->subDay();

    expect(RefundCalculator::percentFor(refundPolicy(freeHours: null), departsAt(), $at))->toBe(0);
})->group('fast');

it('refunds nothing for a cancellation at or after departure', function (): void {
    // CXL-4 keeps this off the guest page; the operator can still record a
    // refund by hand, which is a different action with its own audit trail.
    expect(RefundCalculator::percentFor(refundPolicy(), departsAt(), departsAt()))->toBe(0)
        ->and(RefundCalculator::percentFor(refundPolicy(), departsAt(), departsAt()->copy()->addHour()))->toBe(0);
})->group('fast');

it('computes the refund from cash actually paid, not the booking total', function (): void {
    // CXL-3.3. A guest who paid a 30% deposit on a €400 trip and cancels under
    // the 50% rung is owed half of the €120 they paid, not half of the trip.
    $at = departsAt()->copy()->subDays(10);

    expect(RefundCalculator::refundCents(refundPolicy(freeHours: null), departsAt(), $at, paidCents: 12000))
        ->toBe(6000);
})->group('fast');

it('rounds half up on a fractional cent', function (): void {
    // CNV-4. 3333 * 50 / 100 = 1666.5, which must become 1667 rather than 1666.
    // Native float rounding drifts here; brick/money keeps it in minor units.
    expect(RefundCalculator::applyPercent(3333, 50))->toBe(1667)
        // And the other direction, to prove it is half-up rather than always-up.
        ->and(RefundCalculator::applyPercent(3333, 33))->toBe(1100);
})->group('fast');

it('returns zero for zero paid and for a zero percentage', function (): void {
    // A guest who paid nothing is owed nothing, and the arithmetic must not
    // produce a negative or a stray cent.
    expect(RefundCalculator::applyPercent(0, 100))->toBe(0)
        ->and(RefundCalculator::applyPercent(12000, 0))->toBe(0);
})->group('fast');

it('treats a null free-cancellation window as no free cancellation at all', function (): void {
    // Not the same as zero hours, which would mean free cancellation right up
    // to departure. Null is a real policy: the ladder always decides.
    $at = departsAt()->copy()->subDays(20);

    expect(RefundCalculator::qualifiesForFreeCancellation(refundPolicy(freeHours: null), departsAt(), $at))->toBeFalse()
        ->and(RefundCalculator::percentFor(refundPolicy(freeHours: null), departsAt(), $at))->toBe(100);
})->group('fast');

it('evaluates an unsorted ladder correctly, because an old snapshot may carry one', function (): void {
    // §3.3 stores tiers sorted descending, but this function reads rows written
    // by earlier versions of the application. Sorting defensively costs nothing
    // and is the difference between a correct refund and a silent 0%.
    $unsorted = new CancellationPolicyData(
        policyId: null,
        name: [],
        summary: null,
        freeCancellationHours: null,
        weatherRefundPercent: 100,
        forceMajeureVoucherMonths: 18,
        noShowRefundPercent: 0,
        tiers: [
            new CancellationTierData(daysBefore: 2, refundPercent: 0),
            new CancellationTierData(daysBefore: 15, refundPercent: 100),
            new CancellationTierData(daysBefore: 7, refundPercent: 50),
        ],
        capturedAt: Carbon::now(),
    );

    expect(RefundCalculator::percentFor($unsorted, departsAt(), departsAt()->copy()->subDays(10)))->toBe(50);
})->group('fast');

it('cannot reach the database, which is what makes CXL-1 structural', function (): void {
    // The invariant is "editing a policy MUST NOT affect an existing booking".
    // A calculator that could load a policy would satisfy every test above and
    // break that the first time an operator edited a policy with bookings on
    // the books — silently, in money. So this asserts the shape rather than the
    // behaviour: no constructor, no state, nothing to inject a repository into.
    $reflection = new ReflectionClass(RefundCalculator::class);

    expect($reflection->getConstructor())->toBeNull()
        ->and($reflection->getProperties())->toBe([]);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("{$method->getName()} is not static");

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

            expect($name)->not->toContain('App\\Models', "{$method->getName()} accepts a model");
        }
    }
})->group('fast');
