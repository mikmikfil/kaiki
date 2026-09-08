<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Booking;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 *
 * The default is a **`pending` row with no number**, because that is what
 * `IssueInvoice` writes and what every retry and allocation test starts from.
 * A factory that handed out `sent` rows with numbers would make the state this
 * domain spends most of its time in the awkward one to construct.
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // 100.00 inclusive at 13%: net 88.50, VAT 11.50. Real arithmetic rather
        // than round numbers that hide a rounding bug — the halves must sum to
        // the gross exactly (MYD-7), and a factory of 100/0/100 would never
        // exercise that.
        $total = 10_000;
        $net = 8_850;

        return [
            'booking_id' => Booking::factory(),
            'type' => InvoiceType::Alp,
            'series' => 'A',
            // MYD-4.2: no number until the send attempt.
            'number' => null,
            'year' => (int) now()->format('Y'),
            'net_cents' => $net,
            'vat_cents' => $total - $net,
            'total_cents' => $total,
            'vat_rate_bp' => 1_300,
            'vat_category' => 'VAT_2',
            'status' => InvoiceStatus::Pending,
            'retries' => 0,
            'environment' => 'dev',
        ];
    }

    /** A document AADE has accepted, with a MARK and a number. */
    public function sent(int $number = 1): self
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Sent,
            'number' => $number,
            'issued_at' => now(),
            'mark' => (string) $this->faker->numberBetween(400000000000000, 499999999999999),
        ]);
    }

    /** Attempts exhausted (MYD-10) — the row the failure feed shows. */
    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Failed,
            'retries' => 8,
            'last_error_code' => '243',
            'next_retry_at' => null,
        ]);
    }

    public function tpy(): self
    {
        return $this->state(fn (): array => [
            'type' => InvoiceType::Tpy,
            'counterparty_vat' => '094014201',
            'counterparty_name' => 'Παράδειγμα ΑΕ',
            'counterparty_country' => 'GR',
        ]);
    }
}
