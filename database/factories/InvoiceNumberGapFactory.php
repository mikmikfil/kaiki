<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceNumberGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceNumberGap> */
class InvoiceNumberGapFactory extends Factory
{
    protected $model = InvoiceNumberGap::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'series' => 'A',
            'year' => (int) now()->format('Y'),
            'number' => $this->faker->numberBetween(1, 500),
            'invoice_id' => Invoice::factory(),
            'reason_code' => InvoiceNumberGap::REASON_RETRIES_EXHAUSTED,
        ];
    }
}
