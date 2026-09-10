<?php

declare(strict_types=1);

use App\Domain\Pricing\Support\DepositCalculator;
use App\Enums\DepositType;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| Deposits — spec PRC-23, PRC-24
|--------------------------------------------------------------------------
|
| Every clamp below is a real failure. A €200 standing deposit on a €150 seat
| would otherwise overcharge; 1% of €0.50 would otherwise take a payment of
| nothing and leave the booking looking paid; a free booking would otherwise
| carry a one-cent charge nobody can explain.
|
| `depositsEnabled` is passed rather than looked up. It is the operator's own
| switch — `tenants.deposits_enabled` — and the calculator stays arithmetic, so
| the same plan and the same total always give the same answer. Switched off it
| means what `DepositType::None` has always meant: the whole total, now.
|
| The tenant below is for the *factory*, not the calculator — a rate plan belongs
| to a vessel and a vessel belongs to an operator (TEN-4).
|
*/

function depositTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

it('takes the whole total when no deposit is configured', function (): void {
    depositTenant(function (): void {
        // "No deposit" means pay in full now, not pay nothing.
        $plan = RatePlan::factory()->create();

        expect(DepositCalculator::amountCents($plan, 15000, depositsEnabled: true))->toBe(15000)
            ->and(DepositCalculator::balanceCents($plan, 15000, depositsEnabled: true))->toBe(0);
    });
})->group('fast');

it('takes a percentage, rounded half up', function (): void {
    depositTenant(function (): void {
        $plan = RatePlan::factory()->depositPercent(30)->create();

        expect(DepositCalculator::amountCents($plan, 14250, depositsEnabled: true))->toBe(4275)
            // 30% of 3333 is 999.9 — half up, and never 999.
            ->and(DepositCalculator::amountCents($plan, 3333, depositsEnabled: true))->toBe(1000);
    });
})->group('fast');

it('never lets a percentage round down to nothing', function (): void {
    depositTenant(function (): void {
        // 1% of 50 cents is half a cent. Rounding to zero would take a payment
        // of nothing and leave the booking looking paid.
        $plan = RatePlan::factory()->depositPercent(1)->create();

        expect(DepositCalculator::amountCents($plan, 50, depositsEnabled: true))->toBe(1);
    });
})->group('fast');

it('clamps a fixed deposit to the total and calls the rest paid', function (): void {
    depositTenant(function (): void {
        // PRC-24: a deposit at or above the total is full payment.
        $plan = RatePlan::factory()->depositFixed(20000)->create();

        expect(DepositCalculator::amountCents($plan, 15000, depositsEnabled: true))->toBe(15000)
            ->and(DepositCalculator::balanceCents($plan, 15000, depositsEnabled: true))->toBe(0);
    });
})->group('fast');

it('leaves a balance when the deposit is less than the total', function (): void {
    depositTenant(function (): void {
        $plan = RatePlan::factory()->depositFixed(5000)->create();

        expect(DepositCalculator::amountCents($plan, 15000, depositsEnabled: true))->toBe(5000)
            ->and(DepositCalculator::balanceCents($plan, 15000, depositsEnabled: true))->toBe(10000);
    });
})->group('fast');

it('takes nothing at all on a zero total', function (): void {
    depositTenant(function (): void {
        // There is nothing to hold, and a one-cent charge against a free
        // booking is a payment row nobody can account for.
        foreach ([
            RatePlan::factory()->create(),
            RatePlan::factory()->depositPercent(30)->create(),
            RatePlan::factory()->depositFixed(5000)->create(),
        ] as $plan) {
            expect(DepositCalculator::amountCents($plan, 0, depositsEnabled: true))->toBe(0)
                ->and(DepositCalculator::balanceCents($plan, 0, depositsEnabled: true))->toBe(0);
        }
    });
})->group('fast');

it('records the type and percentage in the snapshot block', function (): void {
    depositTenant(function (): void {
        $plan = RatePlan::factory()->depositPercent(30)->create();

        expect(DepositCalculator::forPlan($plan, 14250, depositsEnabled: true))
            ->toBe(['type' => 'percent', 'percent' => 30, 'amount_cents' => 4275]);
    });
})->group('fast');

it('records no percentage for a fixed deposit', function (): void {
    depositTenant(function (): void {
        expect(DepositCalculator::forPlan(RatePlan::factory()->depositFixed(5000)->create(), 15000, depositsEnabled: true))
            ->toBe(['type' => 'fixed', 'percent' => null, 'amount_cents' => 5000]);
    });
})->group('fast');

it('takes the whole total when the operator does not do deposits', function (): void {
    depositTenant(function (): void {
        // The plan says 30%; the business does not take instalments. The
        // business wins — and «off» means the whole total now, not nothing.
        $plan = RatePlan::factory()->depositPercent(30)->create();

        expect(DepositCalculator::amountCents($plan, 15000, depositsEnabled: false))->toBe(15000)
            ->and(DepositCalculator::balanceCents($plan, 15000, depositsEnabled: false))->toBe(0);
    });
})->group('fast');

it('records no deposit type at all when the operator does not do deposits', function (): void {
    depositTenant(function (): void {
        // The half a guest reads. A snapshot saying «30% deposit» beside a
        // button charging all of it is the mismatch this asserts cannot happen.
        $plan = RatePlan::factory()->depositPercent(30)->create();

        expect(DepositCalculator::forPlan($plan, 15000, depositsEnabled: false))
            ->toBe(['type' => DepositType::None->value, 'percent' => null, 'amount_cents' => 15000]);
    });
})->group('fast');
