<?php

declare(strict_types=1);

namespace Tests\Support\Stress;

use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;

/**
 * The operator the stress suite sweeps: every half-finished shape at once.
 *
 * Shared by `PanelSweepTest` (every GET, every role)
 * and `ButtonSweepTest` (every button, every role), so both are asked about the
 * same account. {@see fill()} is separate from {@see create()} because the
 * button sweep also needs the reverse direction — an account that *gains* this
 * data while a screen is open.
 */
final class DirtyOperator
{
    public static function create(): Tenant
    {
        $tenant = Tenant::factory()->create(['name' => 'Dirty Seas']);

        self::fill($tenant);

        return $tenant;
    }

    public static function fill(Tenant $tenant): void
    {
        Tenancy::forTenant($tenant, static function (): void {
            $port = Port::factory()->withoutCoordinates()->create();
            $boat = Vessel::factory()->atPort($port)->create();
            $portless = Vessel::factory()->create(['home_port_id' => null]);
            $laidUp = Vessel::factory()->inMaintenance()->create();

            CancellationPolicy::factory()->create(); // no tiers at all

            // A trip with no price and no age bands.
            Product::factory()->create(['vessel_id' => $portless->getKey()]);

            // A price with no ages: the rate plan exists, the bands do not.
            $priced = Product::factory()->create(['vessel_id' => $boat->getKey()]);
            RatePlan::factory()->create(['product_id' => $priced->getKey()]);

            // A draft, a quote trip, and a charter.
            Product::factory()->draft()->create(['vessel_id' => $boat->getKey()]);
            Product::factory()->quote()->create(['vessel_id' => $boat->getKey()]);
            Product::factory()->perVessel()->create(['vessel_id' => $boat->getKey()]);

            // Archived, with departures still in the future and a live rate plan.
            $archived = Product::factory()->create([
                'vessel_id' => $boat->getKey(),
                'status' => ProductStatus::Archived,
            ]);
            RatePlan::factory()->create(['product_id' => $archived->getKey()]);
            Departure::factory()->at('2026-07-20', '09:00')->withSeats(3)->create([
                'product_id' => $archived->getKey(),
                'vessel_id' => $boat->getKey(),
            ]);

            // In the bin, with a live rate plan, age bands and a booking on it.
            $binned = Product::factory()->create(['vessel_id' => $boat->getKey()]);
            RatePlan::factory()->create(['product_id' => $binned->getKey()]);
            AgeBand::factory()->create(['product_id' => $binned->getKey()]);
            $binnedDeparture = Departure::factory()->at('2026-07-08', '09:00')->withSeats(2)->create([
                'product_id' => $binned->getKey(),
                'vessel_id' => $boat->getKey(),
            ]);
            Booking::factory()->forDeparture($binnedDeparture)->create(['product_id' => $binned->getKey()]);
            $binned->delete();

            // A boat in maintenance with a booking on it today.
            $onLaidUp = Departure::factory()->at('2026-07-08', '11:00')->withSeats(4)->create([
                'product_id' => $priced->getKey(),
                'vessel_id' => $laidUp->getKey(),
            ]);
            Booking::factory()->forDeparture($onLaidUp)->create(['product_id' => $priced->getKey()]);

            // A hold that lapsed and was never swept, a booking at the gateway,
            // and one on a departure the operator cancelled.
            $today = Departure::factory()->at('2026-07-08', '17:00')->withSeats(0, 2)->create([
                'product_id' => $priced->getKey(),
                'vessel_id' => $boat->getKey(),
            ]);
            Booking::factory()->heldButExpired($today)->create(['product_id' => $priced->getKey()]);
            Booking::factory()->pendingPayment()->forDeparture($today)->create(['product_id' => $priced->getKey()]);

            $cancelled = Departure::factory()->at('2026-07-09', '09:00')->cancelled()->create([
                'product_id' => $priced->getKey(),
                'vessel_id' => $boat->getKey(),
            ]);
            Booking::factory()->forDeparture($cancelled)->create(['product_id' => $priced->getKey()]);

            // A booking with no departure at all, the shape an import leaves.
            Booking::factory()->create(['product_id' => $priced->getKey(), 'departure_id' => null]);
        });
    }
}
