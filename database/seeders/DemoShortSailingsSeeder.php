<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * One sailing a day short of its minimum, not every sailing (Mike, 2026-09-23).
 *
 * The demo schedules every trip every day for months and books only a few of
 * them, so nearly every departure sat under its minimum. «Χρειάζονται προσοχή»
 * then held an endless queue: answer a row and the next one took its place, and
 * the list looked as if it ignored the click. A real operator has one or two of
 * these a day, which is what makes the question worth asking.
 *
 * So each day keeps its first short sailing as the example, and every other one
 * has its minimum lifted to zero — the per-departure copy only, never the trip's
 * own `min_pax`, which is what guests are shown. Runs after `DemoBookingSeeder`,
 * because a booking is what decides which sailings are short.
 */
class DemoShortSailingsSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()
            ->whereIn('slug', ['aegean-blue', 'ionian-sunset', 'actionseaze'])
            ->get()
            ->each(function (Tenant $tenant): void {
                Tenancy::forTenant($tenant, function () use ($tenant): void {
                    $this->thin($tenant->timezone);
                });
            });
    }

    private function thin(string $timezone): void
    {
        $kept = [];
        $lift = [];

        Departure::query()
            ->where('status', DepartureStatus::Scheduled->value)
            ->where('min_pax', '>', 0)
            ->whereColumn('seats_sold', '<', 'min_pax')
            ->where('starts_at_utc', '>=', now())
            ->orderBy('starts_at_utc')
            ->select(['id', 'starts_at_utc'])
            ->each(function (Departure $departure) use ($timezone, &$kept, &$lift): void {
                $day = $departure->starts_at_utc->copy()->setTimezone($timezone)->toDateString();

                if (isset($kept[$day])) {
                    $lift[] = $departure->getKey();

                    return;
                }

                $kept[$day] = true;
            });

        foreach (array_chunk($lift, 500) as $ids) {
            Departure::query()->whereKey($ids)->update(['min_pax' => 0]);
        }
    }
}
