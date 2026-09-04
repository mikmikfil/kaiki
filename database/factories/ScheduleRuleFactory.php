<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Availability\Support\WeekdayMask;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleRule> */
class ScheduleRuleFactory extends Factory
{
    protected $model = ScheduleRule::class;

    /**
     * Daily, all summer, at nine. Fixed dates rather than faked: every
     * assertion in the suite is about which side of a boundary a date lands.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'vessel_id' => null,
            'weekday_mask' => WeekdayMask::DAILY,
            'start_time' => '09:00',
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-09-15',
            'capacity_override' => null,
            'generate_days_ahead' => 180,
            'is_active' => true,
            'last_generated_on' => null,
        ];
    }

    /** @param list<int> $isoWeekdays */
    public function onDays(array $isoWeekdays): self
    {
        return $this->state(fn (): array => ['weekday_mask' => WeekdayMask::fromDays($isoWeekdays)]);
    }

    public function openEnded(): self
    {
        return $this->state(fn (): array => ['valid_until' => null]);
    }

    public function withCapacity(int $capacity): self
    {
        return $this->state(fn (): array => ['capacity_override' => $capacity]);
    }

    /** A second boat on the same trip — the reason the override exists. */
    public function onVessel(?Vessel $vessel = null): self
    {
        return $this->state(fn (): array => [
            'vessel_id' => ($vessel ?? Vessel::factory()->create())->getKey(),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
