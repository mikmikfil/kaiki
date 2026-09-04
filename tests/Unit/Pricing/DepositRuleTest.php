<?php

declare(strict_types=1);

use App\Enums\DepositType;
use App\Models\RatePlan;

/*
|--------------------------------------------------------------------------
| Deposits — spec PRC-23, CNV-1, CNV-4
|--------------------------------------------------------------------------
|
| `RatePlan::depositCents()` is the only place that answers "what does the
| guest pay now", and it answers in integer cents through the same half-up
| helper the refund path uses. Two implementations of that rounding is two
| chances for a deposit and its refund to disagree by a cent, which is a
| reconciliation ticket rather than a bug report.
|
| Unmigrated models throughout: this is arithmetic on three columns, and a
| database would only slow it down.
|
*/

function planWithDeposit(DepositType $type, ?int $percent = null, ?int $fixedCents = null): RatePlan
{
    $plan = new RatePlan;

    $plan->deposit_type = $type;
    $plan->deposit_percent = $percent;
    $plan->deposit_fixed_cents = $fixedCents;

    return $plan;
}

it('charges the whole total when no deposit is taken', function (): void {
    // "No deposit" means the guest pays everything at checkout, not nothing —
    // getting this backwards would take a boat out with no money against it.
    expect(planWithDeposit(DepositType::None)->depositCents(15000))->toBe(15000);
})->group('fast');

it('takes a percentage of the total, rounded half up', function (int $totalCents, int $percent, int $expected): void {
    expect(planWithDeposit(DepositType::Percent, percent: $percent)->depositCents($totalCents))
        ->toBe($expected);
})->with([
    // 30% of €150.00 is exact.
    [15000, 30, 4500],
    // 30% of €33.33 is 999.9 cents — half up, and never 999.
    [3333, 30, 1000],
    // A third of €10.01 is 333.6667 — the case a float would drift on.
    [1001, 33, 330],
    [10000, 100, 10000],
])->group('fast');

it('takes a flat amount whatever the total', function (): void {
    expect(planWithDeposit(DepositType::Fixed, fixedCents: 20000)->depositCents(85000))->toBe(20000);
})->group('fast');

it('never charges a flat deposit larger than the total', function (): void {
    // A €200 standing deposit on a €150 seat is an operator setting, not a
    // licence to take more than the trip costs.
    expect(planWithDeposit(DepositType::Fixed, fixedCents: 20000)->depositCents(15000))->toBe(15000);
})->group('fast');

it('answers zero rather than a negative for a zero total', function (): void {
    expect(planWithDeposit(DepositType::Percent, percent: 30)->depositCents(0))->toBe(0)
        ->and(planWithDeposit(DepositType::Fixed, fixedCents: 5000)->depositCents(0))->toBe(0)
        ->and(planWithDeposit(DepositType::None)->depositCents(0))->toBe(0);
})->group('fast');

it('returns an integer, never a float', function (): void {
    // CNV-1 in its narrowest form: the value that reaches a payment column.
    expect(planWithDeposit(DepositType::Percent, percent: 33)->depositCents(1001))->toBeInt();
})->group('fast');

it('names the column each type needs', function (): void {
    expect(DepositType::None->requiredField())->toBeNull()
        ->and(DepositType::Percent->requiredField())->toBe('deposit_percent')
        ->and(DepositType::Fixed->requiredField())->toBe('deposit_fixed_cents')
        ->and(DepositType::None->isPartial())->toBeFalse()
        ->and(DepositType::Percent->isPartial())->toBeTrue();
})->group('fast');
