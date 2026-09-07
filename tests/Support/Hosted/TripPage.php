<?php

declare(strict_types=1);

namespace Tests\Support\Hosted;

use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;

/**
 * Setup for the product-page tests of #104.
 *
 * A class rather than helper `function`s at the top of a Pest file, for the
 * reason {@see OperatorPage} records twice over: a file-local helper that
 * collides with an identically named one elsewhere is a **fatal error**, not a
 * failed assertion, and it stops the whole suite in a file that has nothing to
 * do with either.
 *
 * The trip built here is deliberately **complete** — a boat, a meeting point
 * with coordinates, an itinerary, age bands, a cancellation policy with tiers
 * and real departures — because every acceptance criterion of #104 is about a
 * section of the page rendering, and a fixture with holes in it makes an absent
 * section indistinguishable from a broken one.
 */
final class TripPage
{
    /**
     * A fully furnished trip inside one operator's tenancy.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(Tenant $tenant, array $attributes = []): Product
    {
        return Tenancy::forTenant($tenant, static function () use ($attributes): Product {
            $port = Port::factory()->create([
                'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
                'address' => 'Ακτή Θεμιστοκλέους, Πειραιάς',
                'lat' => '37.9339000',
                'lng' => '23.6469000',
                'instructions' => ['el' => 'Στο μπλε περίπτερο.', 'en' => 'At the blue kiosk.'],
            ]);

            $vessel = Vessel::factory()->create([
                'name' => 'Kalypso',
                'home_port_id' => $port->getKey(),
                'capacity_max' => 24,
            ]);

            $policy = CancellationPolicy::factory()->create([
                'name' => ['el' => 'Ευέλικτη', 'en' => 'Flexible'],
                'summary' => ['el' => 'Ακύρωση έως δύο ημέρες πριν.', 'en' => 'Cancel up to two days before.'],
                'free_cancellation_hours' => 48,
                'weather_refund_percent' => 100,
            ]);

            CancellationPolicyTier::factory()->create([
                'cancellation_policy_id' => $policy->getKey(),
                'days_before' => 7,
                'refund_percent' => 100,
            ]);

            $product = Product::factory()->withItinerary()->create([
                'slug' => 'sunset-cruise',
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port->getKey(),
                'cancellation_policy_id' => $policy->getKey(),
                'title' => ['el' => 'Κρουαζιέρα ηλιοβασιλέματος', 'en' => 'Sunset cruise'],
                'summary' => ['el' => 'Τρεις ώρες στον Σαρωνικό.', 'en' => 'Three hours in the Saronic.'],
                'description' => [
                    'el' => "Φεύγουμε με το φως να χαμηλώνει.\n\nΤο σκάφος το έφτιαξε ο παππούς μου.",
                    'en' => "We leave as the light drops.\n\nMy grandfather built the boat.",
                ],
                'includes' => ['el' => ['Ποτήρι κρασί'], 'en' => ['A glass of wine']],
                'excludes' => ['el' => ['Μεταφορά από το ξενοδοχείο'], 'en' => ['Hotel transfer']],
                'what_to_bring' => ['el' => ['Αντηλιακό'], 'en' => ['Sunscreen']],
                ...$attributes,
            ]);

            $adult = AgeBand::factory()->create([
                'product_id' => $product->getKey(),
                'code' => 'adult',
                'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'],
                'min_age' => 12,
                'max_age' => null,
                'is_base' => true,
            ]);

            // The price is **derived** (#33): the observers rewrite
            // `price_from_cents` on every rate-plan and age-band write, so the
            // 4500 above is null by the time the page renders unless there is a
            // real plan behind it. Building the chain is the fixture being
            // honest about where a price comes from — and the first version of
            // this class did set the column, which is how that was learnt.
            $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);

            RatePlanPrice::factory()->create([
                'rate_plan_id' => $plan->getKey(),
                'age_band_id' => $adult->getKey(),
                'price_cents' => 4500,
            ]);

            return $product->refresh();
        });
    }

    /**
     * One sellable departure in the future.
     *
     * The page shows the departures a guest can still take, so a fixture on a
     * past date would render nothing and prove nothing. Dates are explicit
     * rather than relative for the reason `DepartureFactory` gives: every
     * assertion in this suite is about which side of a boundary an instant
     * falls on.
     */
    public static function departure(Tenant $tenant, Product $product, string $date = '2026-12-20', string $time = '18:30'): Departure
    {
        return Tenancy::forTenant($tenant, static fn (): Departure => Departure::factory()
            ->at($date, $time, 180)
            ->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $product->vessel_id,
                'capacity' => 12,
            ]));
    }

    /** The page's URL on the hosted host. */
    public static function url(Tenant $tenant, Product $product, ?string $locale = null): string
    {
        $path = '/' . $tenant->slug . '/' . $product->slug;

        return HostedRequest::url($locale === null ? $path : $path . '?lang=' . $locale);
    }
}
