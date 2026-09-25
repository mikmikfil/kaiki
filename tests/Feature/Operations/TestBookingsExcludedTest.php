<?php

declare(strict_types=1);

use App\Domain\Analytics\Support\AnalyticsFigures;
use App\Domain\Analytics\Support\LocalRange;
use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\DashboardFigures;
use App\Domain\Operations\Support\Manifest;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\ManifestColumn;
use App\Events\BookingConfirmed;
use App\Events\DepartureGuaranteed;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Test bookings hold seats, and count as nobody (2026-09-25)
|--------------------------------------------------------------------------
|
| The rule (TestSeats): a live test booking keeps its seats in `seats_sold`,
| so nothing oversells a seat a test is sitting on and a cancelled test gives
| its seat back the ordinary way. It never counts as a guest: not towards
| `min_pax` (a guarantee is irreversible), not on the port manifest, and not
| in the pax and occupancy figures.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Departure} */
function sandboxSailing(int $minPax = 0, string $localDate = '2026-06-21', string $localTime = '10:00'): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $departure = Tenancy::forTenant($tenant, static fn (): Departure => Departure::factory()
        ->at($localDate, $localTime, 180)
        ->create([
            'capacity' => 10,
            'seats_sold' => 0,
            'seats_held' => 0,
            'min_pax' => $minPax,
        ]));

    return [$tenant, $departure];
}

it('does not guarantee a sailing on test seats, but still holds them', function (): void {
    Event::fake([BookingConfirmed::class, DepartureGuaranteed::class]);

    [$tenant, $departure] = sandboxSailing(minPax: 2);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $test = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create(['is_test' => true]);
        $departure->forceFill(['seats_held' => 2])->save();

        app(ConfirmBooking::class)($test);

        expect($departure->refresh()->status)->toBe(DepartureStatus::Scheduled)
            ->and($departure->seats_sold)->toBe(2);

        $real = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();
        $departure->forceFill(['seats_held' => 2])->save();

        app(ConfirmBooking::class)($real);

        expect($departure->refresh()->status)->toBe(DepartureStatus::Guaranteed)
            ->and($departure->seats_sold)->toBe(4);
    });

    Event::assertDispatchedTimes(DepartureGuaranteed::class, 1);
})->group('fast');

it('keeps test bookings off the manifest and out of the figures', function (): void {
    [$tenant, $departure] = sandboxSailing(minPax: 4, localDate: '2026-06-20', localTime: '18:00');

    Tenancy::forTenant($tenant, function () use ($departure): void {
        Booking::factory()->forDeparture($departure)->withPax(2, 2)->create([
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Ελένη Πραγματική',
        ]);
        Booking::factory()->forDeparture($departure)->withPax(3, 3)->create([
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Δοκιμή Sandbox',
            'is_test' => true,
        ]);
        // Both committed, as ConfirmBooking would have left them.
        $departure->forceFill(['seats_sold' => 5])->save();

        $manifest = Manifest::forDeparture($departure->refresh(), ManifestColumn::defaults());

        expect($manifest->onBoard)->toBe(2)
            ->and(json_encode($manifest->rows, JSON_UNESCAPED_UNICODE))->not->toContain('Sandbox');

        expect((new DashboardFigures('Europe/Athens'))->todayAndTomorrow()['pax'])->toBe(2)
            // 2 real of a minimum of 4: still short, whatever the tests say.
            ->and((new DashboardFigures('Europe/Athens'))->atRiskDepartures())->toBe(1)
            ->and(array_map(static fn ($item): string => $item->key, (new AttentionItems('Europe/Athens'))->everything()))
            ->toContain('departure:' . $departure->getKey());
    });

    // Sailed: the occupancy figures read it.
    Carbon::setTestNow('2026-06-21 09:00:00');

    Tenancy::forTenant($tenant, function (): void {
        $occupancy = (new AnalyticsFigures('Europe/Athens'))->occupancy(LocalRange::between('2026-06-01', '2026-06-30', 'Europe/Athens'));

        expect($occupancy['sold'])->toBe(2)
            ->and($occupancy['capacity'])->toBe(10);
    });
})->group('fast');
