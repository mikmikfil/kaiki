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
    }
}
