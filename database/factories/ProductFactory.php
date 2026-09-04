<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * A per-seat day trip, which is the shape most of the engine is built for.
     *
     * `max_pax` is deliberately well under any plausible `capacity_max`, so a
     * test that does not care about the CAT-5 ceiling never trips over it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'trip-' . $this->faker->unique()->numberBetween(1, 99999);

        return [
            'vessel_id' => Vessel::factory(),
            'slug' => $slug,
            'category' => ProductCategory::SharedFullDay,
            'mode' => BookingMode::PerSeat,
            'title' => ['el' => 'Ημερήσια κρουαζιέρα', 'en' => 'Full-day cruise'],
            'summary' => ['el' => 'Μια μέρα στον Σαρωνικό.', 'en' => 'A day in the Saronic.'],
            'duration_minutes' => 480,
            'default_start_time' => '09:00',
            'flexible_start' => false,
            'check_in_offset_minutes' => 30,
            'min_pax' => 4,
            'max_pax' => 12,
            'min_booking_pax' => 1,
            'guest_details_required' => false,
            'guest_details_deadline_hours' => 48,
            'status' => ProductStatus::Active,
            'sort_order' => 0,
            'is_featured' => false,
            'images' => [],
        ];
    }

    /** A whole-boat charter: no seats counted, and the flexible window is available. */
    public function perVessel(): self
    {
        return $this->state(fn (): array => [
            'mode' => BookingMode::PerVessel,
            'category' => ProductCategory::PrivateFullDay,
            'min_pax' => 0,
        ]);
    }

    /** The guest proposes their own window (AVL-30) — `per_vessel` only. */
    public function flexibleStart(string $earliest = '08:00', string $latest = '16:00'): self
    {
        return $this->perVessel()->state(fn (): array => [
            'flexible_start' => true,
            'default_start_time' => null,
            'earliest_start_time' => $earliest,
            'latest_start_time' => $latest,
        ]);
    }

    /** No price is ever shown; the operator quotes by hand (BKG-24). */
    public function quote(): self
    {
        return $this->state(fn (): array => [
            'mode' => BookingMode::Quote,
            'category' => ProductCategory::Custom,
            // §2.3: nullable only for `quote` products with no fixed boat.
            'vessel_id' => null,
            'min_pax' => 0,
        ]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Draft]);
    }

    /** The §3.6 itinerary from the data model, `_geo` sidecar and all. */
    public function withItinerary(): self
    {
        return $this->state(fn (): array => [
            'itinerary_stops' => [
                'el' => [
                    ['key' => 's1', 'name' => 'Αναχώρηση — Μαρίνα Ζέας', 'description' => 'Επιβίβαση 30΄ πριν.', 'duration_minutes' => 0],
                    ['key' => 's2', 'name' => 'Όρμος Βλυχάδα', 'description' => 'Κολύμπι και σνόρκελ.', 'duration_minutes' => 60],
                ],
                'en' => [
                    ['key' => 's1', 'name' => 'Departure — Zea Marina', 'description' => 'Boarding 30 min before.', 'duration_minutes' => 0],
                    ['key' => 's2', 'name' => 'Vlychada Bay', 'description' => 'Swimming and snorkelling.', 'duration_minutes' => 60],
                ],
                '_geo' => [
                    's1' => ['lat' => 37.9339, 'lng' => 23.6512],
                    's2' => ['lat' => 37.6721, 'lng' => 23.4410],
                ],
            ],
        ]);
    }
}
