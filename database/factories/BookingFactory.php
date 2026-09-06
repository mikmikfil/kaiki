<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Availability\Actions\HoldSeats;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Support\Booking\BookingReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 *
 * A confirmed per-seat booking for two adults, by default — the ordinary case,
 * so a test that cares about something else states only that thing.
 *
 * **The hold states write `hold_expires_at` directly and do not go through
 * {@see HoldSeats}.** That is deliberate and it is the one place in the project
 * where writing that column outside the three permitted Actions is correct: a
 * factory setting up "a hold that expired forty minutes ago" is describing a
 * past the Action cannot produce, and routing it through the Action would take
 * the cache lock and touch `departures.seats_held` for a fixture. The
 * arrangement is the test's; the behaviour under test is the Action's.
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Fixed, never faked: the whole suite asserts which side of a boundary
        // an instant falls on, and a random date is a test that fails one day
        // in seven.
        $start = now()->parse('2026-07-04 06:00:00');

        return [
            'uuid' => (string) Str::uuid(),
            'reference' => (string) BookingReference::generate(),
            'product_id' => Product::factory(),
            'vessel_id' => null,
            'departure_id' => null,
            'mode' => BookingMode::PerSeat,
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Widget,
            'locale' => 'el',

            'local_date' => '2026-07-04',
            'local_time' => '09:00',
            'starts_at_utc' => $start,
            'ends_at_utc' => $start->copy()->addHours(8),

            'guest_name' => 'Maria Papadopoulou',
            'guest_email' => 'maria@example.gr',
            'guest_phone' => '+306912345678',

            'pax_total' => 2,
            'pax_capacity_total' => 2,
            'pax_breakdown' => [
                ['code' => 'adult', 'qty' => 2, 'counts_toward_capacity' => true],
            ],
            'extras_snapshot' => [],
            'policy_snapshot' => null,
            'price_snapshot' => null,

            'subtotal_cents' => 12000,
            'extras_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => 12000,
            'deposit_cents' => 0,
            'paid_cents' => 12000,
            'balance_cents' => 0,
            'refunded_cents' => 0,

            'vat_rate_bp' => 0,
            'vat_category' => '',
            'vat_cents' => 0,

            'guest_details_status' => GuestDetailsStatus::NotRequired,
            'manage_token' => Str::random(40),
            'hold_expires_at' => null,
            'confirmed_at' => now(),
            'terms_accepted_at' => now(),
            'is_test' => false,
        ];
    }

    /** A draft holding its seats, with the full window still to run. */
    public function holding(?Departure $departure = null): self
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Draft,
            'confirmed_at' => null,
            'paid_cents' => 0,
            'balance_cents' => 12000,
            'hold_expires_at' => HoldSeats::expiryFrom(now()),
            'departure_id' => $departure?->getKey(),
            'vessel_id' => $departure?->vessel_id,
        ]);
    }

    /**
     * A draft whose hold lapsed.
     *
     * Forty minutes, comfortably past any plausible `hold_minutes`, so the test
     * does not silently depend on the configured window.
     */
    public function heldButExpired(?Departure $departure = null): self
    {
        return $this->holding($departure)->state(fn (): array => [
            'hold_expires_at' => now()->subMinutes(40),
        ]);
    }

    /** At the gateway: seats committed rather than held (BKG-9). */
    public function pendingPayment(): self
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::PendingPayment,
            'confirmed_at' => null,
            'paid_cents' => 0,
            'balance_cents' => 12000,
            'hold_expires_at' => HoldSeats::expiryFrom(now()),
        ]);
    }

    public function forDeparture(Departure $departure): self
    {
        return $this->state(fn (): array => [
            'departure_id' => $departure->getKey(),
            'product_id' => $departure->product_id,
            'vessel_id' => $departure->vessel_id,
            'local_date' => $departure->local_date->toDateString(),
            'local_time' => (string) $departure->local_time,
            'starts_at_utc' => $departure->starts_at_utc,
            'ends_at_utc' => $departure->ends_at_utc,
        ]);
    }

    /** @param  array<string, int>  $breakdown  band code => how many */
    public function withPax(int $capacityCounting, int $total, array $breakdown = []): self
    {
        return $this->state(fn (): array => [
            'pax_capacity_total' => $capacityCounting,
            'pax_total' => $total,
            'pax_breakdown' => $breakdown === []
                ? [['code' => 'adult', 'qty' => $capacityCounting, 'counts_toward_capacity' => true]]
                : $breakdown,
        ]);
    }

    /** A private charter occupying a window rather than seats. */
    public function perVessel(): self
    {
        return $this->state(fn (): array => ['mode' => BookingMode::PerVessel, 'departure_id' => null]);
    }
}
