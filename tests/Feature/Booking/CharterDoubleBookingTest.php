<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Booking\Actions\StartCheckout;
use App\Domain\Operations\Support\AttentionItems;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Exceptions\HoldRefused;
use App\Filament\App\Resources\BookingResource\Pages\CreateBooking;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| One boat, one charter (2026-09-25)
|--------------------------------------------------------------------------
|
| Two guests could book and pay for the same whole-boat charter at the same
| hour. Worse than the plan feared: a charter booked online occupied its boat in
| **no** state — the draft was never given a hold, and `BookingHoldSource` only
| counted live draft holds, so even a confirmed charter left the day on offer.
|
| Now a charter's draft holds the boat for the hold's minutes, and from the
| redirect to the gateway onwards the booking holds it for good. The draft is
| refused under the vessel lock when somebody else has the boat; so is the
| redirect, and so is the confirmation — the last one cancelling and refunding a
| payment that arrived for a boat that had gone.
|
| The real race — two transactions at once — cannot run on SQLite.
| `LockDisciplineTest` holds the lock order; these hold the rule.
|
*/

beforeEach(function (): void {
    // 13:00 in Athens.
    Carbon::setTestNow('2026-09-25 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A priced 09:00 charter, eight hours, on its own boat.
 *
 * @return array{tenant: Tenant, product: Product, departure: Departure, band: AgeBand, key: string}
 */
function charterRaceFixture(bool $flexible = false): array
{
    $fixture = BookingApiScenario::bookable(mode: BookingMode::PerVessel);

    $fixture['product'] = Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $flexible): Product {
        $fixture['product']->forceFill([
            'default_start_time' => $flexible ? null : '09:00',
            'flexible_start' => $flexible,
            'earliest_start_time' => $flexible ? '08:00' : null,
            'latest_start_time' => $flexible ? '16:00' : null,
            'duration_minutes' => $flexible ? 240 : 480,
        ])->save();

        RatePlan::query()->where('product_id', $fixture['product']->getKey())->update([
            'vessel_price_cents' => 50000,
            'min_lead_time_hours' => 0,
        ]);

        $fixture['departure']->delete();

        return $fixture['product']->fresh(['ageBands']);
    });

    return $fixture;
}

/**
 * `POST /bookings` for a charter.
 *
 * @param  array<string, mixed>  $fixture
 * @return TestResponse<JsonResponse>
 */
function bookCharter(array $fixture, string $date, ?string $time = null, ?Product $product = null): TestResponse
{
    $product ??= $fixture['product'];

    return postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($product, $fixture['departure'], $fixture['band'], [
            'product_uuid' => $product->uuid,
            'departure_uuid' => null,
            'window' => ['local_date' => $date, 'local_time' => $time],
        ]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  TestResponse<JsonResponse>  $created
 */
function charterBooking(array $fixture, TestResponse $created): Booking
{
    return Tenancy::forTenant($fixture['tenant'], fn (): Booking => Booking::query()->where('uuid', $created->json('data.uuid'))->firstOrFail());
}

/** @param  array<string, mixed>  $fixture */
function charterCount(array $fixture): int
{
    return Tenancy::forTenant($fixture['tenant'], fn (): int => Booking::query()->count());
}

it('refuses a second draft for the same window, in the guest words, and writes nothing', function (): void {
    $fixture = charterRaceFixture();

    $first = charterBooking($fixture, bookCharter($fixture, '2026-09-30')->assertCreated());

    // The draft is the hold: fifteen minutes on the boat itself.
    expect($first->hold_expires_at?->toIso8601ZuluString())->toBe('2026-09-25T10:15:00Z');

    bookCharter($fixture, '2026-09-30')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'vessel_unavailable')
        ->assertJsonPath('error.message_el', 'Το σκάφος μόλις κλείστηκε για αυτή την ώρα. Διαλέξτε άλλη μέρα.')
        ->assertJsonPath('error.message', 'The boat has just been booked for this time. Please choose another day.');

    expect(charterCount($fixture))->toBe(1);

    // And the calendar says so, rather than offering the day to a third guest.
    $day = getJson(CatalogRequest::url('/availability', [
        'product' => $fixture['product']->uuid,
        'from' => '2026-09-30',
        'to' => '2026-09-30',
    ]), ['Authorization' => "Bearer {$fixture['key']}"])->assertOk()->json('data.0');

    expect($day['status'])->toBe('unavailable')
        ->and($day['windows'][0]['unavailable_reason'])->toBe('vessel_blocked');
})->group('fast');

it('refuses an overlapping window and lets a later one through', function (): void {
    $fixture = charterRaceFixture(flexible: true);

    // 09:00–13:00.
    bookCharter($fixture, '2026-09-30', '09:00')->assertCreated();

    // 11:00–15:00 overlaps it.
    bookCharter($fixture, '2026-09-30', '11:00')->assertStatus(409)->assertJsonPath('error.code', 'vessel_unavailable');

    // 15:00 clears 13:00 and the boat's turnaround.
    bookCharter($fixture, '2026-09-30', '15:00')->assertCreated();

    expect(charterCount($fixture))->toBe(2);
})->group('fast');

it('lets another day and another boat through', function (): void {
    $fixture = charterRaceFixture();

    bookCharter($fixture, '2026-09-30')->assertCreated();
    bookCharter($fixture, '2026-10-01')->assertCreated();

    // The same kind of charter on a second boat, the same morning.
    $other = Tenancy::forTenant($fixture['tenant'], function () use ($fixture): Product {
        $vessel = Vessel::factory()->create(['capacity_max' => 20]);

        $product = Product::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'default_start_time' => '09:00',
            'duration_minutes' => 480,
        ]);

        AgeBand::factory()->create([
            'product_id' => $product->getKey(),
            'code' => 'adult',
            'counts_toward_capacity' => true,
            'is_base' => true,
        ]);

        $plan = RatePlan::query()->where('product_id', $fixture['product']->getKey())->firstOrFail();

        RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'season_id' => $plan->season_id,
            'vessel_price_cents' => 40000,
            'min_lead_time_hours' => 0,
            'max_advance_days' => null,
        ]);

        return $product->fresh(['ageBands']);
    });

    $fixture['band'] = $other->ageBands->first();

    bookCharter($fixture, '2026-09-30', null, $other)->assertCreated();

    expect(charterCount($fixture))->toBe(3);
})->group('fast');

it('confirms a charter against its own hold, and the confirmed charter keeps the boat', function (): void {
    $fixture = charterRaceFixture();

    $first = charterBooking($fixture, bookCharter($fixture, '2026-09-30')->assertCreated());

    $confirmed = Tenancy::forTenant($fixture['tenant'], fn (): Booking => app(ConfirmBooking::class)($first));

    expect($confirmed->status)->toBe(BookingStatus::Confirmed)
        ->and($confirmed->hold_expires_at)->toBeNull();

    // A day later the hold would long have lapsed; the booking has not.
    Carbon::setTestNow('2026-09-26 10:00:00');

    bookCharter($fixture, '2026-09-30')->assertStatus(409)->assertJsonPath('error.code', 'vessel_unavailable');
})->group('fast');

it('lets a second guest in once the first hold lapses, and never confirms the first', function (): void {
    Queue::fake();

    $fixture = charterRaceFixture();

    $first = charterBooking($fixture, bookCharter($fixture, '2026-09-30')->assertCreated());

    // Twenty minutes on the checkout page: the hold has gone.
    Carbon::setTestNow('2026-09-25 10:20:00');

    $second = charterBooking($fixture, bookCharter($fixture, '2026-09-30')->assertCreated());

    // The first guest presses pay: refused before the gateway, no charge.
    Tenancy::forTenant($fixture['tenant'], function () use ($first): void {
        $refused = null;

        try {
            app(StartCheckout::class)($first->refresh());
        } catch (HoldRefused $exception) {
            $refused = $exception;
        }

        expect($refused?->reason)->toBe('vessel_unavailable');

        expect($first->refresh()->status)->toBe(BookingStatus::Draft)
            ->and(Payment::query()->where('booking_id', $first->getKey())->count())->toBe(0);
    });

    // The worse order: the first guest reached the gateway and the money came
    // back after the second had the boat. Paid, and still not confirmed.
    [$payment] = Tenancy::forTenant($fixture['tenant'], function () use ($first): array {
        $first->forceFill(['status' => BookingStatus::PendingPayment, 'hold_expires_at' => null])->save();

        $payment = Payment::factory()->pending()->create([
            'booking_id' => $first->getKey(),
            'amount_cents' => $first->total_cents,
            'gateway' => PaymentGatewayName::Viva,
            'gateway_ref' => 'viva-order-charter-race',
        ]);

        return [$payment];
    });

    Tenancy::forTenant($fixture['tenant'], function () use ($payment, $first, $second): void {
        app(ConfirmFromWebhook::class)($payment->refresh(), true);

        $lost = $first->refresh();

        expect($lost->status)->toBe(BookingStatus::Cancelled)
            ->and($lost->cancel_reason)->toBe(CancelReason::VesselBookedPrivately)
            ->and($lost->cancelled_by)->toBe(CancelledBy::System)
            // Refunded in full: a pending refund against the card, for all of it.
            ->and((int) Payment::query()
                ->where('booking_id', $lost->getKey())
                ->where('kind', PaymentKind::Refund->value)
                ->sum('amount_cents'))->toBe($lost->total_cents)
            ->and($second->refresh()->status)->toBe(BookingStatus::Draft);

        $keys = array_map(
            static fn ($item): string => $item->key,
            (new AttentionItems('Europe/Athens'))->everything(),
        );

        expect($keys)->toContain('charter_lost:' . $lost->getKey());
    });
})->group('fast');

it('refuses an operator booking a charter on a boat that is taken, with the sentence', function (): void {
    $fixture = charterRaceFixture();

    // Somebody has tomorrow's charter already — the panel's charter booking is
    // for tomorrow, at the trip's own 09:00 (today's 09:00 has already
    // started, and a started trip is refused before the boat is asked).
    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        Booking::factory()->create([
            'product_id' => $fixture['product']->getKey(),
            'vessel_id' => $fixture['product']->vessel_id,
            'departure_id' => null,
            'mode' => BookingMode::PerVessel,
            'status' => BookingStatus::Confirmed,
            'local_date' => '2026-09-26',
            'local_time' => '09:00',
            'starts_at_utc' => Carbon::parse('2026-09-26 06:00:00'),
            'ends_at_utc' => Carbon::parse('2026-09-26 14:00:00'),
        ]);
    });

    $owner = OperatorUser::withRole(Role::Owner, $fixture['tenant']);

    actingAs($owner);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        Livewire::test(CreateBooking::class)
            ->fillForm([
                'product_id' => $fixture['product']->getKey(),
                'pax' => [['code' => 'adult', 'qty' => 2]],
                'guest_name' => 'Γιώργος Παπαδάκης',
                'guest_email' => 'giorgos@example.gr',
                'payment' => 'on_the_day',
                'date' => '2026-09-26',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            // In the panel's language, from the same lang key the API sends.
            ->assertNotified((string) __('booking.hold.vessel_unavailable'));

        expect(Booking::query()->count())->toBe(1);
    });
})->group('fast');
