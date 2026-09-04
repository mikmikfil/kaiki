<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtraPricing;
use App\Models\Extra;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Extra> */
class ExtraFactory extends Factory
{
    protected $model = Extra::class;

    /**
     * A per-person lunch at €15. Fixed, because every price assertion in the
     * suite is about the arithmetic rather than the number.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ['el' => 'Γεύμα', 'en' => 'Lunch'],
            'description' => ['el' => 'Γεύμα και ποτό εν πλω.', 'en' => 'Lunch and a drink on board.'],
            'pricing_type' => ExtraPricing::PerPerson,
            'price_cents' => 1500,
            'max_qty' => null,
            'is_tenant_wide' => false,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** Offered on every product unless a pivot says otherwise. */
    public function tenantWide(): self
    {
        return $this->state(fn (): array => ['is_tenant_wide' => true]);
    }

    /** One charge for the whole booking, however many people. */
    public function perBooking(int $priceCents = 4000): self
    {
        return $this->state(fn (): array => [
            'name' => ['el' => 'Μεταφορά από το ξενοδοχείο', 'en' => 'Hotel transfer'],
            'pricing_type' => ExtraPricing::PerBooking,
            'price_cents' => $priceCents,
        ]);
    }

    /** «Κατόπιν αιτήματος» — no price, never in a total (CAT-12). */
    public function onRequest(): self
    {
        return $this->state(fn (): array => [
            'name' => ['el' => 'Ιδιωτικός σεφ', 'en' => 'Private chef'],
            'pricing_type' => ExtraPricing::OnRequest,
            'price_cents' => null,
        ]);
    }

    public function requiredExtra(): self
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    public function limitedTo(int $maxQty): self
    {
        return $this->state(fn (): array => ['max_qty' => $maxQty]);
    }
}
