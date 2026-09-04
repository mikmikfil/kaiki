<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Data\Pricing\PriceLineData;
use App\Domain\Catalog\Data\OfferedExtra;
use App\Enums\ExtraPricing;
use Illuminate\Validation\ValidationException;

/**
 * Priced lines for the extras a guest chose (spec PRC-9, PRC-10).
 *
 * ## Three pricing types, three different multiplications
 *
 * - `per_booking` adds its price **once**, however many people.
 * - `per_person` multiplies by **counted** pax by default, and by **total**
 *   persons when the extra's `prices_all_pax` flag is set — the lifejacket and
 *   the lunch an infant also consumes.
 * - `on_request` adds **zero** and is still recorded. PRC-9 is explicit that a
 *   booking containing one still confirms and pays the computed total; the
 *   operator follows it up afterwards. Dropping the line would lose the
 *   guest's request entirely, and pricing it would invent a figure nobody
 *   agreed.
 *
 * ## `max_qty` is refused here and nowhere else that matters
 *
 * PRC-10 says server-side, and this is the server side. The widget's own limit
 * is a courtesy on somebody else's page; the only enforcement that counts is
 * the one a hand-made request also hits.
 */
final class ExtraLineBuilder
{
    /**
     * @param  iterable<OfferedExtra>  $offered  what this product actually offers
     * @param  array<int, int>  $quantities  extra id => quantity
     * @return list<PriceLineData>
     *
     * @throws ValidationException when a quantity exceeds `max_qty` (PRC-10)
     */
    public static function build(iterable $offered, array $quantities, int $countedPax, int $totalPax): array
    {
        $lines = [];

        foreach ($offered as $extra) {
            $quantity = max(0, $quantities[$extra->extraId] ?? 0);

            if ($quantity === 0) {
                continue;
            }

            if (! $extra->allowsQuantity($quantity)) {
                throw ValidationException::withMessages([
                    'extras' => [trans('catalog.extra.validation.max_qty_exceeded', [
                        'extra' => $extra->name,
                        'max' => (string) $extra->maxQty,
                    ])],
                ]);
            }

            $lines[] = $extra->pricingType === ExtraPricing::OnRequest
                ? self::onRequestLine($extra, $quantity)
                : self::pricedLine($extra, $quantity, $extra->paxFor($countedPax, $totalPax));
        }

        return $lines;
    }

    private static function pricedLine(OfferedExtra $extra, int $quantity, int $pax): PriceLineData
    {
        $unit = $extra->priceCents ?? 0;
        $units = $extra->pricingType === ExtraPricing::PerPerson ? $quantity * max(0, $pax) : $quantity;

        return new PriceLineData(
            kind: 'extra',
            ref: $extra->uuid,
            label: ['el' => $extra->name, 'en' => $extra->name],
            // The quantity a guest recognises: "3 transfers", not "3 × 4
            // people". The multiplication shows up in the total, which is the
            // figure they are being asked to pay.
            qty: $quantity,
            unitPriceCents: $unit,
            totalCents: $unit * max(0, $units),
        );
    }

    /** Recorded, never added (PRC-9). */
    private static function onRequestLine(OfferedExtra $extra, int $quantity): PriceLineData
    {
        return new PriceLineData(
            kind: 'extra',
            ref: $extra->uuid,
            label: ['el' => $extra->name, 'en' => $extra->name],
            qty: $quantity,
            unitPriceCents: 0,
            totalCents: 0,
            onRequest: true,
        );
    }
}
