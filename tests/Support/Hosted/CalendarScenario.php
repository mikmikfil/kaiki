<?php

declare(strict_types=1);

namespace Tests\Support\Hosted;

use App\Enums\BookingMode;
use App\Enums\DepartureCancelReason;
use App\Enums\DepartureStatus;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/**
 * Trips and sailings for the departures calendar's tests (2026-09-25).
 *
 * A class rather than file-local functions, for the reason `OperatorPage`
 * gives: two Pest files declaring the same helper is a fatal error.
 */
final class CalendarScenario
{
    /** A day far enough ahead that no lead time or «today» rule touches it. */
    public static function day(int $offset = 0): string
    {
        return Carbon::now('Europe/Athens')->addDays(10 + $offset)->toDateString();
    }

    /**
     * One trip on its own boat, priced per adult, on sale.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function trip(Tenant $tenant, string $slug, int $priceCents = 6500, array $attributes = []): Product
    {
        return Tenancy::forTenant($tenant, static function () use ($slug, $priceCents, $attributes): Product {
            $vessel = Vessel::factory()->create(['name' => "Boat {$slug}", 'capacity_max' => 40]);

            $product = Product::factory()->create([
                'slug' => $slug,
                'vessel_id' => $vessel->getKey(),
                'title' => ['el' => "Εκδρομή {$slug}", 'en' => "Trip {$slug}"],
                'min_pax' => 0,
                'max_pax' => 20,
                ...$attributes,
            ]);

            $band = AgeBand::factory()->create(['product_id' => $product->getKey()]);

            $plan = RatePlan::factory()->create([
                'product_id' => $product->getKey(),
                'min_lead_time_hours' => 0,
                'max_advance_days' => 365,
                'vessel_price_cents' => 75000,
            ]);

            RatePlanPrice::factory()->create([
                'rate_plan_id' => $plan->getKey(),
                'age_band_id' => $band->getKey(),
                'price_cents' => $priceCents,
            ]);

            return $product->refresh();
        });
    }

    /** A sailing of `$product` with `$free` seats left out of `$capacity`. */
    public static function sailing(
        Tenant $tenant,
        Product $product,
        string $date,
        string $time,
        int $capacity = 12,
        ?int $free = null,
        ?DepartureCancelReason $cancelled = null,
    ): Departure {
        return Tenancy::forTenant($tenant, static fn (): Departure => Departure::factory()
            ->at($date, $time, 180)
            ->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $product->vessel_id,
                'capacity' => $capacity,
                'min_pax' => 0,
                'seats_sold' => $capacity - ($free ?? $capacity),
                'status' => $cancelled === null ? DepartureStatus::Scheduled : DepartureStatus::Cancelled,
                'cancel_reason' => $cancelled,
                'cancelled_at' => $cancelled === null ? null : now(),
            ]));
    }

    public static function charter(Tenant $tenant, string $slug): Product
    {
        return self::trip($tenant, $slug, 6500, ['mode' => BookingMode::PerVessel, 'default_start_time' => '10:00', 'duration_minutes' => 480]);
    }

    public static function draft(Tenant $tenant, string $slug): Product
    {
        return self::trip($tenant, $slug, 6500, ['status' => ProductStatus::Draft]);
    }

    public static function url(string $operator, string $query = ''): string
    {
        return HostedRequest::url("/{$operator}/calendar?lang=el&from=" . self::day() . ($query === '' ? '' : '&' . $query));
    }
}
