<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VoucherReason;
use App\Enums\VoucherStatus;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Voucher>
 *
 * `remaining_cents` starts equal to `amount_cents`, because that is the only
 * state a freshly issued voucher can be in — a factory able to produce a
 * partially spent voucher with no redemption rows behind it would let a test
 * assert against a state the application cannot reach.
 */
class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $amount = 5000;

        return [
            'uuid' => (string) Str::uuid(),
            // Upper-case and ambiguity-free, like a real one. Not the booking
            // reference alphabet: ADR-0007 is explicit that vouchers have their
            // own format and do not share that space.
            'code' => 'GIFT-' . strtoupper(Str::random(6)),
            'amount_cents' => $amount,
            'remaining_cents' => $amount,
            'currency' => 'EUR',
            'status' => VoucherStatus::Active,
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'issued_for_booking_id' => null,
            'reason' => VoucherReason::Goodwill,
        ];
    }

    /** Past its date but not yet swept — the state the read-side check exists for. */
    public function lapsed(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
            'status' => VoucherStatus::Active,
        ]);
    }

    public function fullySpent(): self
    {
        return $this->state(fn (): array => [
            'remaining_cents' => 0,
            'status' => VoucherStatus::Redeemed,
        ]);
    }

    /** Never expires, which is a real operator choice rather than a missing value. */
    public function perpetual(): self
    {
        return $this->state(fn (): array => ['expires_at' => null]);
    }
}
