<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Support\Format\DateTimeFormatter;
use App\Support\Format\MoneyFormatter;

/**
 * One rate plan, read as a line an operator can scan (product owner,
 * 2026-09-21, direction Α).
 *
 * The «Τιμές» screen listed price lists **without a price in them** — product,
 * period, name, deposit type, active. Everything except the number the screen
 * is named after. This class is the number, and the three other things that
 * have to sit beside it before the line answers *«τόσο τη θερινή περίοδο»*:
 * which period, which dates, and what the guest pays up front.
 *
 * ## It reads, it never resolves
 *
 * This is **not** pricing. It reports what one plan has stored. The engine that
 * decides what a party actually pays on a date is `QuotePrice` and it stays the
 * only one: a summary that did its own arithmetic would be a second pricing
 * rule, and the two would disagree the first time somebody changed one.
 *
 * ## The headline depends on what is being sold
 *
 * A whole-boat charter has one number — the boat. A per-seat trip has one per
 * age band, and the one that belongs on the line is the **base** band's, which
 * is the same figure the guest sees as «από». A `quote` product has neither by
 * design, so it reports null rather than a zero, for the reason
 * `price_from_cents` does: «0,00 €» on a trip agreed by phone is a free trip.
 */
final class PlanSummary
{
    /**
     * The one price this plan leads with, in cents, or null when it has none.
     *
     * Null is a real answer here and the screen must show it as one: a plan
     * saved before its bands were priced is exactly what an operator is looking
     * for on this screen.
     */
    public static function headlineCents(RatePlan $plan): ?int
    {
        $product = $plan->product;

        if ($product?->mode === BookingMode::PerVessel) {
            return $plan->vessel_price_cents;
        }

        if ($product?->mode === BookingMode::Quote) {
            return null;
        }

        $baseBandId = self::baseBand($product)?->getKey();

        if ($baseBandId === null) {
            return null;
        }

        foreach ($plan->prices as $price) {
            if ($price->age_band_id === $baseBandId) {
                return $price->price_cents;
            }
        }

        return null;
    }

    /** «65,00 €», or null when there is nothing to show. */
    public static function headline(RatePlan $plan): ?string
    {
        $cents = self::headlineCents($plan);

        return $cents === null ? null : MoneyFormatter::format($cents);
    }

    /**
     * The rest of the line: the bands that are not the base one, or the terms
     * of a whole-boat price.
     *
     * «Παιδί 3–11 · 38 € · Βρέφος · 0 €» for a per-seat trip; «έως 8 άτομα ·
     * +60 € το άτομο · +110 € η ώρα» for a charter. Empty string rather than
     * null, because it is a column description and Filament hides an empty one.
     */
    public static function detail(RatePlan $plan): string
    {
        $product = $plan->product;

        if ($product?->mode === BookingMode::PerVessel) {
            return self::vesselDetail($plan);
        }

        $baseBandId = self::baseBand($product)?->getKey();

        /** @var array<int, AgeBand> $bands */
        $bands = $product?->ageBands->keyBy(static fn (AgeBand $band): int => $band->getKey())->all() ?? [];

        $parts = [];

        foreach ($plan->prices as $price) {
            if ($price->age_band_id === $baseBandId) {
                continue;
            }

            $band = $bands[$price->age_band_id] ?? null;

            if ($band === null) {
                continue;
            }

            $parts[] = $band->label . ' ' . MoneyFormatter::format($price->price_cents);
        }

        return implode(' · ', $parts);
    }

    /** «Όλο τον χρόνο», or the season's own name. */
    public static function period(RatePlan $plan): string
    {
        return $plan->isDefault()
            ? __('pricing.on_product.season.default')
            : (string) $plan->season?->name;
    }

    /**
     * When the period runs, or an em dash for the default plan.
     *
     * **A season has many date ranges**, and that is the whole reason this is a
     * method rather than two columns: «Θερινή» can be June-to-September *and*
     * the fortnight either side of Easter. The line shows the first and counts
     * the rest — it never quietly drops them, because an operator reading one
     * range would believe it was the only one.
     */
    public static function dates(RatePlan $plan): string
    {
        if ($plan->isDefault()) {
            return '—';
        }

        $ranges = $plan->season?->dateRanges;

        if ($ranges === null || $ranges->isEmpty()) {
            return __('pricing.rate_plan.table.no_dates');
        }

        $first = $ranges->first();
        $line = DateTimeFormatter::date($first->starts_on) . ' – ' . DateTimeFormatter::date($first->ends_on);
        $rest = $ranges->count() - 1;

        return $rest === 0
            ? $line
            : $line . ' ' . trans_choice('pricing.rate_plan.table.more_dates', $rest, ['count' => $rest]);
    }

    /** «30%», «200,00 €», or «Εξοφλείται». */
    public static function deposit(RatePlan $plan): string
    {
        return match ($plan->deposit_type) {
            DepositType::None => __('pricing.rate_plan.table.deposit_none'),
            DepositType::Percent => ($plan->deposit_percent ?? 0) . '%',
            DepositType::Fixed => MoneyFormatter::format($plan->deposit_fixed_cents ?? 0),
        };
    }

    /**
     * The trip's own line, under its name in the group heading: the boat, the
     * people, the length of the day, how it is sold.
     *
     * Everything an operator would otherwise open the trip to check, and all of
     * it already on the product — no join beyond the vessel.
     */
    public static function productMeta(?Product $product): string
    {
        if (! $product instanceof Product) {
            return '';
        }

        $parts = array_values(array_filter([
            $product->vessel?->name,
            trans_choice('pricing.rate_plan.table.up_to_pax', $product->max_pax, ['count' => $product->max_pax]),
            self::duration($product->duration_minutes),
            $product->mode->label(),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        return implode(' · ', $parts);
    }

    /**
     * «3 ώρες», «90 λεπτά», «1 ώρα 30 λεπτά».
     *
     * Whole hours read as hours, because that is how a trip is sold and «180
     * λεπτά» makes the reader do the division.
     */
    public static function duration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return trans_choice('pricing.rate_plan.table.minutes', $rest, ['count' => $rest]);
        }

        $line = trans_choice('pricing.rate_plan.table.hours', $hours, ['count' => $hours]);

        return $rest === 0
            ? $line
            : $line . ' ' . trans_choice('pricing.rate_plan.table.minutes', $rest, ['count' => $rest]);
    }

    private static function baseBand(?Product $product): ?AgeBand
    {
        return $product?->ageBands->firstWhere('is_base', true);
    }

    private static function vesselDetail(RatePlan $plan): string
    {
        $parts = [];

        if ($plan->included_pax !== null) {
            $parts[] = trans_choice('pricing.rate_plan.table.includes_pax', $plan->included_pax, ['count' => $plan->included_pax]);
        }

        if ($plan->extra_pax_price_cents !== null) {
            $parts[] = __('pricing.rate_plan.table.extra_pax', ['price' => MoneyFormatter::format($plan->extra_pax_price_cents)]);
        }

        if ($plan->extra_hour_price_cents !== null) {
            $parts[] = __('pricing.rate_plan.table.extra_hour', ['price' => MoneyFormatter::format($plan->extra_hour_price_cents)]);
        }

        return implode(' · ', $parts);
    }
}
