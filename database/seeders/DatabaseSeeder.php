<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately does **not** use `WithoutModelEvents`. Model events are how
     * `uuid` and `tenant_id` get assigned (data-model §1.1, §1.2), so muting
     * them here would seed rows with a null public identifier and no tenant —
     * and the failure would surface much later, as a confusing null uuid rather
     * than as a seeding error.
     */
    public function run(): void
    {
        // Before the tenants, because it belongs to nobody: `vat_rates` is
        // platform-owned reference data (ADR-0002), and the row it writes is an
        // explicit placeholder rather than a rate — see the seeder.
        $this->call(PlaceholderVatRateSeeder::class);

        $this->call(DemoTenantSeeder::class);

        // After the tenants, and inside their context: ports and vessels are
        // tenant-owned, so there is nothing for them to belong to until the
        // operators above exist.
        $this->call(DemoCatalogSeeder::class);

        // Last, because it needs the ports and vessels above: the rest of the
        // chain a booking actually requires — age bands, a season, a rate plan,
        // a schedule and the departures it generates. Without it `/app` has rows
        // to look at and nothing anybody can book, which is the state a demo is
        // least useful in.
        $this->call(DemoBookableSeeder::class);

        // After the products, because one of its entries belongs to a trip: the
        // FAQ of #103, which is invisible until somebody writes one — the block
        // renders nothing when there is nothing published, so an unseeded demo
        // shows the feature as an absence.
        $this->call(DemoFaqSeeder::class);
    }
}
