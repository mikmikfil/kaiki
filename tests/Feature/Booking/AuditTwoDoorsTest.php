<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\AttentionSeverity;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\IcalSource;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| Audit 2: an outside calendar over sold bookings, and trips already gone
|--------------------------------------------------------------------------
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
| An iCal block over bookings sold here cannot be refused — the other platform
| sold the boat — so the operator is told.
*/

/** @return list<AttentionItem> */
function clashItems(Tenant $tenant): array
{
    return array_values(array_filter(
        Tenancy::forTenant($tenant, fn (): array => (new AttentionItems($tenant->timezone))->everything()),
        static fn (AttentionItem $item): bool => str_starts_with($item->key, 'ical_clash:'),
    ));
}

it('puts an outside booking over a sold charter in front of the operator', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $booking = Tenancy::forTenant($tenant, function (): Booking {
        $vessel = Vessel::factory()->create(['name' => 'Θάλασσα']);
        $source = IcalSource::factory()->create(['vessel_id' => $vessel->getKey(), 'name' => 'Click&Boat']);

        $booking = Booking::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'mode' => BookingMode::PerVessel,
            'status' => BookingStatus::Confirmed,
            'reference' => 'KAI-CLASH',
            'local_date' => '2026-09-12',
            'local_time' => '10:00',
            'starts_at_utc' => Carbon::parse('2026-09-12 07:00:00'),
            'ends_at_utc' => Carbon::parse('2026-09-12 15:00:00'),
        ]);

        VesselBlock::factory()->externalIcal()->create([
            'vessel_id' => $vessel->getKey(),
            'ical_source_id' => $source->getKey(),
            'starts_at_utc' => Carbon::parse('2026-09-12 07:00:00'),
            'ends_at_utc' => Carbon::parse('2026-09-12 15:00:00'),
            'local_date' => '2026-09-12',
            'local_end_date' => '2026-09-12',
        ]);

        return $booking;
    });

    $items = clashItems($tenant);

    expect($items)->toHaveCount(1)
        ->and($items[0]->severity)->toBe(AttentionSeverity::Critical)
        ->and($items[0]->detail)->toContain('KAI-CLASH')
        ->and($items[0]->detail)->toContain('Click&Boat')
        ->and($items[0]->subject?->getKey())->toBe($booking->getKey())
        ->and(Tenancy::forTenant($tenant, fn (): int => (new AttentionItems($tenant->timezone))->count()))->toBeGreaterThanOrEqual(1);
})->group('fast');

it('says nothing about an outside booking on a free day', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create();

        // A booking on another day, and one cancelled under the block.
        Booking::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
            'starts_at_utc' => Carbon::parse('2026-09-13 07:00:00'),
            'ends_at_utc' => Carbon::parse('2026-09-13 15:00:00'),
        ]);
        Booking::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Cancelled,
            'starts_at_utc' => Carbon::parse('2026-09-12 07:00:00'),
            'ends_at_utc' => Carbon::parse('2026-09-12 15:00:00'),
        ]);

        VesselBlock::factory()->externalIcal()->create([
            'vessel_id' => $vessel->getKey(),
            'starts_at_utc' => Carbon::parse('2026-09-12 07:00:00'),
            'ends_at_utc' => Carbon::parse('2026-09-12 15:00:00'),
            'local_date' => '2026-09-12',
            'local_end_date' => '2026-09-12',
        ]);
    });

    expect(clashItems($tenant))->toBe([]);
})->group('fast');

/*
| POST /bookings with no sailing named: the cutoff is the sailing's own start.
*/

it('refuses a per-seat draft on a sailing that has already left, named by date and time', function (): void {
    // 09:30 in Athens; the 08:00 sailing has gone.
    Carbon::setTestNow('2026-07-03 06:30:00');

    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-03 05:00:00'));
    // A per-seat trip keeps its times on its sailings, not on the trip.
    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['product']->forceFill(['default_start_time' => null])->save());

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'departure_uuid' => null,
            'window' => ['local_date' => '2026-07-03', 'local_time' => '08:00'],
        ]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertUnprocessable();

    expect(Tenancy::forTenant($fixture['tenant'], fn (): int => Booking::query()->count()))->toBe(0)
        ->and($fixture['departure']->refresh()->seats_held)->toBe(0);
})->group('fast');

it('still takes a per-seat draft named by date and time for a later sailing', function (): void {
    Carbon::setTestNow('2026-07-03 06:30:00');

    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-03 14:00:00'));

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'departure_uuid' => null,
            'window' => ['local_date' => '2026-07-03', 'local_time' => '17:00'],
        ]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();
})->group('fast');

/*
| A panel booking for a trip that has already started is refused.
*/

it('refuses a phone booking on a sailing that has already left', function (): void {
    Carbon::setTestNow('2026-07-03 06:30:00');

    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-03 05:00:00'));

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $data = new BookingDraftData(
            product: $fixture['product'],
            date: $fixture['departure']->local_date->copy(),
            guestName: 'Γιώργος Νικολάου',
            guestEmail: 'giorgos@example.gr',
            guestPhone: '+306912345678',
            paxByCode: ['adult' => 2],
            departure: $fixture['departure'],
        );

        expect(fn () => app(CreateManualBooking::class)($data, payOnTheDay: true))
            ->toThrow(ValidationException::class, (string) __('bookings.trip_started'));

        expect(Booking::query()->count())->toBe(0);
    });
})->group('fast');

it('lets the quay sell a seat on a sailing that is a few minutes late', function (): void {
    // Ten minutes after the 08:00 start.
    Carbon::setTestNow('2026-07-03 05:10:00');

    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-03 05:00:00'));

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $data = new BookingDraftData(
            product: $fixture['product'],
            date: $fixture['departure']->local_date->copy(),
            guestName: 'Γιώργος Νικολάου',
            guestEmail: 'giorgos@example.gr',
            guestPhone: '+306912345678',
            paxByCode: ['adult' => 2],
            departure: $fixture['departure'],
        );

        $booking = app(CreateManualBooking::class)($data, source: BookingSource::Quay, payOnTheDay: true);

        expect($booking->status)->toBe(BookingStatus::Confirmed);

        expect(fn () => app(CreateManualBooking::class)($data, payOnTheDay: true))
            ->toThrow(ValidationException::class);
    });
})->group('fast');

it('refuses a phone charter dated yesterday', function (): void {
    Carbon::setTestNow('2026-07-03 06:30:00');

    $fixture = BookingApiScenario::bookable(mode: BookingMode::PerVessel);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $fixture['product']->forceFill(['default_start_time' => '09:00', 'flexible_start' => false])->save();

        $data = new BookingDraftData(
            product: $fixture['product']->refresh(),
            date: Carbon::parse('2026-07-02'),
            guestName: 'Γιώργος Νικολάου',
            guestEmail: 'giorgos@example.gr',
            guestPhone: '+306912345678',
            paxByCode: ['adult' => 2],
        );

        expect(fn () => app(CreateManualBooking::class)($data, payOnTheDay: true))
            ->toThrow(ValidationException::class, (string) __('bookings.trip_started'));
    });
})->group('fast');
