<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Data\Pricing\PriceLineData;
use App\Data\Pricing\PriceQuoteData;
use App\Domain\Catalog\Support\AgeBandResolver;
use App\Models\Departure;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use App\Support\Locale\TranslationValue;

/**
 * A server-computed price, as `docs/api.md`'s `PriceQuote` (spec PRC-1,
 * PRC-15, PRC-16).
 *
 * The contract's own standard for this payload: *"Sufficient to explain the
 * total to a guest a year later without touching another endpoint."* That is
 * why `lines[]` carries the whole derivation rather than a total — a guest
 * asking "why is it 142,50" gets an answer, and so does the operator fielding
 * the question.
 *
 * ## Three things in the snapshot never reach the wire
 *
 * `PriceSnapshotData` is built for `bookings.price_snapshot`, which is an
 * internal record, so it carries `rate_plan_id`, `vat_rate_id` and the season's
 * `id`. All three are database ids and CNV-8 keeps them off the API. What a
 * guest needs from that provenance is the **season name**, which is what the
 * contract's `rate_plan` block exposes and all it exposes.
 *
 * ## `price_token` is deliberately absent
 *
 * The contract lists it as optional and describes it as a signed handle that
 * `POST /api/v1/bookings` verifies. Nothing verifies it yet — bookings are M2 —
 * and a token is only as good as the fields it binds. Designing it without its
 * verifier means guessing which inputs matter and shipping a signature format
 * that has to change once the answer is known, on a value the contract tells
 * clients is opaque and unparseable. `expires_at` is still returned, because it
 * is what PRC-15 is actually about: a quote is a calculation with a shelf life.
 */
final class PriceQuoteResource
{
    /**
     * @param  array<string, mixed>|null  $window  the contract's `LocalWindow`, for a charter
     * @return array<string, mixed>
     */
    public static function toArray(
        PriceQuoteData $quote,
        Product $product,
        ?Departure $departure = null,
        ?array $window = null,
    ): array {
        $snapshot = $quote->snapshot;
        $locale = app()->getLocale();
        $paxByCode = self::paxFrom($snapshot->lines);

        return [
            'product_uuid' => $product->uuid,
            'mode' => $snapshot->mode,
            'departure_uuid' => $departure?->uuid,
            'window' => $window,
            'currency' => MoneyFormatter::currency(),
            'pax_total' => AgeBandResolver::totalPersons($paxByCode),
            'pax_capacity_total' => AgeBandResolver::countedSeats($product->ageBands, $paxByCode),
            'lines' => array_map(
                static fn (PriceLineData $line): array => self::line($line, $locale),
                $snapshot->lines,
            ),
            'subtotal_cents' => $snapshot->subtotalCents,
            'extras_cents' => $snapshot->extrasCents,
            'discount_cents' => $snapshot->discountCents,
            'total_cents' => $snapshot->totalCents,
            'total_formatted' => MoneyFormatter::format($snapshot->totalCents, $locale, MoneyFormatter::currency()),
            'vat' => self::vat($snapshot->vat),
            'deposit' => self::deposit($quote),
            // PRC-18 is M2, and the request refuses a voucher code rather than
            // ignoring one, so this is null rather than absent for the reason
            // the branding payload gives: a client that branches on presence
            // breaks the day the field starts carrying a value.
            'voucher' => null,
            'on_request_items' => self::onRequestItems($snapshot->lines, $locale),
            'cancellation_policy' => self::policy($product),
            'rate_plan' => [
                'season_name' => $snapshot->season === null
                    ? null
                    : TranslationValue::resolve($snapshot->season['name'] ?? null, $locale),
                'source' => $snapshot->source,
            ],
            'expires_at' => $quote->expiresAt->toIso8601ZuluString(),
            'computed_at' => $snapshot->computedAt->toIso8601ZuluString(),
            // CNV-1 and PRC-16: one rounding mode, stated, so a client
            // reproducing a figure for display cannot pick a different one.
            'rounding' => 'HALF_UP',
        ];
    }

    /** @return array<string, mixed> */
    private static function line(PriceLineData $line, string $locale): array
    {
        return [
            'kind' => $line->kind,
            'ref' => $line->ref,
            'label' => TranslationValue::resolve($line->label, $locale),
            'qty' => $line->qty,
            'unit_price_cents' => $line->unitPriceCents,
            'total_cents' => $line->totalCents,
            'multiplier_bp' => $line->multiplierBp,
        ];
    }

    /**
     * The contract's `VatBreakdown`, which is narrower than the snapshot's.
     *
     * `vat_rate_id` is dropped outright — an internal id (CNV-8). `vat_category`
     * survives: it is the AADE classification settled by ADR-0002, the issue
     * asks for it by name, and `docs/api.md` gained the field in this issue with
     * the reason recorded in `CHANGELOG.md` per §10.5.
     *
     * @param  array<string, mixed>|null  $vat
     * @return array<string, mixed>|null
     */
    private static function vat(?array $vat): ?array
    {
        if ($vat === null) {
            return null;
        }

        return [
            'rate_bp' => $vat['rate_bp'] ?? null,
            'vat_category' => $vat['vat_category'] ?? null,
            'included' => $vat['included'] ?? true,
            'net_cents' => $vat['net_cents'] ?? null,
            'vat_cents' => $vat['vat_cents'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function deposit(PriceQuoteData $quote): array
    {
        $deposit = $quote->snapshot->deposit;

        return [
            'type' => $deposit['type'] ?? 'none',
            'percent' => $deposit['percent'] ?? null,
            'amount_cents' => $quote->depositCents,
            'amount_formatted' => MoneyFormatter::format(
                $quote->depositCents,
                app()->getLocale(),
                MoneyFormatter::currency(),
            ),
            'balance_cents' => $quote->balanceCents,
            // The balance policy is M2's, with the booking it is due against.
            // Null is the contract's own answer for "the deposit model does not
            // apply", and no balance is due until there is a booking.
            'balance_due_at' => null,
        ];
    }

    /**
     * PRC-9: extras with no price, listed so a guest is never surprised.
     *
     * They are in `lines[]` too, at zero, because the snapshot records what was
     * asked for. This block is what a client renders as "price on request", and
     * it exists so nobody has to filter `lines` on a boolean to find them.
     *
     * @param  list<PriceLineData>  $lines
     * @return list<array<string, mixed>>
     */
    private static function onRequestItems(array $lines, string $locale): array
    {
        $items = [];

        foreach ($lines as $line) {
            if (! $line->onRequest) {
                continue;
            }

            $items[] = [
                'extra_uuid' => $line->ref,
                'name' => TranslationValue::resolve($line->label, $locale),
                'qty' => $line->qty,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    private static function policy(Product $product): ?array
    {
        $policy = $product->effectiveCancellationPolicy();

        return $policy === null
            ? null
            : (new CancellationPolicySummaryResource($policy))->resolve();
    }

    /**
     * The party, recovered from the pax lines.
     *
     * Read back off the snapshot rather than taken from the request, so
     * `pax_total` and `pax_capacity_total` describe **what was priced**. Taking
     * them from the request would let the two disagree the first time a band is
     * dropped as unknown — and the numbers a guest checks against their party
     * would be the ones nothing was charged for.
     *
     * @param  list<PriceLineData>  $lines
     * @return array<string, int>
     */
    private static function paxFrom(array $lines): array
    {
        $pax = [];

        foreach ($lines as $line) {
            if ($line->kind === 'pax') {
                $pax[$line->ref] = ($pax[$line->ref] ?? 0) + $line->qty;
            }
        }

        return $pax;
    }
}
