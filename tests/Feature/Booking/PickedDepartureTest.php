<?php

declare(strict_types=1);

use App\Enums\DepartureStatus;
use App\Enums\ProductStatus;
use App\Exceptions\HoldRefused;
use App\Filament\App\Resources\BookingResource;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| A per-seat booking lands on the sailing the guest picked (2026-09-25)
|--------------------------------------------------------------------------
|
| The widget sends `departure_uuid` and no window. Until this, only the
| departure's **date** was kept and the earliest sailing of that day took the
| booking, whatever its status: a guest who picked 17:00 was held, charged,
| ticketed and put on the manifest of 10:00, even when 10:00 was cancelled.
|
*/

/**
 * The scenario's 10:00 sailing plus a 17:00 one on the same day.
 *
 * @return array{tenant: Tenant, product: Product, departure: Departure, band: AgeBand, key: string, later: Departure}
 */
function twoSailings(): array
{
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::now()->addDays(30)->setTimezone('Europe/Athens')->setTime(10, 0)->utc());

    $later = Tenancy::forTenant($fixture['tenant'], static function () use ($fixture): Departure {
        $first = $fixture['departure'];
        $startsAt = $first->starts_at_utc->copy()->addHours(7);

        /** @var Departure $departure */
        $departure = Departure::factory()->create([
            'product_id' => $fixture['product']->getKey(),
            'vessel_id' => $first->vessel_id,
            'capacity' => 12,
            'seats_sold' => 0,
            'seats_held' => 0,
            'min_pax' => 0,
            'starts_at_utc' => $startsAt,
            'ends_at_utc' => $startsAt->copy()->addMinutes($fixture['product']->duration_minutes),
            'local_date' => $first->local_date->toDateString(),
            'local_time' => '17:00:00',
        ]);

        return $departure;
    });

    return [...$fixture, 'later' => $later];
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<JsonResponse>
 */
function bookPicked(array $fixture, array $overrides = []): TestResponse
{
    return postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], $overrides),
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );
}

/** @param array<string, mixed> $fixture */
function pickedBooking(array $fixture, string $uuid): Booking
{
    return Tenancy::forTenant($fixture['tenant'], static fn (): Booking => Booking::query()->where('uuid', $uuid)->sole());
}

it('books the later sailing when the guest picked it, not the first of the day', function (): void {
    $fixture = twoSailings();

    $response = bookPicked($fixture, ['departure_uuid' => $fixture['later']->uuid, 'window' => null])
        ->assertCreated();

    $booking = pickedBooking($fixture, (string) $response->json('data.uuid'));

    expect($booking->departure_id)->toBe($fixture['later']->getKey())
        ->and(substr((string) $booking->local_time, 0, 5))->toBe('17:00');

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        expect($fixture['later']->fresh()?->seats_held)->toBe(2)
            ->and($fixture['departure']->fresh()?->seats_held)->toBe(0);
    });
});

it('refuses a picked sailing that is cancelled, and does not move the guest to another one', function (): void {
    $fixture = twoSailings();

    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['later']->forceFill(['status' => DepartureStatus::Cancelled])->save());

    bookPicked($fixture, ['departure_uuid' => $fixture['later']->uuid])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'departure_unavailable');

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        expect(Booking::query()->count())->toBe(0)
            ->and($fixture['departure']->fresh()?->seats_held)->toBe(0);
    });
});

it('refuses a picked sailing that is blocked', function (): void {
    $fixture = twoSailings();

    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['later']->forceFill(['is_blocked' => true])->save());

    bookPicked($fixture, ['departure_uuid' => $fixture['later']->uuid])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'departure_unavailable');
});

it('asks for the time when only a date is sent and the trip sails twice that day', function (): void {
    $fixture = twoSailings();

    bookPicked($fixture, [
        'departure_uuid' => null,
        'window' => ['local_date' => $fixture['departure']->local_date->toDateString()],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'departure_time_required');

    expect(Tenancy::forTenant($fixture['tenant'], static fn (): int => Booking::query()->count()))->toBe(0);
});

it('takes the date alone when the trip sails once that day', function (): void {
    $fixture = BookingApiScenario::bookable();

    $response = bookPicked($fixture, [
        'departure_uuid' => null,
        'window' => ['local_date' => $fixture['departure']->local_date->toDateString()],
    ])->assertCreated();

    expect(pickedBooking($fixture, (string) $response->json('data.uuid'))->departure_id)
        ->toBe($fixture['departure']->getKey());
});

it('does not fall back to a cancelled sailing when only a date is sent', function (): void {
    $fixture = twoSailings();

    // 10:00 cancelled: the date alone now means 17:00, the one on sale.
    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['departure']->forceFill(['status' => DepartureStatus::Cancelled])->save());

    $response = bookPicked($fixture, [
        'departure_uuid' => null,
        'window' => ['local_date' => $fixture['departure']->local_date->toDateString()],
    ])->assertCreated();

    expect(pickedBooking($fixture, (string) $response->json('data.uuid'))->departure_id)
        ->toBe($fixture['later']->getKey());
});

it('refuses a trip that is not on sale', function (ProductStatus $status): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['product']->forceFill(['status' => $status])->save());

    bookPicked($fixture)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    expect(Tenancy::forTenant($fixture['tenant'], static fn (): int => Booking::query()->count()))->toBe(0);
})->with([
    'draft' => ProductStatus::Draft,
    'inactive' => ProductStatus::Inactive,
    'archived' => ProductStatus::Archived,
]);

it('refuses a departure of another trip of the same operator', function (): void {
    $fixture = BookingApiScenario::bookable();

    // Its own trip, which the factory makes; the uuid is the whole question.
    $other = Tenancy::forTenant($fixture['tenant'], static fn (): Departure => Departure::factory()->create());

    bookPicked($fixture, ['departure_uuid' => $other->uuid])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'departure_unavailable');

    expect(Tenancy::forTenant($fixture['tenant'], static fn (): int => Booking::query()->count()))->toBe(0);
});

it('refuses a departure of another operator', function (): void {
    $fixture = BookingApiScenario::bookable();
    $stranger = BookingApiScenario::bookable();

    bookPicked($fixture, ['departure_uuid' => $stranger['departure']->uuid])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'departure_unavailable');

    expect(Tenancy::forTenant($fixture['tenant'], static fn (): int => Booking::query()->count()))->toBe(0);
    expect(Tenancy::forTenant($stranger['tenant'], static fn (): ?int => $stranger['departure']->fresh()?->seats_held))->toBe(0);
});

it('says the refusal in both languages', function (): void {
    $fixture = twoSailings();

    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['later']->forceFill(['status' => DepartureStatus::Cancelled])->save());

    $response = bookPicked($fixture, ['departure_uuid' => $fixture['later']->uuid])->assertStatus(409);

    expect($response->json('error.message'))->toBe(__('booking.hold.departure_unavailable', [], 'en'))
        ->and($response->json('error.message_el'))->toBe(__('booking.hold.departure_unavailable', [], 'el'));
});

it('refuses a manual booking on a cancelled sailing in the panel', function (): void {
    $fixture = twoSailings();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $fixture['later']->forceFill(['status' => DepartureStatus::Cancelled])->save();

        expect(fn () => BookingResource::createFromForm([
            'product_id' => $fixture['product']->getKey(),
            'departure_id' => $fixture['later']->getKey(),
            'guest_name' => 'Νίκος',
            'guest_email' => 'nikos@example.gr',
            'pax' => [['code' => 'adult', 'qty' => 2]],
        ]))->toThrow(HoldRefused::class);

        expect(Booking::query()->count())->toBe(0);
    });
});

it('books the picked later sailing from the panel', function (): void {
    $fixture = twoSailings();

    $booking = Tenancy::forTenant($fixture['tenant'], fn (): Booking => BookingResource::createFromForm([
        'product_id' => $fixture['product']->getKey(),
        'departure_id' => $fixture['later']->getKey(),
        'guest_name' => 'Νίκος',
        'guest_email' => 'nikos@example.gr',
        'pax' => [['code' => 'adult', 'qty' => 2]],
    ]));

    expect($booking->departure_id)->toBe($fixture['later']->getKey());
});
