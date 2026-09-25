<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\RemoveGuestsFromBooking;
use App\Domain\Booking\Support\ManifestRows;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| Taking people off counts seats, as the draft door does (audit 2)
|--------------------------------------------------------------------------
|
| The trip's minimum is in seats (CAT-5, PartyGuard::bounds). Counted in
| people, two adults and a baby on a trip of «at least two» could lose an
| adult and keep one seat. And the rules are asked again under the booking's
| lock, so two tabs each taking off a different adult cannot leave a child
| alone between them.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    CancellationScenario::fakeGatewayResponses();
    Mail::fake();
    Carbon::setTestNow('2026-06-20 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, array{int, bool, int}>  $party  code => [qty, takes a seat, unit price]
 * @return array{0: Tenant, 1: Booking}
 */
function partyOnBoard(array $party, int $minimum = 1): array
{
    $total = array_sum(array_map(static fn (array $line): int => $line[0] * $line[2], $party));

    [$tenant, $booking] = CancellationScenario::make(paidCents: $total, ladder: [0 => 100]);

    Tenancy::forTenant($tenant, function () use ($booking, $party, $total, $minimum): void {
        $lines = [];

        foreach ($party as $code => [$qty, $seat, $price]) {
            $lines[] = ['code' => $code, 'label' => ['el' => $code, 'en' => $code], 'qty' => $qty, 'counts_toward_capacity' => $seat, 'unit_price_cents' => $price, 'total_cents' => $qty * $price];

            $booking->product->ageBands()->create([
                'code' => $code, 'label' => ['el' => $code, 'en' => $code], 'min_age' => $code === 'adult' ? 18 : 0, 'max_age' => $code === 'adult' ? null : 11,
                'counts_toward_capacity' => $seat, 'is_base' => $code === 'adult', 'requires_adult' => $code !== 'adult', 'sort_order' => 1,
            ]);
        }

        $seats = array_sum(array_map(static fn (array $line): int => $line[1] ? $line[0] : 0, $party));

        $booking->forceFill([
            'pax_total' => array_sum(array_column($party, 0)),
            'pax_capacity_total' => $seats,
            'pax_breakdown' => $lines,
            'subtotal_cents' => $total,
            'total_cents' => $total,
            'deposit_cents' => $total,
        ])->save();

        $booking->product->forceFill(['min_booking_pax' => $minimum])->save();

        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => $seats]);

        ManifestRows::ensure($booking);
    });

    return [$tenant, Tenancy::forTenant($tenant, fn (): Booking => $booking->refresh())];
}

it('counts the minimum in seats: two adults and a baby cannot lose an adult on a trip of at least two', function (): void {
    [$tenant, $booking] = partyOnBoard(['adult' => [2, true, 5000], 'infant' => [1, false, 0]], minimum: 2);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(RemoveGuestsFromBooking::class)($booking, ['adult' => 1]))
            ->toThrow(ValidationException::class, (string) __('bookings.remove_guests.validation.below_minimum', ['minimum' => 2]));

        expect($booking->refresh()->pax_total)->toBe(3);
    });
})->group('fast');

it('never leaves only passengers who take no seat', function (): void {
    [$tenant, $booking] = partyOnBoard(['adult' => [1, true, 5000], 'infant' => [1, false, 0]]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(RemoveGuestsFromBooking::class)($booking, ['adult' => 1]))
            ->toThrow(ValidationException::class, (string) __('bookings.remove_guests.validation.no_seat'));
    });
})->group('fast');

it('asks again under the lock, so two tabs cannot leave a child alone between them', function (): void {
    [$tenant, $booking] = partyOnBoard(['adult' => [2, true, 5000], 'child' => [1, true, 3000]]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // The second tab loaded the booking before the first one saved.
        $stale = Booking::query()->findOrFail($booking->getKey());

        app(RemoveGuestsFromBooking::class)($booking, ['adult' => 1]);

        expect(fn () => app(RemoveGuestsFromBooking::class)($stale, ['adult' => 1]))
            ->toThrow(ValidationException::class, (string) __('bookings.remove_guests.validation.needs_adult'));

        expect($booking->refresh()->pax_total)->toBe(2);
    });
})->group('fast');
