<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonDateRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeasonDateRange> */
class SeasonDateRangeFactory extends Factory
{
    protected $model = SeasonDateRange::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-09-15',
        ];
    }
}
