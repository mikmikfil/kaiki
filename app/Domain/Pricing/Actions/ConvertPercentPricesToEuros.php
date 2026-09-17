<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Support\PaxLineBuilder;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Support\Money\Cents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turn every «ποσοστό της βασικής» band into euros, once (product owner,
 * 2026-09-17).
 *
 * ## Exactly the fare a guest pays today
 *
 * For each rate plan, a percentage band with no price of its own gets one: the
 * plan's base price times the multiplier, through {@see Cents::applyBasisPoints()},
 * which is the call {@see PaxLineBuilder} prices a
 * booking with. Same input, same rounding, same cent. Then the band becomes
 * `fixed`, so nothing derives from the multiplier again.
 *
 * ## Bookings are not touched
 *
 * A booking carries its own `price_snapshot`, taken when it was priced. This
 * writes `rate_plan_prices` and `age_bands` and nothing else.
 *
 * ## All of a trip, or none of it
 *
 * A plan with no base price has nothing to take a percentage of. Converting the
 * trip's other plans and leaving that one would switch the band to `fixed` with
 * a plan it cannot price on. So such a trip is left exactly as it is and
 * reported, and the operator fixes it in the price table, which does the same
 * conversion on save.
 *
 * ## Safe to run twice
 *
 * A converted band is `fixed`, so a second run finds nothing to do.
 */
final class ConvertPercentPricesToEuros
{
    /**
     * @return array{
     *     planned: list<array{product: string, plan: string, band: string, basis_points: int, base_cents: int, cents: int}>,
     *     skipped: list<array{product: string, plan: string, reason: string}>,
     *     products: int
     * }
     */
    public function __invoke(bool $apply): array
    {
        $planned = [];
        $skipped = [];
        $converted = 0;

        $products = Product::query()
            ->withTrashed()
            ->where('mode', BookingMode::PerSeat->value)
            ->whereHas('ageBands', static fn ($query) => $query->where('pricing_mode', AgeBandPricing::Multiplier->value))
            ->get();

        foreach ($products as $product) {
            /** @var Collection<int, AgeBand> $bands */
            $bands = $product->ageBands()->get();
            $base = $bands->firstWhere('is_base', true);
            $percentBands = $bands->filter(static fn (AgeBand $band): bool => $band->pricing_mode === AgeBandPricing::Multiplier);

            /** @var Collection<int, RatePlan> $plans */
            $plans = $product->ratePlans()->with(['prices', 'season'])->get();

            $rows = [];
            $problems = [];

            foreach ($plans as $plan) {
                $prices = $plan->prices->pluck('price_cents', 'age_band_id');
                $baseCents = $base === null ? null : $prices->get($base->getKey());

                foreach ($percentBands as $band) {
                    if ($prices->has($band->getKey())) {
                        continue;
                    }

                    if ($baseCents === null) {
                        $problems[] = ['product' => (string) $product->title, 'plan' => self::planName($plan), 'reason' => 'no_base_price'];

                        continue 2;
                    }

                    $rows[] = [
                        'plan' => $plan,
                        'band' => $band,
                        'basis_points' => (int) ($band->price_multiplier_bp ?? 0),
                        'base_cents' => (int) $baseCents,
                        'cents' => Cents::applyBasisPoints((int) $baseCents, (int) ($band->price_multiplier_bp ?? 0)),
                    ];
                }
            }

            if ($problems !== []) {
                array_push($skipped, ...$problems);

                continue;
            }

            foreach ($rows as $row) {
                $planned[] = [
                    'product' => (string) $product->title,
                    'plan' => self::planName($row['plan']),
                    'band' => (string) $row['band']->label,
                    'basis_points' => $row['basis_points'],
                    'base_cents' => $row['base_cents'],
                    'cents' => $row['cents'],
                ];
            }

            $converted++;

            if (! $apply) {
                continue;
            }

            DB::transaction(static function () use ($rows, $percentBands): void {
                foreach ($rows as $row) {
                    RatePlanPrice::query()->create([
                        'tenant_id' => $row['plan']->tenant_id,
                        'rate_plan_id' => $row['plan']->getKey(),
                        'age_band_id' => $row['band']->getKey(),
                        'price_cents' => $row['cents'],
                    ]);
                }

                foreach ($percentBands as $band) {
                    $band->pricing_mode = AgeBandPricing::Fixed;
                    $band->price_multiplier_bp = null;
                    $band->save();
                }
            });
        }

        return ['planned' => $planned, 'skipped' => $skipped, 'products' => $converted];
    }

    private static function planName(RatePlan $plan): string
    {
        return $plan->season === null
            ? (string) trans('pricing.on_product.season.default')
            : (string) $plan->season->name;
    }
}
