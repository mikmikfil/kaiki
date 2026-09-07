<?php

declare(strict_types=1);

namespace Tests\Support\Api;

use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Season;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A bookable product, a departure and the keys to reach them.
 *
 * A class rather than Pest helpers, for the reason the five other scenario
 * classes give: four test files need this and a `function` in a Pest file is
 * scoped to that file.
 *
 * ## It builds a *priceable* product, not just a product
 *
 * `POST /bookings` runs the whole pricing engine, so a fixture with no rate
 * plan, no season and no band price produces a booking whose total is zero —
 * and every assertion about money in this group would pass against nothing.
 * The season deliberately spans the whole year, so a test can pick any date
 * without discovering that the fixture only works in July.
 *
 * ## The key is a **test** key by default, and that is PAY-11 working
 *
 * §3.9: a booking made with a `*_test_` key is `is_test`, and `GatewayResolver`
 * answers a test booking with the `FakeGateway` when the operator has
 * configured nothing. So a checkout in this group mints a real session object
 * against a fake provider rather than reaching for Viva's API and failing on
 * the network — which is the sandbox path the requirement exists to give an
 * operator on their first day.
 *
 * A test that wants the **live** path passes `ApiKeyEnvironment::Live` and gets
 * `no_gateway_configured`, which is the honest answer for an operator who has
 * connected nothing.
 */
final class BookingApiScenario
{
    /**
     * @param  list<string>  $allowedOrigins  empty means any, as it does for CORS
     * @return array{tenant: Tenant, product: Product, departure: Departure, band: AgeBand, key: string}
     */
    public static function bookable(
        BookingMode $mode = BookingMode::PerSeat,
        int $capacity = 12,
        int $unitPriceCents = 6500,
        ?Carbon $startsAt = null,
        ApiKeyType $keyType = ApiKeyType::Publishable,
        ApiKeyEnvironment $environment = ApiKeyEnvironment::Test,
        array $allowedOrigins = [],
    ): array {
        [$tenant, $key] = CatalogRequest::key(
            type: $keyType,
            scopes: [ApiScope::ProductsRead, ApiScope::AvailabilityRead, ApiScope::BookingsWrite],
            environment: $environment,
            allowedOrigins: $allowedOrigins,
        );

        $startsAt ??= Carbon::now()->addDays(30)->setTime(9, 0);

        /** @var array{product: Product, departure: Departure, band: AgeBand} $built */
        $built = Tenancy::forTenant($tenant, static function () use ($mode, $capacity, $unitPriceCents, $startsAt): array {
            /** @var Vessel $vessel */
            $vessel = Vessel::factory()->create(['capacity_max' => 20]);

            /** @var Port $port */
            $port = Port::factory()->create();

            /** @var CancellationPolicy $policy */
            $policy = CancellationPolicy::factory()->withTiers()->create(['free_cancellation_hours' => null]);

            /** @var Product $product */
            $product = Product::factory()->create([
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port->getKey(),
                'cancellation_policy_id' => $policy->getKey(),
                'mode' => $mode,
                'max_pax' => $capacity,
                'min_pax' => 0,
            ]);

            /** @var AgeBand $band */
            $band = AgeBand::factory()->create([
                'product_id' => $product->getKey(),
                'code' => 'adult',
                'counts_toward_capacity' => true,
                'is_base' => true,
                'price_multiplier_bp' => 10000,
            ]);

            /** @var Season $season */
            $season = Season::factory()->create();

            // The whole year, so a test can pick any date without discovering
            // the fixture only works in July.
            $season->dateRanges()->create([
                'tenant_id' => $season->tenant_id,
                'starts_on' => Carbon::now()->startOfYear()->toDateString(),
                'ends_on' => Carbon::now()->addYear()->endOfYear()->toDateString(),
            ]);

            /** @var RatePlan $plan */
            $plan = RatePlan::factory()->create([
                'product_id' => $product->getKey(),
                'season_id' => $season->getKey(),
                'min_lead_time_hours' => 0,
                'max_advance_days' => null,
            ]);

            RatePlanPrice::factory()->create([
                'rate_plan_id' => $plan->getKey(),
                'age_band_id' => $band->getKey(),
                'price_cents' => $unitPriceCents,
            ]);

            /** @var Departure $departure */
            $departure = Departure::factory()->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'capacity' => $capacity,
                'seats_sold' => 0,
                'seats_held' => 0,
                'min_pax' => 0,
                'starts_at_utc' => $startsAt,
                'ends_at_utc' => $startsAt->copy()->addMinutes($product->duration_minutes),
                'local_date' => $startsAt->copy()->setTimezone('Europe/Athens')->toDateString(),
                'local_time' => $startsAt->copy()->setTimezone('Europe/Athens')->format('H:i:s'),
            ]);

            return ['product' => $product->fresh(['ageBands']), 'departure' => $departure, 'band' => $band];
        });

        return [
            'tenant' => $tenant,
            'product' => $built['product'],
            'departure' => $built['departure'],
            'band' => $built['band'],
            'key' => $key,
        ];
    }

    /**
     * A well-formed `POST /bookings` body.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function body(Product $product, Departure $departure, AgeBand $band, array $overrides = []): array
    {
        return array_replace([
            'product_uuid' => $product->uuid,
            'departure_uuid' => $departure->uuid,
            'pax' => [['age_band_uuid' => $band->uuid, 'qty' => 2]],
            'guest' => [
                'name' => 'Μαρία Παπαδοπούλου',
                'email' => 'maria@example.gr',
                'phone' => '+306941234567',
            ],
            'terms_accepted' => true,
        ], $overrides);
    }

    /** A confirmed booking on the same fixture, for the read and cancel paths. */
    public static function confirm(Tenant $tenant, Booking $booking, int $paidCents): Booking
    {
        return Tenancy::forTenant($tenant, static function () use ($booking, $paidCents): Booking {
            $booking->forceFill([
                'status' => BookingStatus::Confirmed,
                'confirmed_at' => now(),
                'paid_cents' => $paidCents,
                'balance_cents' => max(0, $booking->total_cents - $paidCents),
            ])->save();

            return $booking->refresh();
        });
    }

    /** A fresh UUIDv4, which is what `Idempotency-Key` has to be. */
    public static function idempotencyKey(): string
    {
        return (string) Str::uuid();
    }
}
