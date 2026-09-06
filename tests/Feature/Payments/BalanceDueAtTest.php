<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ComputeBalanceDueAt;
use App\Models\Booking;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| PRC-27.1 … PRC-27.3, ADR-0018: when a balance falls due
|--------------------------------------------------------------------------
|
| **The 09:00 floor is timezone arithmetic, not instant arithmetic**, and that
| is the whole reason these tests exist. `starts_at_utc->subDays(14)->setTime(9)`
| sets nine o'clock *UTC* — midday in Athens in summer, eleven in winter, and
| shifting by an hour across each DST boundary, silently, twice a year.
|
| The issue says so outright: *"use `LocalDateTimeResolver`; do not compute
| `subDays()` on a UTC instant and hope."* The tests below are what makes that
| checkable rather than merely written down.
|
*/

/**
 * A confirmed booking with a balance, departing at a given local time.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function balanceScenario(string $localDeparture = '2026-08-20 10:00', int $balanceCents = 6000): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $booking = Tenancy::forTenant($tenant, static function () use ($localDeparture, $balanceCents): Booking {
        $starts = Carbon::parse($localDeparture, 'Europe/Athens')->utc();

        return Booking::factory()->create([
            'starts_at_utc' => $starts,
            'ends_at_utc' => $starts->copy()->addHours(6),
            'local_date' => $starts->copy()->setTimezone('Europe/Athens')->toDateString(),
            'total_cents' => 12000,
            'paid_cents' => 12000 - $balanceCents,
            'balance_cents' => $balanceCents,
        ]);
    });

    return [$tenant, $booking];
}

it('falls due at 09:00 in the tenant timezone, not 09:00 UTC', function (): void {
    [$tenant, $booking] = balanceScenario('2026-08-20 10:00');

    $due = Tenancy::forTenant($tenant, fn (): ?Carbon => app(ComputeBalanceDueAt::class)($booking, Carbon::parse('2026-07-01 12:00', 'UTC')));

    expect($due)->not->toBeNull();

    // Fourteen local days before 20 August is 6 August, and 09:00 Athens in
    // August is 06:00 UTC. A naive `setTime(9, 0)` on the UTC instant would
    // give 09:00 UTC — three hours late, every day of the summer.
    expect($due?->copy()->setTimezone('Europe/Athens')->format('Y-m-d H:i'))->toBe('2026-08-06 09:00')
        ->and($due?->utc()->format('H:i'))->toBe('06:00');
})->group('fast');

it('is still 09:00 local on the other side of the clock change', function (): void {
    // A departure in November, whose due date lands in late October — after
    // Greece has gone back to EET. The *local* time must be identical; the UTC
    // instant must differ by an hour from the summer case.
    [$tenant, $booking] = balanceScenario('2026-11-10 10:00');

    $due = Tenancy::forTenant($tenant, fn (): ?Carbon => app(ComputeBalanceDueAt::class)($booking, Carbon::parse('2026-09-01 12:00', 'UTC')));

    expect($due?->copy()->setTimezone('Europe/Athens')->format('Y-m-d H:i'))->toBe('2026-10-27 09:00')
        // 09:00 EET is 07:00 UTC, against 06:00 in summer. That one hour is
        // the entire bug this test exists to catch: it is invisible in any
        // single-season fixture.
        ->and($due?->utc()->format('H:i'))->toBe('07:00');
})->group('fast');

it('counts calendar days, not multiples of 24 hours', function (): void {
    // The distinction AVL-19 and AVL-20 already draw. Across a clock change a
    // fortnight is fourteen calendar days and 335 or 337 hours — and the guest
    // was promised the days.
    [$tenant, $booking] = balanceScenario('2026-11-10 10:00');

    $due = Tenancy::forTenant($tenant, fn (): ?Carbon => app(ComputeBalanceDueAt::class)($booking, Carbon::parse('2026-09-01 12:00', 'UTC')));

    $localDeparture = $booking->starts_at_utc->copy()->setTimezone('Europe/Athens')->toDateString();
    $localDue = $due?->copy()->setTimezone('Europe/Athens')->toDateString();

    expect(Carbon::parse($localDue)->diffInDays(Carbon::parse($localDeparture)))->toBe(14.0);
})->group('fast');

it('lets a rate plan override the tenant setting', function (): void {
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/Athens',
        'balance_due_days_before_departure' => 14,
    ]);

    $due = Tenancy::forTenant($tenant, function (): ?Carbon {
        // PRC-27.1's precedence: the plan wins where it is set.
        $plan = RatePlan::factory()->create(['balance_due_days_before_departure' => 30]);

        $starts = Carbon::parse('2026-08-20 10:00', 'Europe/Athens')->utc();

        $booking = Booking::factory()->create([
            'starts_at_utc' => $starts,
            'ends_at_utc' => $starts->copy()->addHours(6),
            'total_cents' => 12000,
            'paid_cents' => 6000,
            'balance_cents' => 6000,
            'price_snapshot' => ['rate_plan_id' => $plan->getKey()],
        ]);

        return app(ComputeBalanceDueAt::class)($booking, Carbon::parse('2026-07-01 12:00', 'UTC'));
    });

    expect($due?->copy()->setTimezone('Europe/Athens')->format('Y-m-d H:i'))->toBe('2026-07-21 09:00');
})->group('fast');

it('falls back to the tenant setting when the plan does not override it', function (): void {
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/Athens',
        'balance_due_days_before_departure' => 7,
    ]);

    $due = Tenancy::forTenant($tenant, function (): ?Carbon {
        // Null, not zero and not fourteen. The rate plan column has **no**
        // default precisely so "not set" is distinguishable from "set to the
        // same number" — otherwise changing the tenant setting would silently
        // do nothing.
        $plan = RatePlan::factory()->create(['balance_due_days_before_departure' => null]);

        $starts = Carbon::parse('2026-08-20 10:00', 'Europe/Athens')->utc();

        $booking = Booking::factory()->create([
            'starts_at_utc' => $starts,
            'ends_at_utc' => $starts->copy()->addHours(6),
            'total_cents' => 12000,
            'paid_cents' => 6000,
            'balance_cents' => 6000,
            'price_snapshot' => ['rate_plan_id' => $plan->getKey()],
        ]);

        return app(ComputeBalanceDueAt::class)($booking, Carbon::parse('2026-07-01 12:00', 'UTC'));
    });

    expect($due?->copy()->setTimezone('Europe/Athens')->format('Y-m-d H:i'))->toBe('2026-08-13 09:00');
})->group('fast');

it('gives a late confirmation 24 hours rather than a date already past', function (): void {
    // PRC-27.3. Ten days out, on a fourteen-day policy: the ordinary due date
    // is behind us, and a booking born overdue would send the guest an overdue
    // notice for money they have had no chance to pay.
    [$tenant, $booking] = balanceScenario('2026-08-20 10:00');

    $confirmedAt = Carbon::parse('2026-08-10 15:00', 'UTC');

    $due = Tenancy::forTenant($tenant, fn (): ?Carbon => app(ComputeBalanceDueAt::class)($booking, $confirmedAt));

    expect($due?->equalTo($confirmedAt->copy()->addDay()))->toBeTrue();
})->group('fast');

it('caps a late confirmation at two hours before departure', function (): void {
    // Confirmed the evening before. Twenty-four hours would be *after* the boat
    // sails, and a balance due after departure is not a due date.
    [$tenant, $booking] = balanceScenario('2026-08-20 10:00');

    $confirmedAt = Carbon::parse('2026-08-19 20:00', 'UTC');

    $due = Tenancy::forTenant($tenant, fn (): ?Carbon => app(ComputeBalanceDueAt::class)($booking, $confirmedAt));

    expect($due?->equalTo($booking->starts_at_utc->copy()->subHours(2)))->toBeTrue();
})->group('fast');

it('has no due date when there is nothing left to pay', function (): void {
    [$tenant, $booking] = balanceScenario(balanceCents: 0);

    // Null, not a date. A settled booking with a due date would sit in the
    // reminder scheduler's query forever, and in the "Υπόλοιπα" bucket.
    expect(Tenancy::forTenant($tenant, fn () => app(ComputeBalanceDueAt::class)($booking)))->toBeNull();
})->group('fast');

it('marks a booking overdue only when both halves are true', function (): void {
    [$tenant, $booking] = balanceScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['balance_due_at' => now()->subDay()])->save();

        expect($booking->balanceIsOverdue())->toBeTrue();

        // Paid since. The date is stale but there is nothing owed, so it does
        // not belong in the bucket.
        $booking->forceFill(['balance_cents' => 0])->save();

        expect($booking->balanceIsOverdue())->toBeFalse();
    });
})->group('fast');
