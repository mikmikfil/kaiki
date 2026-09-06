<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtraPricing;
use App\Models\Booking;
use App\Models\BookingExtra;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingExtra>
 *
 * `extra_name` is written as a full translation set rather than a bare string,
 * because that is what the column holds and what the confirmation email will
 * read back — a fixture with only one locale is a fixture that passes every
 * test until the Greek email renders an English word.
 */
class BookingExtraFactory extends Factory
{
    protected $model = BookingExtra::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'extra_id' => null,
            'extra_name' => ['el' => 'Εξοπλισμός κατάδυσης', 'en' => 'Snorkel kit'],
            'pricing_type' => ExtraPricing::PerPerson,
            'qty' => 2,
            'unit_price_cents' => 1000,
            'total_cents' => 2000,
            'is_on_request' => false,
        ];
    }

    /**
     * Priced on request: no price yet, contributes nothing to the total.
     *
     * Zero rather than null, because the column is NOT NULL and "no price yet"
     * is `is_on_request`, not a missing number. A nullable price would make
     * every sum in the reporting layer defensive.
     */
    public function onRequest(): self
    {
        return $this->state(fn (): array => [
            'extra_name' => ['el' => 'Μεταφορά από το ξενοδοχείο', 'en' => 'Hotel transfer'],
            'is_on_request' => true,
            'unit_price_cents' => 0,
            'total_cents' => 0,
            'fulfilled_at' => null,
        ]);
    }

    public function fulfilled(): self
    {
        return $this->onRequest()->state(fn (): array => ['fulfilled_at' => now()]);
    }
}
