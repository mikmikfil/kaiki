<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuoteLineKind;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteLineItem>
 *
 * The charter line every quote has, priced at the §2.5 example's €950.
 */
class QuoteLineItemFactory extends Factory
{
    protected $model = QuoteLineItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'label' => ['el' => 'Ιδιωτική ναύλωση ολοήμερη', 'en' => 'Private charter, full day'],
            'description' => null,
            'kind' => QuoteLineKind::Charter,
            'qty' => 1,
            'unit_price_cents' => 95000,
            'total_cents' => 95000,
            'sort_order' => 0,
        ];
    }

    /** Positive cents; the `kind` carries the sign (§1.4). */
    public function discount(int $cents): self
    {
        return $this->state(fn (): array => [
            'label' => ['el' => 'Έκπτωση', 'en' => 'Discount'],
            'kind' => QuoteLineKind::Discount,
            'qty' => 1,
            'unit_price_cents' => $cents,
            'total_cents' => $cents,
            'sort_order' => 90,
        ]);
    }

    public function fee(int $cents): self
    {
        return $this->state(fn (): array => [
            'label' => ['el' => 'Καύσιμα', 'en' => 'Fuel'],
            'kind' => QuoteLineKind::Fee,
            'qty' => 1,
            'unit_price_cents' => $cents,
            'total_cents' => $cents,
            'sort_order' => 10,
        ]);
    }
}
