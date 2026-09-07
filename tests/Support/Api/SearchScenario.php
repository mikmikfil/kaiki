<?php

declare(strict_types=1);

namespace Tests\Support\Api;

use App\Domain\Catalog\Support\SearchFilters;
use App\Enums\ApiScope;
use App\Enums\ProductCategory;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/**
 * A small catalogue to search through (#105).
 *
 * A class rather than file-local Pest helpers, for the reason the hosted
 * scenarios record twice: an identically named `function` in two Pest files is
 * a **fatal error** that stops the whole suite, in a file that has nothing to do
 * with either.
 *
 * Every price here is built from a real rate plan and band price rather than
 * written onto `products.price_from_cents`. That column is derived — the
 * observers rewrite it on every plan write (#33) — so a fixture that set it
 * would be testing a value the application had already discarded, which is the
 * mistake `TripPage` made in #104 and this class was written knowing.
 */
final class SearchScenario
{
    /** Far enough ahead that no lead-time rule bites, and stable across runs. */
    public static function date(): string
    {
        return Carbon::now()->addDays(30)->toDateString();
    }

    /**
     * An operator, a key with the availability scope, and nothing else yet.
     *
     * @return array{0: Tenant, 1: string}
     */
    public static function operator(): array
    {
        /** @var array{0: Tenant, 1: string} $key */
        $key = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

        return $key;
    }

    /** A per-seat trip that sails on {@see self::date()}, priced per adult. */
    public static function trip(
        Tenant $tenant,
        string $slug,
        int $adultPriceCents = 4500,
        int $capacity = 12,
        int $seatsSold = 0,
        ?Port $port = null,
        ?ProductCategory $category = null,
        int $durationMinutes = 480,
        string $localTime = '09:00',
        bool $withDeparture = true,
    ): Product {
        return Tenancy::forTenant($tenant, static function () use (
            $slug, $adultPriceCents, $capacity, $seatsSold, $port, $category, $durationMinutes, $localTime, $withDeparture
        ): Product {
            $vessel = Vessel::factory()->create(['capacity_max' => 40]);

            $product = Product::factory()->create([
                'slug' => $slug,
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port?->getKey(),
                'category' => $category ?? ProductCategory::SharedFullDay,
                'duration_minutes' => $durationMinutes,
                'max_pax' => $capacity,
                'min_pax' => 0,
                'title' => ['el' => "Εκδρομή {$slug}", 'en' => "Trip {$slug}"],
            ]);

            $band = AgeBand::factory()->create(['product_id' => $product->getKey()]);

            $plan = RatePlan::factory()->create([
                'product_id' => $product->getKey(),
                'min_lead_time_hours' => 0,
                'max_advance_days' => 365,
            ]);

            RatePlanPrice::factory()->create([
                'rate_plan_id' => $plan->getKey(),
                'age_band_id' => $band->getKey(),
                'price_cents' => $adultPriceCents,
            ]);

            if ($withDeparture) {
                Departure::factory()
                    ->at(self::date(), $localTime)
                    ->create([
                        'product_id' => $product->getKey(),
                        'vessel_id' => $vessel->getKey(),
                        'capacity' => $capacity,
                        'min_pax' => 0,
                        'seats_sold' => $seatsSold,
                    ]);
            }

            return $product->refresh();
        });
    }

    /** A meeting point to filter by. */
    public static function port(Tenant $tenant, string $name): Port
    {
        return Tenancy::forTenant($tenant, static fn (): Port => Port::factory()->create([
            'name' => ['el' => $name, 'en' => $name],
        ]));
    }

    /**
     * Switch the operator's filters to exactly this set.
     *
     * @param  array<string, bool>  $filters
     */
    public static function filters(Tenant $tenant, array $filters): void
    {
        $tenant->forceFill([
            'settings' => [
                ...(array) $tenant->settings,
                SearchFilters::SETTINGS_KEY => ['filters' => $filters],
            ],
        ])->save();
    }

    /** @param array<string, mixed> $query */
    public static function url(array $query = []): string
    {
        return CatalogRequest::url('/search', [...['date' => self::date()], ...$query]);
    }
}
