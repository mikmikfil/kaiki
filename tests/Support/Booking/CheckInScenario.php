<?php

declare(strict_types=1);

namespace Tests\Support\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A confirmed booking sailing at a chosen moment, with tickets.
 *
 * A class rather than Pest helpers, for the reason the other four scenario
 * classes give: four test files need this and a `function` in a Pest file is
 * scoped to that file.
 *
 * ## The times are the point, so they are parameters
 *
 * Every test in this group is about **when** — BKG-22's two edges, BKG-21's
 * three-hour grace period, a clock change in the middle of the season. So the
 * departure's start is the first argument and the product's check-in offset is
 * the second, and nothing here defaults to `now()` in a way a test would have
 * to work around.
 */
final class CheckInScenario
{
    /**
     * @param  Carbon  $startsAt  the departure's `starts_at_utc`
     * @param  int  $offsetMinutes  the product's `check_in_offset_minutes` (BKG-22)
     * @return array{0: Tenant, 1: Booking}
     */
    public static function sailing(
        Carbon $startsAt,
        int $offsetMinutes = 30,
        int $durationMinutes = 240,
        int $pax = 2,
        BookingStatus $status = BookingStatus::Confirmed,
    ): array {
        $tenant = Tenant::factory()->create();

        $booking = Tenancy::forTenant($tenant, static function () use (
            $startsAt,
            $offsetMinutes,
            $durationMinutes,
            $pax,
            $status,
        ): Booking {
            /** @var Vessel $vessel */
            $vessel = Vessel::factory()->create();

            /** @var Port $port */
            $port = Port::factory()->create();

            /** @var Product $product */
            $product = Product::factory()->create([
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port->getKey(),
                'check_in_offset_minutes' => $offsetMinutes,
                'duration_minutes' => $durationMinutes,
            ]);

            $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

            $departure = Departure::factory()->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'capacity' => 12,
                'seats_sold' => $pax,
                'seats_held' => 0,
                'min_pax' => 0,
                'starts_at_utc' => $startsAt,
                'ends_at_utc' => $endsAt,
                'local_date' => $startsAt->copy()->setTimezone('Europe/Athens')->toDateString(),
                'local_time' => $startsAt->copy()->setTimezone('Europe/Athens')->format('H:i:s'),
            ]);

            /** @var Booking $booking */
            $booking = Booking::factory()
                ->forDeparture($departure)
                ->withPax($pax, $pax)
                ->create([
                    'vessel_id' => $vessel->getKey(),
                    'status' => $status,
                    'confirmed_at' => now(),
                ]);

            // One row per person, named — a manifest a crew member can read.
            // The factory's default is a nameless row, which is what
            // confirmation really creates, but a check-in test asserting a
            // notification says "— is aboard" would be asserting nothing.
            for ($position = 1; $position <= $pax; $position++) {
                BookingGuest::factory()->create([
                    'booking_id' => $booking->getKey(),
                    'position' => $position,
                    'full_name' => $position === 1 ? 'Μαρία Παπαδοπούλου' : 'Γιώργος Παπαδόπουλος',
                    'is_lead' => $position === 1,
                    'ticket_code' => strtoupper(Str::random(24)),
                ]);
            }

            return $booking;
        });

        return [$tenant, $booking->refresh()];
    }
}
