<?php

declare(strict_types=1);

use App\Enums\BookingMode;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| A whole-boat charter, before «now» and at the right hour (2026-09-25)
|--------------------------------------------------------------------------
|
| Two faults Mike met on «Ιδιωτική ναύλωση ημέρας» on the same afternoon.
|
| 1. `GET /availability` answered `available` for every day of September,
|    yesterday included, because `CheckVesselAvailability` never asked AVL-19
|    or AVL-20. The quote then refused the day for another reason («no price
|    table») and the guest saw an error. The calendar, the quote and the
|    booking now ask one rule, `BookingCutoff`, so they cannot disagree.
|
| 2. The checkout said «30/09/2026 · 06:00» for a 09:00 charter. Not a
|    rendering fault: the widget sends no time for a fixed-start charter, and
|    the draft fell back to the operating window's earliest start, so the
|    booking itself — its hold on the boat included — was at 06:00.
|
| Athens is UTC+3 on these dates, so 09:00 local is 06:00Z.
|
*/

beforeEach(function (): void {
    // 13:00 in Athens on the 25th.
    Carbon::setTestNow('2026-09-25 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A 09:00 fixed-start charter with a price on every day of the year.
 *
 * @return array{tenant: Tenant, product: Product, departure: Departure, band: AgeBand, key: string}
 */
function charterCutoffFixture(int $leadHours = 0): array
{
    $fixture = BookingApiScenario::bookable(mode: BookingMode::PerVessel);

    $fixture['product'] = Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $leadHours): Product {
        $fixture['product']->forceFill([
            'default_start_time' => '09:00',
            'duration_minutes' => 480,
            'flexible_start' => false,
        ])->save();

        RatePlan::query()->where('product_id', $fixture['product']->getKey())->update([
            'vessel_price_cents' => 50000,
            'min_lead_time_hours' => $leadHours,
        ]);

        // The scenario's sailing is a per-seat thing; a charter has none.
        $fixture['departure']->delete();

        return $fixture['product']->fresh(['ageBands']);
    });

    return $fixture;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return list<array<string, mixed>>
 */
function charterDays(array $fixture, string $from, string $to): array
{
    return getJson(CatalogRequest::url('/availability', [
        'product' => $fixture['product']->uuid,
        'from' => $from,
        'to' => $to,
    ]), ['Authorization' => "Bearer {$fixture['key']}"])->assertOk()->json('data');
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $window
 * @return array<string, mixed>
 */
function charterBody(array $fixture, array $window): array
{
    $body = BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
        'departure_uuid' => null,
        'window' => $window,
    ]);

    return $body;
}

it('offers no charter window before now', function (): void {
    $fixture = charterCutoffFixture();

    $days = collect(charterDays($fixture, '2026-09-24', '2026-09-26'))->keyBy('local_date');

    // Yesterday, and today's 09:00 which sailed four hours ago.
    foreach (['2026-09-24', '2026-09-25'] as $date) {
        expect($days[$date]['status'])->toBe('past', $date)
            ->and($days[$date]['from_price_cents'])->toBeNull()
            ->and($days[$date]['windows'][0]['is_available'])->toBeFalse()
            ->and($days[$date]['windows'][0]['unavailable_reason'])->toBe('lead_time');
    }

    expect($days['2026-09-26']['status'])->toBe('available')
        ->and($days['2026-09-26']['windows'][0]['window']['local_time'])->toBe('09:00')
        ->and($days['2026-09-26']['windows'][0]['is_available'])->toBeTrue();
})->group('fast');

it('holds a charter window to the plan lead time', function (): void {
    // 48 hours from 10:00Z on the 25th is 10:00Z on the 27th; the 27th's
    // 09:00 is 06:00Z, four hours short.
    $fixture = charterCutoffFixture(leadHours: 48);

    $days = collect(charterDays($fixture, '2026-09-26', '2026-09-28'))->keyBy('local_date');

    expect($days['2026-09-26']['status'])->toBe('past')
        ->and($days['2026-09-27']['status'])->toBe('past')
        ->and($days['2026-09-27']['windows'][0]['unavailable_reason'])->toBe('lead_time')
        ->and($days['2026-09-28']['status'])->toBe('available');
})->group('fast');

it('refuses to quote a charter date the calendar calls past, in the calendar own words', function (): void {
    $fixture = charterCutoffFixture();
    $headers = ['Authorization' => "Bearer {$fixture['key']}"];

    $body = static fn (string $date): array => [
        'product_uuid' => $fixture['product']->uuid,
        'window' => ['local_date' => $date, 'local_time' => null],
        'pax' => [['age_band_uuid' => $fixture['band']->uuid, 'qty' => 2]],
    ];

    // Priced before this change, since the fixture's season covers the date:
    // the refusal is the cutoff's, not an accident of the price table.
    postJson(CatalogRequest::url('/price-quote'), $body('2026-09-20'), $headers)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'lead_time_too_short')
        ->assertJsonPath('error.message_el', __('enums.availability_rejection.lead_time_too_short.label', [], 'el'));

    postJson(CatalogRequest::url('/price-quote'), $body('2026-09-30'), $headers)
        ->assertOk()
        ->assertJsonPath('data.total_cents', 50000);
})->group('fast');

it('refuses a charter booking for a past date and creates nothing', function (): void {
    $fixture = charterCutoffFixture();

    postJson(
        CatalogRequest::url('/bookings'),
        charterBody($fixture, ['local_date' => '2026-09-20', 'local_time' => '09:00']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertUnprocessable()->assertJsonPath('error.code', 'lead_time_too_short');

    expect(Tenancy::forTenant($fixture['tenant'], fn (): int => Booking::query()->count()))->toBe(0);
})->group('fast');

it('books a fixed-start charter at its own 09:00 and shows 09:00 at the checkout', function (): void {
    $fixture = charterCutoffFixture();

    // What the widget sends for a charter: a date and no time.
    $created = postJson(
        CatalogRequest::url('/bookings'),
        charterBody($fixture, ['local_date' => '2026-09-30', 'local_time' => null]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    $booking = Tenancy::forTenant($fixture['tenant'], fn (): Booking => Booking::query()->where('uuid', $created->json('data.uuid'))->firstOrFail());

    expect($booking->local_time)->toStartWith('09:00')
        ->and($booking->starts_at_utc->toIso8601ZuluString())->toBe('2026-09-30T06:00:00Z')
        ->and($booking->ends_at_utc->toIso8601ZuluString())->toBe('2026-09-30T14:00:00Z');

    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('30/09/2026 · 09:00')
        ->assertDontSee('30/09/2026 · 06:00');

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee('06:00');
})->group('fast');

it('leaves a per-seat booking at its departure time', function (): void {
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-09-30 06:00:00'));

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    $booking = Tenancy::forTenant($fixture['tenant'], fn (): Booking => Booking::query()->where('uuid', $created->json('data.uuid'))->firstOrFail());

    expect($booking->local_time)->toStartWith('09:00')
        ->and($booking->starts_at_utc->toIso8601ZuluString())->toBe('2026-09-30T06:00:00Z');

    get('/c/' . $booking->manage_token)->assertOk()->assertSee('30/09/2026 · 09:00');
})->group('fast');

it('refuses a per-seat departure that has already sailed', function (): void {
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-09-24 06:00:00'));

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertUnprocessable()->assertJsonPath('error.code', 'lead_time_too_short');
})->group('fast');
