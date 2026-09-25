<?php

declare(strict_types=1);

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Booking\Actions\ImportBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Models\Booking;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Api\BookingApiScenario;

/*
|--------------------------------------------------------------------------
| An imported charter lands on its own local day (AVL-16)
|--------------------------------------------------------------------------
|
| A per-vessel import has no departure, so the booking is placed by the UTC
| instant `CommitImport` resolved. A night charter at 01:00 on 5 July in Athens
| is 22:00 on 4 July in UTC, and its `local_date` has to say the 5th.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('writes the tenant-local date of a charter that starts after local midnight', function (): void {
    $fixture = BookingApiScenario::bookable();

    $booking = Tenancy::forTenant($fixture['tenant'], function () use ($fixture): Booking {
        $product = $fixture['product'];
        $product->forceFill([
            'mode' => BookingMode::PerVessel,
            'vessel_id' => $fixture['departure']->vessel_id,
            'duration_minutes' => 120,
        ])->save();

        $instant = LocalDateTimeResolver::resolveForTenant('2026-07-05', '01:00')->instantOrFail();

        expect($instant->toDateTimeString())->toBe('2026-07-04 22:00:00');

        return app(ImportBooking::class)(
            new BookingDraftData(
                product: $product->refresh(),
                date: $instant,
                guestName: 'Ελένη Δημητρίου',
                guestEmail: 'eleni@example.gr',
                source: BookingSource::Import,
                paxByCode: ['adult' => 2],
                startTime: '01:00',
            ),
            totalCents: 90_000,
            paidCents: 0,
            departure: null,
        );
    });

    expect($booking->local_date->toDateString())->toBe('2026-07-05')
        ->and(substr((string) $booking->local_time, 0, 5))->toBe('01:00')
        ->and($booking->starts_at_utc->toDateTimeString())->toBe('2026-07-04 22:00:00')
        ->and($booking->ends_at_utc->toDateTimeString())->toBe('2026-07-05 00:00:00');
})->group('fast');
