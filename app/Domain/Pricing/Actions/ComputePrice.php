<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Data\Pricing\PriceLineData;
use App\Data\Pricing\PriceQuoteData;
use App\Data\Pricing\PriceSnapshotData;
use App\Domain\Catalog\Support\AgeBandResolver;
use App\Domain\Catalog\Support\OfferedExtrasResolver;
use App\Domain\Pricing\Support\DepositCalculator;
use App\Domain\Pricing\Support\ExtraLineBuilder;
use App\Domain\Pricing\Support\PaxLineBuilder;
use App\Domain\Pricing\Support\RatePlanResolver;
use App\Enums\BookingMode;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\VatRate;
use App\Support\Money\Cents;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What a party costs (spec PRC-1, PRC-6 to PRC-16, PRC-23, PRC-24).
 *
 * ## The price is computed here and accepted from nowhere
 *
 * PRC-1. The widget runs on somebody else's page and posts a party and a date;
 * it never posts a price, and nothing in this path reads one from a request.
 * That is the reason this Action exists rather than a controller assembling
 * numbers: the same function answers the public quote endpoint (#37), the
 * operator's manual booking, and M2's booking creation, so there is exactly one
 * place where a total is decided.
 *
 * ## Not sellable is refused, not priced at zero
 *
 * PRC-5. With no resolvable rate plan there is no price, and the result is a
 * refusal rather than a snapshot full of zeroes — a zero total would be a free
 * trip that passes every arithmetic check on its way to a payment gateway.
 *
 * ## What is deliberately absent
 *
 * `discount_cents` is present and always zero: vouchers and operator discounts
 * are M2 (PRC-17 to PRC-22, PRC-11). The field exists now so the shape a
 * snapshot is read back with does not change when they land, and PRC-25's
 * ordering — deposit computed **after** the discount — is already correct in
 * {@see DepositCalculator}, which takes the total rather than the subtotal.
 *
 * Per-line VAT overrides (an extra taxed differently from the transport) are
 * also absent, as #34 scopes them out. The top-level `vat` block is here,
 * because ADR-0002 is accepted and §3.4 specifies its shape — it is null only
 * while a product has no `vat_rate_id`, which M1 still permits.
 */
final class ComputePrice
{
    /**
     * @param  array<string, int>  $paxByCode  age band code => how many
     * @param  array<int, int>  $extraQuantities  extra id => quantity
     *
     * @throws ValidationException
     */
    public function __invoke(
        Product $product,
        Carbon $date,
        array $paxByCode = [],
        array $extraQuantities = [],
        int $extraHours = 0,
    ): PriceQuoteData {
        $resolved = RatePlanResolver::forProduct($product, $date);

        if (! $resolved->isSellable()) {
            throw ValidationException::withMessages([
                'date' => [trans('pricing.quote.validation.not_sellable', [
                    'date' => $date->toDateString(),
                ])],
            ]);
        }

        $plan = $resolved->planOrFail();
        $bands = $product->ageBands()->get();

        $paxLines = $product->mode === BookingMode::PerVessel
            ? $this->vesselLines($product, $plan, $extraHours)
            : PaxLineBuilder::build($plan, $bands, $paxByCode);

        $countedPax = AgeBandResolver::countedSeats($bands, $paxByCode);
        $totalPax = AgeBandResolver::totalPersons($paxByCode);

        $extraLines = ExtraLineBuilder::build(
            OfferedExtrasResolver::forProduct($product),
            $extraQuantities,
            $countedPax,
            $totalPax,
        );

        $subtotal = $this->sum($paxLines);
        $extras = $this->sum($extraLines);

        // M2's term, present and zero. PRC-12 floors the total at zero, which
        // only matters once a voucher can exceed the subtotal.
        $discount = 0;
        $total = max(0, $subtotal + $extras - $discount);

        // Asked once, here, and carried into all three calls below. The
        // calculator is arithmetic and does not reach for a tenant of its own;
        // this Action is the only place in the pricing path that knows which
        // operator the price is being computed for.
        $deposits = $this->operatorTakesDeposits();

        $snapshot = new PriceSnapshotData(
            source: 'rate_plan',
            computedAt: Carbon::now(),
            ratePlanId: $plan->getKey(),
            season: $this->seasonBlock($resolved->season),
            mode: $product->mode->value,
            lines: [...$paxLines, ...$extraLines],
            subtotalCents: $subtotal,
            extrasCents: $extras,
            discountCents: $discount,
            totalCents: $total,
            vat: $this->vatBlock($product, $total),
            deposit: DepositCalculator::forPlan($plan, $total, $deposits),
            cancellationPolicyId: $product->effectiveCancellationPolicy()?->getKey(),
        );

        return new PriceQuoteData(
            snapshot: $snapshot,
            totalCents: $total,
            depositCents: DepositCalculator::amountCents($plan, $total, $deposits),
            balanceCents: DepositCalculator::balanceCents($plan, $total, $deposits),
            // PRC-15: a quote is a calculation with a shelf life, and the
            // shelf life is the hold TTL — the window a guest has to finish
            // checking out before the seats go back.
            expiresAt: Carbon::now()->addMinutes((int) config('kaiki.pricing.quote_ttl_minutes', 20)),
            hasOnRequestItems: $this->hasOnRequest($extraLines),
        );
    }

    /**
     * Does this operator take a deposit and collect the rest later?
     *
     * `tenants.deposits_enabled`. A rate plan may ask for 30%; whether the
     * business works that way at all is not the price list's decision, and an
     * operator who has not switched instalments on should never have one
     * computed for them.
     *
     * Null counts as off — the column is nullable so it could be added to an
     * existing table (§6), and a row written before it existed opted into
     * nothing. No tenant resolved counts as off too: a price computed outside a
     * tenant context is a bug elsewhere, and "charge the whole thing" is the
     * safe direction to fail in.
     */
    private function operatorTakesDeposits(): bool
    {
        $tenant = Tenancy::current();

        return (bool) ($tenant->deposits_enabled ?? false);
    }

    /**
     * PRC-8: the boat, plus any extension of the flexible window.
     *
     * One line rather than a pax line per person, because a charter is sold as
     * one thing. The extra hours are a second line so a guest reading the
     * receipt can see what the longer afternoon cost them.
     *
     * @return list<PriceLineData>
     */
    private function vesselLines(Product $product, RatePlan $plan, int $extraHours): array
    {
        $lines = [new PriceLineData(
            kind: 'pax',
            ref: 'vessel',
            label: $product->getTranslations('title'),
            qty: 1,
            unitPriceCents: $plan->vessel_price_cents ?? 0,
            totalCents: $plan->vessel_price_cents ?? 0,
        )];

        $hours = max(0, $extraHours);

        if ($hours > 0 && $plan->extra_hour_price_cents !== null) {
            $lines[] = new PriceLineData(
                kind: 'fee',
                ref: 'extra_hours',
                label: [
                    'el' => trans('pricing.quote.extra_hours', [], 'el'),
                    'en' => trans('pricing.quote.extra_hours', [], 'en'),
                ],
                qty: $hours,
                unitPriceCents: $plan->extra_hour_price_cents,
                totalCents: $plan->extra_hour_price_cents * $hours,
            );
        }

        return $lines;
    }

    /**
     * The VAT split of a **VAT-inclusive** total (§3.4).
     *
     * Greek passenger transport is quoted inclusive, so the gross is what the
     * guest agreed and the split is derived from it. The rate and its myDATA
     * category are copied here and frozen: a statutory change next year must
     * not rewrite an invoice issued this one (PRC-14, ADR-0002).
     *
     * Null while a product has no `vat_rate_id`. §2.3 leaves that nullable in
     * M1 so onboarding can proceed, and MYD-16 gates myDATA activation on every
     * sellable product having one — inventing a rate here would defeat that
     * gate silently.
     *
     * @return array<string, mixed>|null
     */
    private function vatBlock(Product $product, int $totalCents): ?array
    {
        $rate = $product->vat_rate_id === null
            ? null
            : VatRate::query()->find($product->vat_rate_id);

        if (! $rate instanceof VatRate) {
            return null;
        }

        $net = Cents::netOfInclusive($totalCents, $rate->rate_bp);

        return [
            'rate_bp' => $rate->rate_bp,
            'vat_category' => $rate->vat_category,
            'vat_rate_id' => $rate->getKey(),
            'included' => true,
            'net_cents' => $net,
            // The remainder, never rounded separately: two independently
            // rounded halves can fail to sum to the total they came from.
            'vat_cents' => $totalCents - $net,
        ];
    }

    /** @return array<string, mixed>|null */
    private function seasonBlock(?Season $season): ?array
    {
        if ($season === null) {
            return null;
        }

        return [
            'id' => $season->getKey(),
            'name' => $season->getTranslations('name'),
            'priority' => $season->priority,
        ];
    }

    /** @param list<PriceLineData> $lines */
    private function sum(array $lines): int
    {
        return array_sum(array_map(static fn (PriceLineData $line): int => $line->totalCents, $lines));
    }

    /** @param list<PriceLineData> $lines */
    private function hasOnRequest(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->onRequest) {
                return true;
            }
        }

        return false;
    }
}
