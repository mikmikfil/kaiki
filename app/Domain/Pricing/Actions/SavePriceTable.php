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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save a trip's price table: every band, every period, in euros
 * (product owner, 2026-09-17).
 *
 * ## Every cell, or nothing
 *
 * A blank cell used to mean either «worked out from the adult» or «forgotten»,
 * and nothing could tell them apart. Here there is no percentage to fall back
 * on, so a blank is always missing and the save is refused, naming the band and
 * the period. All of the plans are written in one transaction, so a refusal
 * leaves no plan half-priced.
 *
 * ## Each plan still goes through {@see SaveRatePlan}
 *
 * Its four rules (one default per trip, the mode, band coverage, deposit
 * columns) are not repeated here. The plan's own settings are passed back
 * unchanged, because this table edits prices and nothing else.
 *
 * ## Percent bands become euro bands on the way out
 *
 * Once every plan carries an explicit price for a band, its multiplier is
 * dead weight and a trap: a later plan without a row would silently derive from
 * it again. So a `multiplier` band is switched to `fixed` in the same
 * transaction. The enum keeps `multiplier` (imports and the API still send it,
 * and old price snapshots record it); the panel simply stops offering it.
 */
final class SavePriceTable
{
    public function __construct(private readonly SaveRatePlan $saveRatePlan) {}

    /**
     * @param  array<string, array<string, int|null>>  $cents  row key (`b12`) => column key (`p5`, or `new`) => cents
     *
     * @throws ValidationException
     */
    public function __invoke(Product $product, array $cents): void
    {
        if ($product->mode !== BookingMode::PerSeat) {
            throw ValidationException::withMessages([
                'prices' => [trans('pricing.price_table.validation.per_seat_only')],
            ]);
        }

        /** @var Collection<int, AgeBand> $bands */
        $bands = $product->ageBands()->get();

        /** @var Collection<int, RatePlan> $plans */
        $plans = $product->ratePlans()->with('season')->get();

        $columns = $plans->isEmpty()
            ? [PriceTable::NEW_DEFAULT => null]
            : $plans->mapWithKeys(static fn (RatePlan $plan): array => [PriceTable::columnKey($plan) => $plan])->all();

        $this->guardComplete($bands, $columns, $cents);

        DB::transaction(function () use ($product, $bands, $columns, $cents): void {
            foreach ($columns as $key => $plan) {
                $bandPrices = [];

                foreach ($bands as $band) {
                    $bandPrices[(int) $band->getKey()] = (int) $cents[PriceTable::rowKey($band)][$key];
                }

                $this->saveRatePlan->__invoke(
                    $plan ?? new RatePlan,
                    $product,
                    $plan === null ? self::newDefaultPlan() : self::unchangedSettings($plan),
                    $bandPrices,
                );
            }

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

    /**
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, RatePlan|null>  $columns
     * @param  array<string, array<string, int|null>>  $cents
     *
     * @throws ValidationException
     */
    private function guardComplete(Collection $bands, array $columns, array $cents): void
    {
        $missing = [];

        foreach ($bands as $band) {
            foreach ($columns as $key => $plan) {
                $value = $cents[PriceTable::rowKey($band)][$key] ?? null;

                if ($value === null || $value < 0) {
                    $missing[] = trans('pricing.price_table.validation.cell', [
                        'band' => (string) $band->label,
                        'period' => $plan?->season === null
                            ? trans('pricing.on_product.season.default')
                            : (string) $plan->season->name,
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

    /** @return array<string, mixed> */
    private static function unchangedSettings(RatePlan $plan): array
    {
        return [
            'season_id' => $plan->season_id,
            'deposit_type' => $plan->deposit_type,
            'deposit_percent' => $plan->deposit_percent,
            'deposit_fixed_cents' => $plan->deposit_fixed_cents,
        ];
    }

    /** @return array<string, mixed> */
    private static function newDefaultPlan(): array
    {
        return [
            'season_id' => null,
            'deposit_type' => DepositType::None->value,
            'min_lead_time_hours' => 0,
            'is_active' => true,
        ];
    }
}
