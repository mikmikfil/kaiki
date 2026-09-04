<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgeBandPricing;
use App\Models\AgeBand;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgeBand> */
class AgeBandFactory extends Factory
{
    protected $model = AgeBand::class;

    /**
     * The adult band: the base, full price, counts toward capacity.
     *
     * Fixed rather than random, because every age-resolution assertion in the
     * suite is about which band a given age lands in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'code' => 'adult',
            'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'],
            'min_age' => 12,
            'max_age' => null,
            'counts_toward_capacity' => true,
            'pricing_mode' => AgeBandPricing::Multiplier,
            'price_multiplier_bp' => 10000,
            'is_base' => true,
            'requires_adult' => false,
            'sort_order' => 0,
        ];
    }

    /** 3–11, half price, consumes a seat. */
    public function child(): self
    {
        return $this->state(fn (): array => [
            'code' => 'child',
            'label' => ['el' => 'Παιδί', 'en' => 'Child'],
            'min_age' => 3,
            'max_age' => 11,
            'price_multiplier_bp' => 5000,
            'is_base' => false,
            'requires_adult' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * 0–2, free, **and consumes no seat**.
     *
     * The case the whole `counts_toward_capacity` flag exists for: an infant on
     * a lap is a person aboard for the legal check and not a seat for the
     * availability engine.
     */
    public function infant(): self
    {
        return $this->state(fn (): array => [
            'code' => 'infant',
            'label' => ['el' => 'Βρέφος', 'en' => 'Infant'],
            'min_age' => 0,
            'max_age' => 2,
            'counts_toward_capacity' => false,
            'price_multiplier_bp' => 0,
            'is_base' => false,
            'requires_adult' => true,
            'sort_order' => 2,
        ]);
    }

    /**
     * A band priced on its own terms rather than off the adult fare — a senior
     * rate negotiated with a coach company, say (PRC-7).
     */
    public function fixedPrice(): self
    {
        return $this->state(fn (): array => [
            'code' => 'senior',
            'label' => ['el' => 'Άνω των 65', 'en' => 'Over 65'],
            'min_age' => 65,
            'max_age' => null,
            'pricing_mode' => AgeBandPricing::Fixed,
            'price_multiplier_bp' => null,
            'is_base' => false,
            'sort_order' => 3,
        ]);
    }
}
