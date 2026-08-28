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
        $this->call(DemoTenantSeeder::class);
    }
}
