<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DepositType;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RatePlan> */
class RatePlanFactory extends Factory
{
    protected $model = RatePlan::class;

    /**
     * The product default, no deposit. Prices are fixed rather than faked:
     * every assertion in the suite is about the arithmetic.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'season_id' => null,
            'name' => null,
            'vessel_price_cents' => null,
            'extra_hour_price_cents' => null,
            'deposit_type' => DepositType::None,
            'deposit_percent' => null,
            'deposit_fixed_cents' => null,
            'min_lead_time_hours' => 0,
            'max_advance_days' => null,
            'min_pax_override' => null,
            'is_active' => true,
        ];
    }

    public function forSeason(Season $season): self
    {
        return $this->state(fn (): array => ['season_id' => $season->getKey()]);
    }

    /** A whole-boat plan: one price, no per-band rows. */
    public function perVessel(int $priceCents = 60000): self
    {
        return $this->state(fn (): array => ['vessel_price_cents' => $priceCents]);
    }

    public function depositPercent(int $percent = 30): self
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Percent,
            'deposit_percent' => $percent,
            'deposit_fixed_cents' => null,
        ]);
    }

    public function depositFixed(int $cents = 20000): self
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Fixed,
            'deposit_fixed_cents' => $cents,
            'deposit_percent' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
