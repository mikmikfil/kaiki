<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\ImportBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Enums\BookingStatus;
use App\Models\Departure;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Api\BookingApiScenario;

/*
|--------------------------------------------------------------------------
| Imported seats are always counted (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| An import is never refused for overselling — it says what already happened
| elsewhere. But the refused counter update used to be ignored, so a sailing
| of 20 with 18 sold online and 4 imported still read 18/20: the widget sold
| two more seats, and a later cancellation took back seats never added.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-03 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts imported seats past the capacity, so nothing sells them again', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 20);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $departure = $fixture['departure'];
        $departure->forceFill(['seats_sold' => 18])->save();

        $booking = app(ImportBooking::class)(
            new BookingDraftData(
                product: $fixture['product'],
                date: $departure->local_date->copy(),
                guestName: 'Ελένη Δημητρίου',
                guestEmail: 'eleni@example.gr',
                paxByCode: ['adult' => 4],
            ),
            totalCents: 26000,
            paidCents: 26000,
            departure: $departure,
        );

        $fresh = Departure::query()->findOrFail($departure->getKey());

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($fresh->seats_sold)->toBe(22)
            ->and($fresh->capacity - $fresh->seats_sold - $fresh->seats_held)->toBeLessThan(0);
    });
})->group('fast');

it('still counts through the ordinary statement when there is room', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 20);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $departure = $fixture['departure'];

        app(ImportBooking::class)(
            new BookingDraftData(
                product: $fixture['product'],
                date: $departure->local_date->copy(),
                guestName: 'Ελένη Δημητρίου',
                guestEmail: 'eleni@example.gr',
                paxByCode: ['adult' => 4],
            ),
            totalCents: 26000,
            paidCents: 0,
            departure: $departure,
        );

        expect(Departure::query()->findOrFail($departure->getKey())->seats_sold)->toBe(4);
    });
})->group('fast');

it('gives back exactly what it counted when the imported booking is cancelled', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 20);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $departure = $fixture['departure'];
        $departure->forceFill(['seats_sold' => 18])->save();

        $booking = app(ImportBooking::class)(
            new BookingDraftData(
                product: $fixture['product'],
                date: $departure->local_date->copy(),
                guestName: 'Ελένη Δημητρίου',
                guestEmail: 'eleni@example.gr',
                paxByCode: ['adult' => 4],
            ),
            totalCents: 0,
            paidCents: 0,
            departure: $departure,
        );

        app(CancelBooking::class)($booking);

        // 18 again — not 16, which is what releasing four never added made it.
        expect(Departure::query()->findOrFail($departure->getKey())->seats_sold)->toBe(18);
    });
})->group('fast');
