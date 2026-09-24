<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Domain\Pricing\Actions\SavePriceTable;
use App\Enums\AgeBandPricing;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\SeasonDateRange;
use Illuminate\Support\Collection;

/**
 * A trip's prices as one table in euros: groups down, periods across
 * (product owner, 2026-09-17; periods ticked since 2026-09-24).
 *
 * ## Read-only, and built from what the engine already reads
 *
 * A column is a rate plan — the year-round one, «Όλο τον χρόνο», and one per
 * period; a row is a passenger group; a cell is what one person in that group
 * pays on that plan. The cell comes from {@see PaxLineBuilder::unitPriceCents()},
 * the same call a booking prices through, so the table cannot show a figure
 * the checkout would not charge.
 *
 * A band still priced as a percentage of the base therefore shows its derived
 * euros, marked `derived`. Saving the table writes that figure as the band's
 * own price, which is the whole conversion for that trip, done by the operator
 * pressing save ({@see SavePriceTable}).
 *
 * ## The periods are ticked, not built (2026-09-24)
 *
 * Mike's flow: make the groups, tick the periods that have a different price,
 * fill the table. So the columns are the year-round plan plus **the ticked
 * periods** — a ticked period with no plan yet is a column all the same
 * (`s{season id}`), and saving creates its plan. The word «τιμοκατάλογος» is
 * gone from the trip; the rate plan is still what the engine prices from.
 *
 * With no ticks passed, the ticked periods are the ones with an active plan,
 * which is what the trip sells today.
 *
 * ## The year-round column is always there
 *
 * `NEW_DEFAULT` when the trip has no year-round plan yet. A date outside every
 * period is priced from it, so there is no way to untick it: a trip with a gap
 * in its prices is a trip that cannot be booked on those days.
 */
final class PriceTable
{
    /** The column key for a year-round plan the trip does not have yet. */
    public const NEW_DEFAULT = 'new';

    /**
     * @param  list<array{key: string, plan_id: int|null, season_id: int|null, label: string, dates: string, active: bool, follows: bool}>  $columns
     * @param  list<array{key: string, band_id: int, label: string, ages: string, is_base: bool, takes_seat: bool}>  $rows
     * @param  array<string, array<string, int|null>>  $cents  row key => column key => cents
     * @param  array<string, array<string, bool>>  $derived  row key => column key => still a percentage
     * @param  list<array{id: int, label: string, dates: string, ticked: bool}>  $periods  every period the operator has, for the ticks
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $cents,
        public readonly array $derived,
        public readonly array $periods = [],
    ) {}

    /** @param  list<int>|null  $seasonIds  the ticked periods; null means "as saved" */
    public static function for(Product $product, ?array $seasonIds = null): self
    {
        /** @var Collection<int, AgeBand> $bands */
        $bands = $product->ageBands()->get();

        $plans = self::plans($product);
        $seasons = self::seasons();
        $ticked = $seasonIds ?? self::tickedAsSaved($plans);

        $columns = [];

        foreach (self::columnPlan($plans, $seasons, $ticked) as $key => [$plan, $season]) {
            $columns[] = [
                'key' => $key,
                'plan_id' => $plan instanceof RatePlan ? (int) $plan->getKey() : null,
                'season_id' => $season instanceof Season ? (int) $season->getKey() : null,
                'label' => $season instanceof Season
                    ? (string) $season->name
                    : __('pricing.on_product.season.default'),
                'dates' => $season instanceof Season
                    ? self::seasonDates($season)
                    : __('pricing.price_table.no_period'),
                'active' => true,
                'follows' => ! $plan instanceof RatePlan || $plan->season_id === null || (bool) $plan->follows_trip_terms,
            ];
        }

        $rows = $bands->map(static fn (AgeBand $band): array => [
            'key' => self::rowKey($band),
            'band_id' => (int) $band->getKey(),
            'label' => (string) $band->label,
            'ages' => match (true) {
                $band->isByStatus() => __('catalog.product.form.bands.summary.by_status'),
                $band->max_age === null => __('pricing.price_table.ages_from', ['min' => $band->min_age]),
                default => __('pricing.price_table.ages_between', ['min' => $band->min_age, 'max' => $band->max_age]),
            },
            'is_base' => (bool) $band->is_base,
            'takes_seat' => (bool) $band->counts_toward_capacity,
        ])->values()->all();

        $cents = [];
        $derived = [];

        foreach ($bands as $band) {
            foreach ($columns as $column) {
                $plan = $column['plan_id'] === null ? null : $plans->firstWhere('id', $column['plan_id']);

                if (! $plan instanceof RatePlan) {
                    $cents[self::rowKey($band)][$column['key']] = null;
                    $derived[self::rowKey($band)][$column['key']] = false;

                    continue;
                }

                /** @var Collection<int, int> $prices */
                $prices = $plan->prices->pluck('price_cents', 'age_band_id');
                $own = $prices->get($band->getKey());

                $cents[self::rowKey($band)][$column['key']] = PaxLineBuilder::unitPriceCents(
                    $band,
                    $prices,
                    PaxLineBuilder::basePriceCents($bands, $prices),
                );
                $derived[self::rowKey($band)][$column['key']] = $own === null
                    && $band->pricing_mode === AgeBandPricing::Multiplier;
            }
        }

        $periods = $seasons
            ->map(static fn (Season $season): array => [
                'id' => (int) $season->getKey(),
                'label' => (string) $season->name,
                'dates' => self::seasonDates($season),
                'ticked' => in_array((int) $season->getKey(), $ticked, true),
            ])
            ->values()
            ->all();

        return new self($columns, $rows, $cents, $derived, $periods);
    }

    /**
     * Column key => [its plan or null, its period or null], year-round first,
     * then the ticked periods by their first date. Shared with
     * {@see SavePriceTable}, so the page and the save agree on what a key means.
     *
     * @param  Collection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     * @param  list<int>  $ticked
     * @return array<string, array{0: RatePlan|null, 1: Season|null}>
     */
    public static function columnPlan(Collection $plans, Collection $seasons, array $ticked): array
    {
        $default = $plans->first(static fn (RatePlan $plan): bool => $plan->season_id === null);

        $map = [
            $default instanceof RatePlan ? self::columnKey($default) : self::NEW_DEFAULT => [$default, null],
        ];

        foreach ($seasons as $season) {
            if (! in_array((int) $season->getKey(), $ticked, true)) {
                continue;
            }

            $plan = $plans->first(static fn (RatePlan $plan): bool => (int) $plan->season_id === (int) $season->getKey());

            $map[$plan instanceof RatePlan ? self::columnKey($plan) : self::seasonKey($season)] = [$plan, $season];
        }

        return $map;
    }

    /** @return Collection<int, RatePlan> */
    public static function plans(Product $product): Collection
    {
        return $product->ratePlans()->with(['prices', 'season.dateRanges'])->get();
    }

    /**
     * Every period the operator has, by first date. Periods are shared by all
     * their trips («Οι περίοδοι είναι κοινές για όλες τις εκδρομές σας»).
     *
     * @return Collection<int, Season>
     */
    public static function seasons(): Collection
    {
        return Season::query()
            ->with('dateRanges')
            ->get()
            ->sortBy(static fn (Season $season): string => (string) ($season->dateRanges->min('starts_on')?->toDateString() ?? '9999'))
            ->values();
    }

    /**
     * @param  Collection<int, RatePlan>  $plans
     * @return list<int>
     */
    public static function tickedAsSaved(Collection $plans): array
    {
        return $plans
            ->filter(static fn (RatePlan $plan): bool => $plan->season_id !== null && (bool) $plan->is_active)
            ->map(static fn (RatePlan $plan): int => (int) $plan->season_id)
            ->values()
            ->all();
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

    /** A ticked period the trip has no plan for yet: saving creates it. */
    public static function seasonKey(Season $season): string
    {
        return 's' . $season->getKey();
    }

    /** The base band's row. */
    public function baseRowKey(): ?string
    {
        foreach ($this->rows as $row) {
            if ($row['is_base']) {
                return $row['key'];
            }
        }

        return null;
    }

    /** «1 Ιουλίου – 31 Αυγούστου», every range of the period. */
    private static function seasonDates(Season $season): string
    {
        return $season->dateRanges
            ->sortBy('starts_on')
            ->map(static fn (SeasonDateRange $range): string => $range->starts_on->translatedFormat('j F')
                . ' – ' . $range->ends_on->translatedFormat('j F'))
            ->implode(' · ');
    }
}
