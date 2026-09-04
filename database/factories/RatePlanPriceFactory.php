<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgeBand;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RatePlanPrice> */
class RatePlanPriceFactory extends Factory
{
    protected $model = RatePlanPrice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'rate_plan_id' => RatePlan::factory(),
            'age_band_id' => AgeBand::factory(),
            'price_cents' => 5000,
        ];
    }

    public function priced(int $cents): self
    {
        return $this->state(fn (): array => ['price_cents' => $cents]);
    }
}
