<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountKind;
use App\Models\DiscountCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DiscountCode>
 *
 * 10% off every trip, open-ended and unlimited: the plainest code there is.
 */
class DiscountCodeFactory extends Factory
{
    protected $model = DiscountCode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Newsletter Ιουνίου',
            'code' => 'SUMMER' . Str::upper(Str::random(4)),
            'kind' => DiscountKind::Percent,
            'value' => 10,
            'valid_from' => null,
            'valid_until' => null,
            'max_uses' => null,
            'product_id' => null,
            'is_active' => true,
        ];
    }

    /** A fixed amount off, in cents. */
    public function fixed(int $cents): self
    {
        return $this->state(fn (): array => ['kind' => DiscountKind::Fixed, 'value' => $cents]);
    }
}
