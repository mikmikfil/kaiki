<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Domain\Pricing\Actions\SavePriceTable;
use App\Enums\AgeBandPricing;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\SeasonDateRange;
use Illuminate\Support\Collection;

/**
 * A trip's prices as one table in euros: age bands down, periods across
 * (product owner, 2026-09-17).
 *
 * ## Read-only, and built from what the engine already reads
 *
 * A column is a rate plan (the default one, «Όλες τις άλλες μέρες», and one per
 * period); a row is an age band; a cell is what one person in that band pays
 * on that plan. The cell comes from {@see PaxLineBuilder::unitPriceCents()},
 * the same call a booking prices through, so the table cannot show a figure
 * the checkout would not charge.
 *
 * A band still priced as a percentage of the base therefore shows its derived
 * euros, marked `derived`. Saving the table writes that figure as the band's
 * own price, which is the whole conversion for that trip, done by the operator
 * pressing save ({@see SavePriceTable}).
 *
 * ## A trip with no plan yet still gets a column
 *
 * `NEW_DEFAULT`, the default plan that does not exist. An empty table with
 * nowhere to type is how the old screen started, and «make a plan first» is the
 * detour the table exists to remove.
 */
final class PriceTable
{
    /** The column key for a default plan the trip does not have yet. */
    public const NEW_DEFAULT = 'new';

    /**
     * @param  list<array{key: string, plan_id: int|null, label: string, dates: string, active: bool}>  $columns
     * @param  list<array{key: string, band_id: int, label: string, ages: string, is_base: bool, takes_seat: bool}>  $rows
     * @param  array<string, array<string, int|null>>  $cents  row key => column key => cents
     * @param  array<string, array<string, bool>>  $derived  row key => column key => still a percentage
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $cents,
        public readonly array $derived,
    ) {}

    public static function for(Product $product): self
    {
        /** @var Collection<int, AgeBand> $bands */
        $bands = $product->ageBands()->get();

        /** @var Collection<int, RatePlan> $plans */
        $plans = $product->ratePlans()
            ->with(['prices', 'season.dateRanges'])
            ->get()
            // The default plan first: it is the baseline the periods differ from.
            ->sortBy(static fn (RatePlan $plan): string => $plan->season_id === null
                ? '0'
                : '1' . self::firstDate($plan))
            ->values();

        $columns = $plans->isEmpty()
            ? [[
                'key' => self::NEW_DEFAULT,
                'plan_id' => null,
                'label' => __('pricing.on_product.season.default'),
                'dates' => __('pricing.price_table.no_period'),
                'active' => true,
            ]]
            : $plans->map(static fn (RatePlan $plan): array => [
                'key' => self::columnKey($plan),
                'plan_id' => (int) $plan->getKey(),
                'label' => $plan->season === null
                    ? __('pricing.on_product.season.default')
                    : (string) $plan->season->name,
                'dates' => self::dates($plan),
                'active' => (bool) $plan->is_active,
            ])->all();

        $rows = $bands->map(static fn (AgeBand $band): array => [
            'key' => self::rowKey($band),
            'band_id' => (int) $band->getKey(),
            'label' => (string) $band->label,
            'ages' => $band->max_age === null
                ? __('pricing.price_table.ages_from', ['min' => $band->min_age])
                : __('pricing.price_table.ages_between', ['min' => $band->min_age, 'max' => $band->max_age]),
            'is_base' => (bool) $band->is_base,
            'takes_seat' => (bool) $band->counts_toward_capacity,
        ])->values()->all();

        $cents = [];
        $derived = [];

        foreach ($bands as $band) {
            foreach ($plans as $plan) {
                /** @var Collection<int, int> $prices */
                $prices = $plan->prices->pluck('price_cents', 'age_band_id');
                $own = $prices->get($band->getKey());

                $cents[self::rowKey($band)][self::columnKey($plan)] = PaxLineBuilder::unitPriceCents(
                    $band,
                    $prices,
                    PaxLineBuilder::basePriceCents($bands, $prices),
                );
                $derived[self::rowKey($band)][self::columnKey($plan)] = $own === null
                    && $band->pricing_mode === AgeBandPricing::Multiplier;
            }

            if ($plans->isEmpty()) {
                $cents[self::rowKey($band)][self::NEW_DEFAULT] = null;
                $derived[self::rowKey($band)][self::NEW_DEFAULT] = false;
            }
        }

        return new self($columns, $rows, $cents, $derived);
    }

    /** `b12`: a string, so Livewire and the browser never reindex the array. */
    public static function rowKey(AgeBand $band): string
    {
        return 'b' . $band->getKey();
    }

    public static function columnKey(RatePlan $plan): string
    {
        return 'p' . $plan->getKey();
    }

    /** The base band's row, which every quick-fill button works from. */
    public function baseRowKey(): ?string
    {
        foreach ($this->rows as $row) {
            if ($row['is_base']) {
                return $row['key'];
            }
        }

        return null;
    }

    private static function firstDate(RatePlan $plan): string
    {
        $first = $plan->season?->dateRanges->sortBy('starts_on')->first();

        return $first === null ? '9999' : $first->starts_on->toDateString();
    }

    /** «1 Ιουλίου – 31 Αυγούστου», every range of the period. */
    private static function dates(RatePlan $plan): string
    {
        if ($plan->season === null) {
            return __('pricing.price_table.no_period');
        }

        return $plan->season->dateRanges
            ->sortBy('starts_on')
            ->map(static fn (SeasonDateRange $range): string => $range->starts_on->translatedFormat('j F')
                . ' – ' . $range->ends_on->translatedFormat('j F'))
            ->implode(' · ');
    }
}
