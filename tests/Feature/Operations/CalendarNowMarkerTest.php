<?php

declare(strict_types=1);

use App\Domain\Operations\Support\CalendarDay;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The "now" marker, and the day that is not 24 hours long
|--------------------------------------------------------------------------
|
| A marker positioned by `hour / 24` beside bars positioned by the day's real
| length drifts by up to an hour on the two days a year the clocks move — and it
| drifts in the most misleading way available, because the marker is the thing
| an operator reads the bars *against*.
|
| The spring day in Athens is 23 hours. Noon is therefore 12/23 of the way
| across it, not 12/24, and the difference is about half an hour of track.
|
*/

it('places the marker by the day\'s real length, not by a 24-hour assumption', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    // 29 March 2026: the clocks go forward, and the day is 23 hours long.
    Carbon::setTestNow('2026-03-29T09:00:00Z');

    $fraction = Tenancy::forTenant($tenant, static fn (): ?float => CalendarDay::for('2026-03-29', 'Europe/Athens')->nowFraction());

    // 09:00 UTC is 12:00 in Athens on that date, and the day started at 00:00
    // local — which is 22:00 UTC the previous evening. Eleven hours in, of
    // twenty-three.
    expect($fraction)->not->toBeNull()
        ->and(round((float) $fraction, 4))->toBe(round(11 / 23, 4))
        // The 24-hour answer would put it here, about half an hour of track
        // away from where the bars are drawn.
        ->and(round((float) $fraction, 4))->not->toBe(round(11 / 24, 4));
});

it('has no marker on a day that is not today', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    Carbon::setTestNow('2026-09-08T09:00:00Z');

    $fraction = Tenancy::forTenant($tenant, static fn (): ?float => CalendarDay::for('2026-09-20', 'Europe/Athens')->nowFraction());

    // Null rather than clamped to an edge. A line pinned to midnight on a day
    // the operator is looking *ahead* to reads as an occupation, and a marker
    // that lies about the time is worse than no marker.
    expect($fraction)->toBeNull();
});

it('places it at the start and never past the end', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    // Midnight in Athens, which is the first instant of the local day.
    Carbon::setTestNow('2026-09-07T21:00:00Z');

    $start = Tenancy::forTenant($tenant, static fn (): ?float => CalendarDay::for('2026-09-08', 'Europe/Athens')->nowFraction());

    expect($start)->toBe(0.0);

    // One second before the day ends is still inside it; one second after is
    // not, and the exclusive bound is what stops a marker appearing on two
    // days at once.
    Carbon::setTestNow('2026-09-08T20:59:59Z');
    $end = Tenancy::forTenant($tenant, static fn (): ?float => CalendarDay::for('2026-09-08', 'Europe/Athens')->nowFraction());

    Carbon::setTestNow('2026-09-08T21:00:00Z');
    $after = Tenancy::forTenant($tenant, static fn (): ?float => CalendarDay::for('2026-09-08', 'Europe/Athens')->nowFraction());

    expect($end)->not->toBeNull()
        ->and((float) $end)->toBeLessThan(1.0)
        ->and($after)->toBeNull();
});
