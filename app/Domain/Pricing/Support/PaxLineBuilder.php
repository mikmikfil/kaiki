<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Data\Pricing\PriceLineData;
use App\Enums\AgeBandPricing;
use App\Models\AgeBand;
use App\Models\RatePlan;
use App\Support\Money\Cents;
use Illuminate\Support\Collection;

/**
 * One priced line per age band in the party (spec PRC-6, PRC-7).
 *
 * ## The rounding order is the whole point
 *
 * PRC-6: *"Rounding is half-up, applied per unit price, before multiplication
 * by quantity, so that ten guests are exactly ten times the displayed unit
 * price."*
 *
 * A child band at 5000 bp of a €65.01 adult fare is 3250.5 cents. Round the
 * unit and multiply: €32.51 each, €325.10 for ten. Multiply and then round:
 * €325.05 — and the guest, who was shown €32.51, is charged five cents less
 * than ten times it. Small, permanent, and impossible for an operator to
 * explain. The test that pins this uses a multiplier producing a fractional
 * cent, because round numbers cannot tell the two orders apart.
 *
 * ## A band that takes no seat is still priced
 *
 * PRC-7: *"Not counting toward capacity does not imply free."* An infant band
 * usually is free, and that is a multiplier of zero the operator chose — not a
 * consequence of the capacity flag. Conflating the two would silently stop
 * charging for any band an operator decided not to count.
 */
final class PaxLineBuilder
{
    /**
     * @param  iterable<AgeBand>  $bands  the product's bands
     * @param  array<string, int>  $paxByCode  band code => how many
     * @return list<PriceLineData>
     */
    public static function build(RatePlan $plan, iterable $bands, array $paxByCode): array
    {
        $bands = $bands instanceof Collection ? $bands : collect($bands);
        $prices = $plan->prices()->pluck('price_cents', 'age_band_id');
        $basePrice = self::basePriceCents($bands, $prices);

        $lines = [];

        foreach ($bands as $band) {
            $quantity = max(0, $paxByCode[$band->code] ?? 0);

            // A band nobody booked is not a zero line. §3.4's snapshot is read
            // by a guest, and "Infant × 0" is noise on a receipt.
            if ($quantity === 0) {
                continue;
            }

            $own = $prices->get($band->getKey());
            $unit = self::unitPriceCents($band, $prices, $basePrice);

            if ($unit === null) {
                continue;
            }

            // §3.4: present **when the price was derived from the base band**.
            // A band with its own price row was not derived from anything, and
            // that includes the base band itself — recording 10000 bp there
            // would say the adult fare is a multiple of the adult fare.
            $derived = $own === null && $band->pricing_mode === AgeBandPricing::Multiplier;

            $lines[] = new PriceLineData(
                kind: 'pax',
                ref: $band->code,
                label: $band->getTranslations('label'),
                qty: $quantity,
                unitPriceCents: $unit,
                // Rounded already, then multiplied. Never the other way round.
                totalCents: $unit * $quantity,
                multiplierBp: $derived ? $band->price_multiplier_bp : null,
            );
        }

        return $lines;
    }

    /**
     * The price for one person in this band, rounded (PRC-6).
     *
     * Null when the band cannot be priced at all — a `fixed` band with no row
     * on this plan. `SaveRatePlan` refuses to save that state, so reaching it
     * means the data was written another way, and dropping the line is safer
     * than charging zero for a passenger category.
     *
     * @param  Collection<int, int>  $prices  age band id => price cents
     */
    public static function unitPriceCents(AgeBand $band, Collection $prices, ?int $basePriceCents): ?int
    {
        $own = $prices->get($band->getKey());

        // An explicit row wins outright, whatever the pricing mode: an operator
        // who typed a price for this band on this plan meant it.
        if ($own !== null) {
            return (int) $own;
        }

        if ($band->pricing_mode !== AgeBandPricing::Multiplier || $basePriceCents === null) {
            return null;
        }

        return Cents::applyBasisPoints($basePriceCents, $band->price_multiplier_bp ?? 0);
    }

    /**
     * The base band's price on this plan — what every multiplier multiplies.
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  Collection<int, int>  $prices
     */
    public static function basePriceCents(Collection $bands, Collection $prices): ?int
    {
        $base = $bands->firstWhere('is_base', true);

        if ($base === null) {
            return null;
        }

        $price = $prices->get($base->getKey());

        return $price === null ? null : (int) $price;
    }
}
