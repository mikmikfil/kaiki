<?php

declare(strict_types=1);

use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\Window;
use App\Domain\Booking\Actions\ExpireQuotes;
use App\Domain\Booking\Actions\SendQuote;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\QuoteScenario;

/*
|--------------------------------------------------------------------------
| «Κράτα το σκάφος» holds the charter's own hours (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| The block ran from the charter's start to the quote's `valid_until`. A
| charter on 15 October quoted on 25 September (valid to 2 October) got a
| block that ended before it began, so the boat stayed on sale; a charter on
| 28 September was blocked until 2 October, four days too long.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-09-25 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A drafted quote for a charter at these UTC hours, valid for a week.
 *
 * @return array{0: Tenant, 1: Booking, 2: Quote}
 */
function quotedCharter(string $startsUtc, string $endsUtc, string $localDate): array
{
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote, $startsUtc, $endsUtc, $localDate): void {
        $tenant = $booking->tenant;
        $tenant?->forceFill(['timezone' => 'Europe/Athens'])->save();

        $booking->forceFill([
            'local_date' => $localDate,
            'starts_at_utc' => Carbon::parse($startsUtc),
            'ends_at_utc' => Carbon::parse($endsUtc),
        ])->save();

        $quote->forceFill(['valid_until' => Carbon::parse('2026-10-02 10:00:00')])->save();
    });

    return [$tenant, $booking->refresh(), $quote->refresh()];
}

it('holds a charter further out than the quote is valid', function (): void {
    // 15 October, 09:00–17:00 in Athens.
    [$tenant, $booking, $quote] = quotedCharter('2026-10-15 06:00:00', '2026-10-15 14:00:00', '2026-10-15');

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote, holdVessel: true);

        $block = VesselBlock::query()->sole();
        $vessel = Vessel::query()->findOrFail($booking->vessel_id);
        $window = Window::of($booking->starts_at_utc, $booking->ends_at_utc);

        expect($block->starts_at_utc->toDateTimeString())->toBe('2026-10-15 06:00:00')
            ->and($block->ends_at_utc->toDateTimeString())->toBe('2026-10-15 14:00:00')
            ->and($block->local_end_date->toDateString())->toBe('2026-10-15')
            // The boat is off sale for exactly those hours.
            ->and(OccupationCollector::forRange($vessel, $window)->isFree($window))->toBeFalse();
    });
})->group('fast');

it('does not keep a near charter off sale until the quote lapses', function (): void {
    // 28 September, 09:00–17:00; the quote is valid to 2 October.
    [$tenant, $booking, $quote] = quotedCharter('2026-09-28 06:00:00', '2026-09-28 14:00:00', '2026-09-28');

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote, holdVessel: true);

        $vessel = Vessel::query()->findOrFail($booking->vessel_id);
        $nextDay = Window::of(Carbon::parse('2026-09-29 06:00:00'), Carbon::parse('2026-09-29 14:00:00'));

        expect(VesselBlock::query()->sole()->ends_at_utc->toDateTimeString())->toBe('2026-09-28 14:00:00')
            ->and(OccupationCollector::forRange($vessel, $nextDay)->isFree($nextDay))->toBeTrue();
    });
})->group('fast');

it('dates the end on the operator calendar, not in UTC', function (): void {
    // 20:00 to 01:30 in Athens: the end is the next local day.
    [$tenant, $booking, $quote] = quotedCharter('2026-10-15 17:00:00', '2026-10-15 22:30:00', '2026-10-15');

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote, holdVessel: true);

        expect(VesselBlock::query()->sole()->local_end_date->toDateString())->toBe('2026-10-16');
    });
})->group('fast');

it('frees the sailings it closed when the quote expires', function (): void {
    [$tenant, $booking, $quote] = quotedCharter('2026-10-15 06:00:00', '2026-10-15 14:00:00', '2026-10-15');

    $sailing = Tenancy::forTenant($tenant, fn (): Departure => Departure::factory()->create([
        'vessel_id' => $booking->vessel_id,
        'starts_at_utc' => Carbon::parse('2026-10-15 08:00:00'),
        'ends_at_utc' => Carbon::parse('2026-10-15 10:00:00'),
        'local_date' => '2026-10-15',
        'local_time' => '11:00:00',
        'seats_sold' => 0,
        'seats_held' => 0,
    ]));

    Tenancy::forTenant($tenant, function () use ($quote, $sailing): void {
        app(SendQuote::class)($quote, holdVessel: true);

        expect($sailing->fresh()?->is_blocked)->toBeTrue();
    });

    Carbon::setTestNow('2026-10-02 10:01:00');
    app(ExpireQuotes::class)();

    Tenancy::forTenant($tenant, function () use ($sailing): void {
        expect(VesselBlock::query()->count())->toBe(0)
            // Deleted one by one, so the observer put the sailing back on sale.
            ->and($sailing->fresh()?->is_blocked)->toBeFalse();
    });
})->group('fast');
