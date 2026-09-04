<?php

declare(strict_types=1);

use App\Domain\Availability\Support\ProposedWindowBuilder;
use App\Enums\AvailabilityRejection;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| The window a charter asks for — spec AVL-6, AVL-30, AVL-31
|--------------------------------------------------------------------------
|
| AVL-31 bounds a guest proposal three ways, and each stops a different kind of
| nonsense reaching an operator's calendar:
|
| - a **15-minute grid**, because 09:07 is not a time anybody schedules and
|   every downstream display would have to decide whether to round it;
| - an **operating window**, because a boat chartered at 03:00 is a boat whose
|   crew finds out at 03:00;
| - **whole-hour extensions with a ceiling**, because `extra_hour_price_cents`
|   has no price for half an hour, and an unbounded extension lets one request
|   block a vessel calendar for a fortnight.
|
| Each is asserted at the boundary and one unit either side, because a bound
| tested only in the middle is a bound that can be off by one.
|
*/

function windowTenantFor(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

/** @param array<string, mixed> $overrides */
function charterProduct(array $overrides = []): Product
{
    return Product::factory()->perVessel()->create(array_merge([
        'duration_minutes' => 240,
        'default_start_time' => '10:00',
        'flexible_start' => true,
        'earliest_start_time' => null,
        'latest_start_time' => null,
    ], $overrides));
}

it('builds the default window when nothing is proposed', function (): void {
    windowTenantFor(function (): void {
        $result = ProposedWindowBuilder::build(charterProduct(), '2026-07-04');

        expect($result['rejection'])->toBeNull()
            // 10:00 Athens is 07:00 UTC, and four hours of it.
            ->and($result['window']?->startUtc->toDateTimeString())->toBe('2026-07-04 07:00:00')
            ->and($result['window']?->endUtc->toDateTimeString())->toBe('2026-07-04 11:00:00');
    });
})->group('fast');

it('accepts a proposal on the quarter hour', function (string $time): void {
    windowTenantFor(function () use ($time): void {
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', $time)['rejection'])->toBeNull();
    });
})->with(['09:00', '09:15', '09:30', '09:45'])->group('fast');

it('refuses a proposal one minute off the grid', function (): void {
    windowTenantFor(function (): void {
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '09:16')['rejection'])
            ->toBe(AvailabilityRejection::OffGrid);
    });
})->group('fast');

it('refuses a proposal carrying seconds', function (): void {
    windowTenantFor(function (): void {
        // On the grid to the minute and still not a time an operator schedules.
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '09:15:30')['rejection'])
            ->toBe(AvailabilityRejection::OffGrid);
    });
})->group('fast');

it('accepts a start exactly at the earliest hour', function (): void {
    windowTenantFor(function (): void {
        // The default operating window opens at 06:00, and "exactly at" must
        // pass or the bound means "after".
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '06:00')['rejection'])->toBeNull();
    });
})->group('fast');

it('refuses a start one grid step before the earliest hour', function (): void {
    windowTenantFor(function (): void {
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '05:45')['rejection'])
            ->toBe(AvailabilityRejection::OutsideOperatingWindow);
    });
})->group('fast');

it('accepts a start exactly at the latest hour', function (): void {
    windowTenantFor(function (): void {
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '23:00')['rejection'])->toBeNull();
    });
})->group('fast');

it('refuses a start after the latest hour', function (): void {
    windowTenantFor(function (): void {
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '23:15')['rejection'])
            ->toBe(AvailabilityRejection::OutsideOperatingWindow);
    });
})->group('fast');

it('lets the product narrow the operating window', function (): void {
    windowTenantFor(function (): void {
        // The operator's own hours win over the platform default — they are
        // what somebody typed, and a sunset cruise does not leave at six.
        $product = charterProduct(['earliest_start_time' => '17:00', 'latest_start_time' => '19:00']);

        expect(ProposedWindowBuilder::build($product, '2026-07-04', '09:00')['rejection'])
            ->toBe(AvailabilityRejection::OutsideOperatingWindow)
            ->and(ProposedWindowBuilder::build($product, '2026-07-04', '18:00')['rejection'])->toBeNull();
    });
})->group('fast');

it('extends the window by whole hours', function (): void {
    windowTenantFor(function (): void {
        $result = ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '09:00', extraHours: 2);

        // Four hours plus two: 06:00 to 12:00 UTC.
        expect($result['window']?->minutes())->toBe(360)
            ->and($result['window']?->endUtc->toDateTimeString())->toBe('2026-07-04 12:00:00');
    });
})->group('fast');

it('accepts an extension exactly at the maximum', function (): void {
    windowTenantFor(function (): void {
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '09:00', extraHours: 6)['rejection'])
            ->toBeNull();
    });
})->group('fast');

it('refuses one hour beyond the maximum', function (): void {
    windowTenantFor(function (): void {
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '09:00', extraHours: 7)['rejection'])
            ->toBe(AvailabilityRejection::ExtensionTooLong);
    });
})->group('fast');

it('refuses a negative extension', function (): void {
    windowTenantFor(function (): void {
        // The shape of a free-hours exploit: shortening the tested window while
        // paying for the original.
        expect(ProposedWindowBuilder::build(charterProduct(), '2026-07-04', '09:00', extraHours: -2)['rejection'])
            ->toBe(AvailabilityRejection::ExtensionTooLong);
    });
})->group('fast');

it('ignores a proposal on a fixed-start charter', function (): void {
    windowTenantFor(function (): void {
        // `flexible_start` false means the operator decided the time. A
        // proposal is not refused, it simply does not apply — refusing it would
        // make a widget that always posts a time unusable on half the catalogue.
        $product = charterProduct(['flexible_start' => false, 'default_start_time' => '10:00']);

        $result = ProposedWindowBuilder::build($product, '2026-07-04', '09:07');

        expect($result['rejection'])->toBeNull()
            ->and($result['window']?->startUtc->toDateTimeString())->toBe('2026-07-04 07:00:00');
    });
})->group('fast');

it('refuses a local time that does not exist on the spring-forward date', function (): void {
    windowTenantFor(function (): void {
        // ADR-0016, with its own code because the guest's remedy — move by half
        // an hour — belongs to no other reason. The operating window is widened
        // for this one, or the proposal would be refused as out-of-hours first
        // and the DST branch would never run.
        $product = charterProduct(['earliest_start_time' => '00:00']);

        expect(ProposedWindowBuilder::build($product, '2026-03-29', '03:30')['rejection'])
            ->toBe(AvailabilityRejection::DstNonExistent);
    });
})->group('fast');

it('reports a charter with no time to propose at all', function (): void {
    windowTenantFor(function (): void {
        // Guessing 09:00 here would put a boat on a calendar at a time nobody
        // chose.
        $product = charterProduct(['default_start_time' => null, 'flexible_start' => false]);

        expect(ProposedWindowBuilder::build($product, '2026-07-04')['rejection'])
            ->toBe(AvailabilityRejection::NoProposedWindow);
    });
})->group('fast');
