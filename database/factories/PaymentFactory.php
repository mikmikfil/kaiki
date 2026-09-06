<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 *
 * The default is a **succeeded** full payment, because that is the state most
 * tests need a booking to be in and the one `paid_cents` is computed from. A
 * `pending` default would make every arithmetic assertion have to succeed the
 * row first.
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'booking_id' => Booking::factory(),
            'gateway' => PaymentGatewayName::Viva,
            'kind' => PaymentKind::Full,
            'amount_cents' => 12000,
            'currency' => 'EUR',
            'status' => PaymentStatus::Succeeded,
            'idempotency_key' => (string) Str::uuid(),
            'paid_at' => now(),
        ];
    }

    /** Created before the redirect: nothing has been taken yet. */
    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);
    }

    public function deposit(int $cents): self
    {
        return $this->state(fn (): array => ['kind' => PaymentKind::Deposit, 'amount_cents' => $cents]);
    }

    /**
     * A refund against a charge.
     *
     * A row of its own pointing at what it reverses, never a negative amount:
     * every money column here is unsigned and §1.4 puts the sign in the meaning.
     */
    public function refundOf(Payment $original, ?int $cents = null): self
    {
        return $this->state(fn (): array => [
            'booking_id' => $original->booking_id,
            'kind' => PaymentKind::Refund,
            'amount_cents' => $cents ?? $original->amount_cents,
            'refunds_payment_id' => $original->getKey(),
            'refunded_at' => now(),
        ]);
    }

    /** Cash at the desk (BKG-33): recorded, never taken. */
    public function cash(): self
    {
        return $this->state(fn (): array => ['gateway' => PaymentGatewayName::Cash]);
    }
}
