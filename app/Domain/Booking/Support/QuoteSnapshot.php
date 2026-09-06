<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Data\Pricing\CancellationPolicyData;
use App\Data\Pricing\PriceLineData;
use App\Data\Pricing\PriceSnapshotData;
use App\Enums\QuoteLineKind;
use App\Models\Quote;
use App\Models\QuoteLineItem;

/**
 * An operator's quote, in the same shape as every other price (§3.4, BKG-27).
 *
 * ## Why a quote produces a `price_snapshot` at all
 *
 * BKG-27: *"the total is still stored as integer cents and still produces a
 * `price_snapshot`."* The reason is refunds. `RefundCalculator` takes a frozen
 * policy and a `paid_cents`, and CXL-1 makes that the only route to a refund —
 * so a quoted booking that carried its totals in some other shape would be a
 * booking the cancellation path could not price. §3.4's `source` field has
 * `quote` in its enumeration precisely so this can be true.
 *
 * ## Free text becomes a line, not an exception
 *
 * An operator writes "Σκάφος με πλήρωμα, 8 ώρες" and there is no rate plan
 * behind it. That maps cleanly: `ref` is `quote_line:{id}`, the label is the
 * operator's own words in both locales, and `rate_plan_id` and `season` are
 * null — which §3.4 permits and which is the honest answer to "why this price".
 * The provenance is the quote, and the quote is a row anybody can read.
 *
 * ## The discount is positive and the sign is in the kind
 *
 * §1.4, and §3.4 says it again for snapshot lines. A discount line contributes
 * to `discount_cents` and appears with a positive `total_cents`, exactly as a
 * voucher line does — so the invariant
 * `subtotal + extras − discount = total` holds for a quoted booking the same
 * way it holds for a rate-plan one, and `PriceSnapshotData` refuses to build
 * anything that does not add up.
 */
final class QuoteSnapshot
{
    /**
     * Fold a quote's lines into the §3.4 shape.
     *
     * @param  CancellationPolicyData|null  $policy  provenance only; the booking's
     *                                               own `policy_snapshot` is what a refund reads
     */
    public static function fromQuote(Quote $quote, string $mode, ?CancellationPolicyData $policy = null): PriceSnapshotData
    {
        $lines = [];
        $subtotal = 0;
        $extras = 0;
        $discount = 0;

        foreach ($quote->lineItems as $item) {
            $lines[] = self::lineOf($item);

            match ($item->kind) {
                QuoteLineKind::Charter => $subtotal += $item->total_cents,
                // A fee is money the guest pays on top of the boat, which is
                // what `extras_cents` means — not a separate bucket, because
                // §3.4's invariant has three terms and no fourth.
                QuoteLineKind::Extra, QuoteLineKind::Fee => $extras += $item->total_cents,
                QuoteLineKind::Discount => $discount += $item->total_cents,
            };
        }

        return new PriceSnapshotData(
            // §3.4's own enumeration. The field exists so a reader a year later
            // knows the number came from a person rather than from a rate plan.
            source: 'quote',
            computedAt: $quote->sent_at ?? now(),
            // Null, and honestly so: there was no rate plan and no season. The
            // provenance for "why this price" is the quote row itself.
            ratePlanId: null,
            season: null,
            mode: $mode,
            lines: $lines,
            subtotalCents: $subtotal,
            extrasCents: $extras,
            discountCents: $discount,
            totalCents: $subtotal + $extras - $discount,
            vat: self::vatOf($quote, $subtotal + $extras - $discount),
            deposit: self::depositOf($quote),
            cancellationPolicyId: $policy?->policyId,
        );
    }

    /** One quote line as a §3.4 snapshot line. */
    private static function lineOf(QuoteLineItem $item): PriceLineData
    {
        return new PriceLineData(
            kind: match ($item->kind) {
                // `charter` is not one of §3.4's four line kinds, and it is a
                // line the guest pays per booking rather than per person —
                // which is exactly what `fee` means there. Mapping it rather
                // than widening §3.4's enum keeps every existing reader of a
                // snapshot working.
                QuoteLineKind::Charter, QuoteLineKind::Fee => 'fee',
                QuoteLineKind::Extra => 'extra',
                QuoteLineKind::Discount => 'discount',
            },
            ref: 'quote_line:' . $item->getKey(),
            label: $item->getTranslations('label'),
            qty: $item->qty,
            unitPriceCents: $item->unit_price_cents,
            totalCents: $item->total_cents,
        );
    }

    /**
     * The VAT block, from the quote's own frozen rate.
     *
     * Greek passenger transport is priced **VAT-inclusive** (§3.4), so the net
     * is the total less the tax rather than the total plus it. Getting that
     * backwards inflates every quoted invoice by the rate.
     *
     * `vat_category` is deliberately absent rather than guessed: ADR-0002 and
     * CAT-11a put the AADE category on the `vat_rates` row, and a quote carries
     * a rate in basis points and no category. The myDATA client reads the
     * category and *"contains no percent→category mapping"* — inventing one
     * here would put the mapping back, in the one place nobody would look.
     *
     * @return array<string, mixed>
     */
    private static function vatOf(Quote $quote, int $totalCents): array
    {
        $rateBp = $quote->vat_rate_bp;

        // total = net × (1 + rate); so net = total ÷ (1 + rate), and the tax is
        // the remainder — computed as a subtraction so the two always sum back
        // to the total exactly, whatever the rounding did.
        $net = $rateBp === 0
            ? $totalCents
            : (int) round($totalCents * 10000 / (10000 + $rateBp));

        return [
            'rate_bp' => $rateBp,
            'included' => true,
            'net_cents' => $net,
            'vat_cents' => $totalCents - $net,
        ];
    }

    /**
     * The deposit the operator asked for, as §3.4's object.
     *
     * `fixed`, never `percent`: an operator quoting a €9 500 charter types the
     * deposit they want in euros, and recording it as a percentage would make
     * "€2 000" into "21.05%" and then round it back to something else.
     *
     * @return array<string, mixed>
     */
    private static function depositOf(Quote $quote): array
    {
        if ($quote->deposit_cents < 1) {
            return ['type' => 'none', 'amount_cents' => 0];
        }

        return ['type' => 'fixed', 'amount_cents' => $quote->deposit_cents];
    }
}
