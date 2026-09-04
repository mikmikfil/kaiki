<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save a rate plan and its per-band prices (spec CAT-10, CAT-5, PRC-23).
 *
 * Four rules, and the first is here because the database cannot hold it.
 *
 * ## Exactly one default plan per product, enforced in PHP
 *
 * `rate_plans_tenant_prod_season_uq` is `(tenant_id, product_id, season_id)`
 * and the default plan is the row with `season_id` **null**. Both MySQL and
 * SQLite treat NULLs as distinct in a unique index, so that constraint permits
 * two defaults and always will; a partial index would express it and is
 * unportable (ENV-12). §2.3 states the caveat and hands the rule to the
 * application, which is here.
 *
 * The failure it prevents is quiet: two default plans mean a product prices
 * differently depending on which row the resolver read first, and the operator
 * sees a figure they cannot explain.
 *
 * ## The plan must match the product's mode (CAT-5)
 *
 * A `per_vessel` plan needs `vessel_price_cents` and must carry **no** per-band
 * rows — a whole-boat charter has one price. A `per_seat` plan is the reverse.
 * A `quote` plan is permitted for internal reference and never produces a
 * guest-facing price.
 *
 * ## Every band that cannot derive a price must have one
 *
 * A `multiplier` band derives from the base band, so its row is optional. A
 * `fixed` band has nothing to derive from, so its row is required — and so is
 * the base band's, because it is what every multiplier is a multiple of.
 * Missing either is a passenger category with no price, which nothing notices
 * at read time.
 *
 * ## The deposit columns must agree with the deposit type
 *
 * `percent` needs a percentage in 1-100, `fixed` needs a positive amount, and
 * `none` needs neither — a stale value under `none` is a deposit that
 * reappears the day somebody switches the type back.
 */
final class SaveRatePlan
{
    /**
     * @param  array<string, mixed>  $attributes  already validated by the caller
     * @param  array<int, int>|null  $bandPrices  age band id => price in cents;
     *                                            null leaves existing prices alone
     *
     * @throws ValidationException
     */
    public function __invoke(RatePlan $plan, Product $product, array $attributes, ?array $bandPrices = null): RatePlan
    {
        $attributes['product_id'] = $product->getKey();

        $this->guardSingleDefault($plan, $product, $attributes);
        $this->guardDepositFields($attributes, $plan);
        $this->guardModeConsistency($product, $attributes, $bandPrices);
        $this->guardBandCoverage($product, $bandPrices);

        return DB::transaction(function () use ($plan, $attributes, $bandPrices): RatePlan {
            $plan->fill($attributes);

            // A new plan that was never sent a deposit type has none of the
            // three, and `match` on null throws rather than defaulting.
            $plan->deposit_type ??= DepositType::None;

            // Clear whichever deposit column this type does not use, so
            // switching the type back cannot resurrect an old figure.
            match ($plan->deposit_type) {
                DepositType::None => [$plan->deposit_percent = null, $plan->deposit_fixed_cents = null],
                DepositType::Percent => $plan->deposit_fixed_cents = null,
                DepositType::Fixed => $plan->deposit_percent = null,
            };

            $plan->save();

            if ($bandPrices !== null) {
                $plan->prices()->delete();

                foreach ($bandPrices as $ageBandId => $priceCents) {
                    RatePlanPrice::query()->create([
                        'rate_plan_id' => $plan->getKey(),
                        'age_band_id' => (int) $ageBandId,
                        'price_cents' => (int) $priceCents,
                    ]);
                }
            }

            return $plan->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardSingleDefault(RatePlan $plan, Product $product, array $attributes): void
    {
        $seasonId = $attributes['season_id'] ?? null;

        if ($seasonId !== null && $seasonId !== '') {
            return;
        }

        $existing = RatePlan::query()
            ->where('product_id', $product->getKey())
            ->defaultPlan()
            ->when($plan->exists, fn ($query) => $query->whereKeyNot($plan->getKey()))
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'season_id' => [trans('pricing.rate_plan.validation.duplicate_default')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardDepositFields(array $attributes, RatePlan $plan): void
    {
        $type = $attributes['deposit_type'] ?? $plan->deposit_type ?? DepositType::None;
        $type = $type instanceof DepositType ? $type : DepositType::tryFrom((string) $type);

        if ($type === null) {
            throw ValidationException::withMessages([
                'deposit_type' => [trans('pricing.rate_plan.validation.deposit_type')],
            ]);
        }

        $percent = $this->value($attributes, 'deposit_percent');
        $fixed = $this->value($attributes, 'deposit_fixed_cents');

        $errors = [];

        if ($type === DepositType::Percent && ($percent === null || (int) $percent < 1 || (int) $percent > 100)) {
            $errors['deposit_percent'] = trans('pricing.rate_plan.validation.deposit_percent');
        }

        if ($type === DepositType::Fixed && ($fixed === null || (int) $fixed < 1)) {
            $errors['deposit_fixed_cents'] = trans('pricing.rate_plan.validation.deposit_fixed');
        }

        // Refused rather than silently cleared: an operator who typed a
        // percentage and chose "no deposit" meant one of the two, and only they
        // know which.
        if ($type === DepositType::None && ($percent !== null || $fixed !== null)) {
            $errors['deposit_type'] = trans('pricing.rate_plan.validation.deposit_none_has_value');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $m): array => [$m], $errors));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>|null  $bandPrices
     *
     * @throws ValidationException
     */
    private function guardModeConsistency(Product $product, array $attributes, ?array $bandPrices): void
    {
        $errors = [];
        $vesselPrice = $this->value($attributes, 'vessel_price_cents');

        if ($product->mode === BookingMode::PerVessel) {
            if ($vesselPrice === null || (int) $vesselPrice < 1) {
                $errors['vessel_price_cents'] = trans('pricing.rate_plan.validation.vessel_price_required');
            }

            if ($bandPrices !== null && $bandPrices !== []) {
                $errors['prices'] = trans('pricing.rate_plan.validation.no_band_prices_per_vessel');
            }
        }

        if ($product->mode === BookingMode::PerSeat && $vesselPrice !== null) {
            $errors['vessel_price_cents'] = trans('pricing.rate_plan.validation.no_vessel_price_per_seat');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $m): array => [$m], $errors));
        }
    }

    /**
     * @param  array<int, int>|null  $bandPrices
     *
     * @throws ValidationException
     */
    private function guardBandCoverage(Product $product, ?array $bandPrices): void
    {
        if ($bandPrices === null || $product->mode !== BookingMode::PerSeat) {
            return;
        }

        $missing = $product->ageBands()
            ->get()
            ->filter(fn (AgeBand $band): bool => ! array_key_exists($band->getKey(), $bandPrices))
            // A `multiplier` band derives from the base and may omit its row;
            // a `fixed` band and the base band itself cannot.
            ->filter(fn (AgeBand $band): bool => $band->is_base
                || $band->pricing_mode === AgeBandPricing::Fixed)
            ->map(fn (AgeBand $band): string => $band->label)
            ->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'prices' => [trans('pricing.rate_plan.validation.missing_band_prices', [
                    'bands' => $missing->implode(', '),
                ])],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function value(array $attributes, string $key): int|string|null
    {
        $value = $attributes[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }
}
