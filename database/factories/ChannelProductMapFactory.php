<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChannelKey;
use App\Models\ChannelProductMap;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelProductMap>
 */
class ChannelProductMapFactory extends Factory
{
    protected $model = ChannelProductMap::class;

    /**
     * The external id is unique per run, because the table says it must be.
     *
     * Two of these rows colliding would be a constraint violation in a test
     * that is about something else entirely, and the failure would name the
     * index rather than the mistake.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => ChannelKey::GetYourGuide,
            'product_id' => Product::factory(),
            'external_product_id' => 'GYG-' . $this->faker->unique()->numberBetween(100000, 999999),
        ];
    }

    /** A mapping for the calendar channel rather than the OTA. */
    public function ical(): self
    {
        return $this->state(fn (): array => ['channel' => ChannelKey::Ical]);
    }
}
