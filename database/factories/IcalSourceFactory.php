<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IcalSource;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IcalSource> */
class IcalSourceFactory extends Factory
{
    protected $model = IcalSource::class;

    /**
     * `url_hash` is deliberately absent: the model's mutator maintains it, and
     * a factory that set it by hand would let a real writer forget to.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vessel_id' => Vessel::factory(),
            'name' => 'Airbnb',
            'url' => 'https://www.airbnb.com/calendar/ical/' . $this->faker->unique()->numberBetween(1, 99999) . '.ics',
            'is_active' => true,
            'sync_interval_minutes' => 15,
            'consecutive_failures' => 0,
            'events_imported' => 0,
        ];
    }

    public function failing(int $times = 10): self
    {
        return $this->state(fn (): array => [
            'consecutive_failures' => $times,
            'last_error' => 'HTTP 404',
        ]);
    }
}
