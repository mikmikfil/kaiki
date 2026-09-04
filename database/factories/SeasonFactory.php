<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonDateRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Season> */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => ['el' => 'Υψηλή περίοδος', 'en' => 'High season'],
            'code' => 'HIGH' . $this->faker->unique()->numberBetween(1, 99999),
            'priority' => 10,
            'is_active' => true,
        ];
    }

    public function priority(int $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Attach a range. Dates are fixed, never faked: every resolution assertion
     * in the suite is about which side of a boundary a date falls.
     */
    public function withRange(string $startsOn, string $endsOn): self
    {
        return $this->afterCreating(function (Season $season) use ($startsOn, $endsOn): void {
            SeasonDateRange::query()->create([
                'season_id' => $season->getKey(),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);
        });
    }
}
