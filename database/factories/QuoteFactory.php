<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quote>
 *
 * A draft by default, because that is where §4.4's state machine begins and
 * because a factory that produced `sent` rows would let a test assert a
 * transition that never happened.
 *
 * `valid_until` is a fixed offset rather than a faked date: every assertion in
 * this area is about which side of an expiry an instant falls on, and a random
 * date is a test that fails one day in seven.
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'booking_id' => Booking::factory(),
            'version' => 1,
            'status' => QuoteStatus::Draft,
            'quote_token' => Str::random(40),
            'subtotal_cents' => 95000,
            'discount_cents' => 0,
            'total_cents' => 95000,
            'deposit_cents' => 0,
            // Zero, like `BookingFactory`'s: `NoHardcodedVatRateTest` refuses a
            // statutory percentage anywhere in the source, and the rates are
            // deliberately unseeded until an accountant supplies them (CAT-11b).
            'vat_rate_bp' => 0,
            'valid_until' => now()->addDays(7),
            'message' => null,
            'terms' => null,
        ];
    }

    public function sent(): self
    {
        return $this->state(fn (): array => [
            'status' => QuoteStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /** Sent, and already past its `valid_until` — the sweeper's input. */
    public function lapsed(): self
    {
        return $this->state(fn (): array => [
            'status' => QuoteStatus::Sent,
            'sent_at' => now()->subDays(14),
            'valid_until' => now()->subDay(),
        ]);
    }

    public function accepted(): self
    {
        return $this->state(fn (): array => [
            'status' => QuoteStatus::Accepted,
            'sent_at' => now()->subDay(),
            'accepted_at' => now(),
        ]);
    }
}
