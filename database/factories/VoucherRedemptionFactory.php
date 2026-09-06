<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoucherRedemption>
 *
 * Nothing reversed by default, which is what an ordinary redemption looks like.
 * A reversal is arranged by the test that is about reversals.
 */
class VoucherRedemptionFactory extends Factory
{
    protected $model = VoucherRedemption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'voucher_id' => Voucher::factory(),
            'booking_id' => Booking::factory(),
            'amount_cents' => 2000,
            'redeemed_at' => now(),
            'reversed_amount_cents' => 0,
        ];
    }

    public function reversed(?int $cents = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'reversed_at' => now(),
            'reversed_amount_cents' => $cents ?? $attributes['amount_cents'],
        ]);
    }
}
