<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use App\Enums\ExtraPricing;
use App\Models\Extra;
use App\Models\ProductExtra;
use Spatie\LaravelData\Data;

/**
 * An extra as one product actually offers it — overrides already applied.
 *
 * The point of a value object here rather than a model with a pivot hanging off
 * it: **every caller downstream would otherwise re-implement the `??` chain**.
 * Pricing, the API payload, the checkout form and the extras snapshot all need
 * "the price for this extra on this product", and three of them getting it
 * right is not good enough.
 *
 * `null` on an override means inherit, so the resolution is a coalesce — except
 * for `isRequired`, where the override is a **tri-state boolean** and `false`
 * must beat the extra's `true`. `??` handles that correctly and `?:` does not,
 * which is the bug this object exists to make impossible.
 */
final class OfferedExtra extends Data
{
    public function __construct(
        public readonly int $extraId,
        public readonly string $uuid,
        public readonly string $name,
        public readonly ExtraPricing $pricingType,
        public readonly ?int $priceCents,
        public readonly ?int $maxQty,
        public readonly bool $isRequired,
        public readonly ?int $vatRateId,
        public readonly int $sortOrder,
    ) {}

    /** Resolve one extra against an optional pivot row. */
    public static function resolve(Extra $extra, ?ProductExtra $pivot = null): self
    {
        // Read once, up front. `$pivot?->x ?? $y` is redundant — `??` already
        // suppresses the null-object access on its left — and PHPStan says so.
        // Naming them also makes the coalesce below read as what it is: three
        // independent inherit-or-override decisions.
        $priceOverride = $pivot?->price_cents_override;
        $maxQtyOverride = $pivot?->max_qty_override;
        $requiredOverride = $pivot?->is_required_override;
        $sortOverride = $pivot?->sort_order;

        return new self(
            extraId: $extra->getKey(),
            uuid: $extra->uuid,
            name: $extra->name,
            pricingType: $extra->pricing_type,
            // An `on_request` extra never carries a price, whatever an override
            // says — the override cannot promote it into the total.
            priceCents: $extra->hasPrice()
                ? ($priceOverride ?? $extra->price_cents)
                : null,
            maxQty: $maxQtyOverride ?? $extra->max_qty,
            // `??` and never `?:`: the override is tri-state, and `false` is a
            // real answer that must beat the extra's `true`.
            isRequired: $requiredOverride ?? $extra->is_required,
            vatRateId: $extra->vat_rate_id,
            sortOrder: $sortOverride ?? $extra->sort_order,
        );
    }

    /**
     * Is this quantity allowed (PRC-10)?
     *
     * Server-side, and the only authority: a client-side maximum is a
     * suggestion, and the widget is on somebody else's page.
     */
    public function allowsQuantity(int $quantity): bool
    {
        if ($quantity < 0) {
            return false;
        }

        return $this->maxQty === null || $quantity <= $this->maxQty;
    }

    /**
     * What this extra costs for a party of `$pax`, in cents.
     *
     * Null for `on_request` — it never enters a total, and returning zero would
     * make it look free rather than unpriced.
     */
    public function totalCents(int $quantity, int $pax): ?int
    {
        if ($this->priceCents === null) {
            return null;
        }

        $units = $this->pricingType->scalesWithPax() ? $quantity * max(0, $pax) : $quantity;

        return $this->priceCents * max(0, $units);
    }
}
