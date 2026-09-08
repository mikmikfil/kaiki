<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SeriesCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeriesCounter> */
class SeriesCounterFactory extends Factory
{
    protected $model = SeriesCounter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'series' => 'A',
            'year' => (int) now()->format('Y'),
            'last_number' => 0,
        ];
    }
}
