<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Actions\StartCheckout;
use App\Enums\BlockReason;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Exceptions\HoldRefused;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| A seat is not sold on a boat somebody else has (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| A charter checked for per-seat sailings on its boat; a per-seat sale never
| checked for charters or blocks. The calendar already called the sailing
| busy, but `POST /bookings`, a stale widget page or the panel went straight
| to `HoldSeats`, which counted only seats. Now the hold and the checkout ask
| the same `OccupationCollector` the calendar does.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-25 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $fixture
 * @return TestResponse<JsonResponse>
 */
function bookSeat(array $fixture): TestResponse
{
    return postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );
}

/** A charter on the sailing's boat, over the sailing's hours. */
function charterOver(Tenant $tenant, Departure $departure, BookingStatus $status, ?Carbon $holdUntil = null): Booking
{
    return Tenancy::forTenant($tenant, static fn (): Booking => Booking::factory()->create([
        'product_id' => $departure->product_id,
        'vessel_id' => $departure->vessel_id,
        'departure_id' => null,
        'mode' => BookingMode::PerVessel,
        'status' => $status,
        'hold_expires_at' => $holdUntil,
        'local_date' => $departure->local_date->toDateString(),
        'local_time' => '08:00',
        'starts_at_utc' => $departure->starts_at_utc->copy()->subHour(),
        'ends_at_utc' => $departure->ends_at_utc->copy()->addHour(),
    ]));
}

it('refuses a seat on a sailing whose boat is chartered, whatever the seat count', function (BookingStatus $status, ?string $hold): void {
    $fixture = BookingApiScenario::bookable();

    charterOver($fixture['tenant'], $fixture['departure'], $status, $hold === null ? null : Carbon::parse($hold));

    bookSeat($fixture)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'departure_unavailable');

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        expect($fixture['departure']->fresh()?->seats_held)->toBe(0)
            ->and(Booking::query()->where('mode', BookingMode::PerSeat->value)->whereNotNull('hold_expires_at')->count())->toBe(0);
    });
})->with([
    'confirmed' => [BookingStatus::Confirmed, null],
    'at the gateway' => [BookingStatus::PendingPayment, null],
    'held at the checkout' => [BookingStatus::Draft, '2026-09-25 10:10:00'],
])->group('fast');

it('sells the seat once the charter hold has lapsed', function (): void {
    $fixture = BookingApiScenario::bookable();

    charterOver($fixture['tenant'], $fixture['departure'], BookingStatus::Draft, Carbon::parse('2026-09-25 09:59:00'));

    bookSeat($fixture)->assertCreated();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        expect($fixture['departure']->fresh()?->seats_held)->toBe(2);
    });
})->group('fast');

it('reads the block itself, not the cached flag, when it takes a hold', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $departure = $fixture['departure'];

        // Written without the observer, so `is_blocked` is still false: the
        // flag is a cache, and the hold must not trust it.
        VesselBlock::withoutEvents(static fn () => VesselBlock::factory()->create([
            'tenant_id' => $departure->tenant_id,
            'vessel_id' => $departure->vessel_id,
            'reason' => BlockReason::Maintenance,
            'starts_at_utc' => $departure->starts_at_utc->copy()->subHours(2),
            'ends_at_utc' => $departure->ends_at_utc->copy()->addHours(2),
            'local_date' => $departure->local_date->toDateString(),
            'local_end_date' => $departure->local_date->toDateString(),
        ]));

        expect($departure->fresh()?->is_blocked)->toBeFalse();

        $draft = Booking::factory()->create([
            'product_id' => $departure->product_id,
            'vessel_id' => $departure->vessel_id,
            'departure_id' => $departure->getKey(),
            'mode' => BookingMode::PerSeat,
            'status' => BookingStatus::Draft,
            'pax_total' => 2,
            'pax_capacity_total' => 2,
            'hold_expires_at' => null,
        ]);

        // With BKG-32's override too: it lifts the trip's seat count, never
        // somebody else's claim on the boat.
        expect(fn () => app(HoldSeats::class)($draft, $departure, allowOvercapacity: true))
            ->toThrow(HoldRefused::class, (string) __('booking.hold.departure_unavailable'));

        expect($departure->fresh()?->seats_held)->toBe(0);
    });
})->group('fast');

it('stops a held seat at the checkout when the boat was blocked after the hold', function (): void {
    $fixture = BookingApiScenario::bookable();

    $uuid = (string) bookSeat($fixture)->assertCreated()->json('data.uuid');

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $uuid): void {
        $departure = $fixture['departure'];

        VesselBlock::factory()->create([
            'vessel_id' => $departure->vessel_id,
            'reason' => BlockReason::Maintenance,
            'starts_at_utc' => $departure->starts_at_utc->copy()->subHour(),
            'ends_at_utc' => $departure->ends_at_utc->copy()->addHour(),
            'local_date' => $departure->local_date->toDateString(),
            'local_end_date' => $departure->local_date->toDateString(),
        ]);

        $booking = Booking::query()->where('uuid', $uuid)->sole();

        expect(fn () => app(StartCheckout::class)($booking))
            ->toThrow(HoldRefused::class, (string) __('booking.hold.departure_unavailable'));

        expect($booking->fresh()?->status)->toBe(BookingStatus::Draft)
            ->and($departure->fresh()?->seats_sold)->toBe(0);
    });
})->group('fast');
