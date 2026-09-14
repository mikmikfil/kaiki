<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analytics\Actions\CountAnalyticsEvent;
use App\Domain\Analytics\Support\AnalyticsMetric;
use App\Models\AnalyticsDaily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A counted point, for the isolation suite.
 *
 * Nothing in the product builds one of these through a factory — counts are
 * made by {@see CountAnalyticsEvent}, which
 * increments rather than inserts. This exists because ADR-0001's isolation
 * suite walks every tenant-owned model and needs to be able to make a row of
 * each, which is the right requirement: a table nobody can build a row for is a
 * table nobody has proved is scoped.
 *
 * Every row gets its own dimension value, so two of them never collide on the
 * unique index the real writer depends on.
 *
 * @extends Factory<AnalyticsDaily>
 */
class AnalyticsDailyFactory extends Factory
{
    protected $model = AnalyticsDaily::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'date' => Carbon::now()->subDays(fake()->numberBetween(0, 60))->toDateString(),
            'metric' => fake()->randomElement(AnalyticsMetric::cases()),
            'dimension' => 'product',
            'dimension_value' => (string) Str::uuid(),
            'count' => fake()->numberBetween(1, 500),
            'value_cents' => 0,
        ];
    }
}
