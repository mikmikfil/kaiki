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

        // Then the fleet: ten boats and ten trips per operator, topped up around
        // whatever the seeders above already made.
        //
        // The screens this exists for only become themselves at scale. A vessel
        // calendar with one boat is a line rather than a fleet; a catalogue
        // search over two trips cannot demonstrate a filter; and the dashboard
        // figures read as arithmetic on two rows and as a business on twenty.
        $this->call(DemoFleetSeeder::class);

        // After the products, because one of its entries belongs to a trip: the
        // FAQ of #103, which is invisible until somebody writes one — the block
        // renders nothing when there is nothing published, so an unseeded demo
        // shows the feature as an absence.
        $this->call(DemoFaqSeeder::class);

        // Last, because it references the products and the FAQ entries above.
        //
        // The demo home page had never been seeded: it was arranged by hand
        // during #102 and existed only in one development database, so a fresh
        // `migrate:fresh --seed` served the default fallback instead. That is
        // how the "about us" block came to look unbuilt — it has had a
        // template, an `image_side` control and a panel form since #102 and was
        // on nobody's screen.
        $this->call(DemoHomePageSeeder::class);
    }
}
