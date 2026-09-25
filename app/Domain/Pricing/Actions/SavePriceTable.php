<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Support\PriceTable;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save a trip's price table: every group, every ticked period, in euros
 * (product owner, 2026-09-17; periods ticked and terms set once, 2026-09-24).
 *
 * ## Every cell, or nothing
 *
 * A blank cell is always missing and the save is refused, naming the group and
 * the period. All of the plans are written in one transaction, so a refusal
 * leaves no plan half-priced.
 *
 * ## A ticked period is a plan, an unticked one is switched off
 *
 * Ticking a period with no plan creates one, priced from its column. Unticking
 * one switches its plan off rather than deleting it: its prices come back when
 * it is ticked again, and a booking already taken on it keeps its snapshot
 * either way.
 *
 * ## Terms: once for the trip, and a period may differ
 *
 * `$terms` — deposit, balance, booking deadlines — are written to the
 * year-round plan and to every period that `follows_trip_terms`. A period set
 * to differ keeps its own ({@see SavePeriodTerms}). A new period follows.
 *
 * ## Each plan still goes through {@see SaveRatePlan}
 *
 * Its rules (one year-round plan per trip, the mode, band coverage, deposit
 * columns) are not repeated here.
 *
 * ## Percent bands become euro bands on the way out
 *
 * Once every plan carries an explicit price for a band, its multiplier is dead
 * weight and a trap, so a `multiplier` band is switched to `fixed` in the same
 * transaction.
 */
final class SavePriceTable
{
    /** The columns a plan's terms are made of. */
    public const TERMS = [
        'deposit_type',
        'deposit_percent',
        'deposit_fixed_cents',
        'balance_due_days_before_departure',
        'min_lead_time_hours',
        'max_advance_days',
    ];

    public function __construct(private readonly SaveRatePlan $saveRatePlan) {}

    /**
     * @param  array<string, array<string, int|null>>  $cents  row key (`b12`) => column key (`p5`, `s3`, `new`) => cents
     * @param  list<int>|null  $seasonIds  the ticked periods; null keeps them as saved
     * @param  array<string, mixed>|null  $terms  the trip's terms; null leaves every plan's as it is
     *
     * @throws ValidationException
     */
    public function __invoke(Product $product, array $cents, ?array $seasonIds = null, ?array $terms = null): void
    {
        if ($product->mode !== BookingMode::PerSeat) {
            throw ValidationException::withMessages([
                'prices' => [trans('pricing.price_table.validation.per_seat_only')],
            ]);
        }

        /** @var Collection<int, AgeBand> $bands */
        $bands = $product->ageBands()->get();

        $plans = PriceTable::plans($product);
        $seasons = PriceTable::seasons();
        $ticked = $seasonIds === null
            ? PriceTable::tickedAsSaved($plans)
            : array_values(array_intersect(
                array_map('intval', $seasonIds),
                $seasons->map(static fn (Season $season): int => (int) $season->getKey())->all(),
            ));

        $columns = PriceTable::columnPlan($plans, $seasons, $ticked);

        $this->guardComplete($bands, $columns, $cents);

        $terms = $terms === null ? null : array_intersect_key($terms, array_flip(self::TERMS));

        DB::transaction(function () use ($product, $bands, $plans, $columns, $cents, $ticked, $terms): void {
            $default = $plans->first(static fn (RatePlan $plan): bool => $plan->season_id === null);
            $tripTerms = $terms ?? ($default instanceof RatePlan ? self::termsOf($default) : self::noTerms());

            foreach ($columns as $key => [$plan, $season]) {
                $bandPrices = [];

                foreach ($bands as $band) {
                    $bandPrices[(int) $band->getKey()] = (int) $cents[PriceTable::rowKey($band)][$key];
                }

                $isDefault = ! $season instanceof Season;
                $follows = $isDefault || ! $plan instanceof RatePlan || (bool) $plan->follows_trip_terms;

                $attributes = [
                    'season_id' => $season?->getKey(),
                    'is_active' => true,
                ] + ($follows ? $tripTerms : self::termsOf($plan));

                $this->saveRatePlan->__invoke($plan ?? new RatePlan, $product, $attributes, $bandPrices);
            }

            // Periods no longer ticked: switched off, prices kept.
            $plans
                ->filter(static fn (RatePlan $plan): bool => $plan->season_id !== null
                    && $plan->is_active
                    && ! in_array((int) $plan->season_id, $ticked, true))
                ->each(static function (RatePlan $plan): void {
                    $plan->is_active = false;
                    $plan->save();
                });

            AgeBand::query()
                ->where('product_id', $product->getKey())
                ->where('pricing_mode', AgeBandPricing::Multiplier->value)
                ->get()
                ->each(static function (AgeBand $band): void {
                    $band->pricing_mode = AgeBandPricing::Fixed;
                    $band->price_multiplier_bp = null;
                    $band->save();
                });
        });
    }

    /** @return array<string, mixed> */
    public static function termsOf(RatePlan $plan): array
    {
        $terms = [];

        foreach (self::TERMS as $column) {
            $value = $plan->getAttribute($column);
            $terms[$column] = $value instanceof DepositType ? $value->value : $value;
        }

        return $terms;
    }

    /** @return array<string, mixed> */
    public static function noTerms(): array
    {
        return [
            'deposit_type' => DepositType::None->value,
            'deposit_percent' => null,
            'deposit_fixed_cents' => null,
            'balance_due_days_before_departure' => null,
            'min_lead_time_hours' => 0,
            'max_advance_days' => null,
        ];
    }

    /**
     * Refused when any cell is blank or negative, naming each one.
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, array{0: RatePlan|null, 1: Season|null}>  $columns
     * @param  array<string, array<string, int|null>>  $cents
     */
    private function guardComplete(Collection $bands, array $columns, array $cents): void
    {
        $missing = [];

        foreach ($bands as $band) {
            foreach ($columns as $key => [, $season]) {
                $value = $cents[PriceTable::rowKey($band)][$key] ?? null;

                if ($value === null || $value < 0) {
                    $missing[] = trans('pricing.price_table.validation.cell', [
                        'band' => (string) $band->label,
                        'period' => $season === null
                            ? trans('pricing.on_product.season.default')
                            : (string) $season->name,
                    ]);
                }
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'prices' => [trans('pricing.price_table.validation.missing', ['cells' => implode(', ', $missing)])],
            ]);
        }
    }
}
