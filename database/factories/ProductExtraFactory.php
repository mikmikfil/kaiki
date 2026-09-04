<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Extra;
use App\Models\Product;
use App\Models\ProductExtra;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductExtra> */
class ProductExtraFactory extends Factory
{
    protected $model = ProductExtra::class;

    /**
     * Every override null, because null is "inherit" and that is what the
     * overwhelming majority of real rows say.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'extra_id' => Extra::factory(),
            'price_cents_override' => null,
            'max_qty_override' => null,
            'is_required_override' => null,
            'sort_order' => 0,
        ];
    }
}
