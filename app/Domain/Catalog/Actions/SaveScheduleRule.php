<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Availability\Support\WeekdayMask;
use App\Enums\BookingMode;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save a schedule rule, and refuse the three ways it can be made useless
 * (spec CAT-14, AVL-52, AVL-55).
 *
 * ## A rule on a whole-boat product generates nothing
 *
 * CAT-14: schedule rules produce `departures`, and departures are the
 * `per_seat` sellable instance. A charter is booked as a window against the
 * boat's calendar, not as a seat on a scheduled sailing. A rule saved on one
 * would sit in the panel looking configured and produce nothing forever, which
 * is worse than a refusal because nobody goes looking.
 *
 * ## An empty mask is a rule that never fires
 *
 * `weekday_mask = 0` passes every column constraint and generates no departure
 * on any day. An operator who cleared all seven boxes meant to pause the rule,
 * and `is_active` is how you pause a rule — so this is refused and named.
 *
 * ## An inverted window is a typo
 *
 * `valid_until` before `valid_from` is always a mistyped year. Refused rather
 * than normalised: silently swapping them would generate a season of departures
 * the operator did not ask for.
 *
 * The vessel override is checked against the product's own boat only to the
 * extent that it must exist — which boat is a legitimate operator choice, and
 * capacity is capped at read time by `ScheduleRuleCapacityResolver` rather than
 * refused here, because a boat's certificate can change after the rule is
 * written.
 */
final class SaveScheduleRule
{
    /**
     * @param  array<string, mixed>  $attributes  already validated by the caller
     *
     * @throws ValidationException
     */
    public function __invoke(ScheduleRule $rule, Product $product, array $attributes): ScheduleRule
    {
        $attributes['product_id'] = $product->getKey();

        $this->guardProductMode($product);
        $this->guardWeekdayMask($attributes, $rule);
        $this->guardValidityWindow($attributes, $rule);
        $this->guardVessel($attributes);

        return DB::transaction(function () use ($rule, $attributes): ScheduleRule {
            $rule->fill($attributes);
            $rule->save();

            return $rule->refresh();
        });
    }

    /** @throws ValidationException */
    private function guardProductMode(Product $product): void
    {
        if ($product->mode === BookingMode::PerSeat) {
            return;
        }

        throw ValidationException::withMessages([
            'product_id' => [trans('availability.schedule_rule.validation.not_per_seat', [
                'mode' => $product->mode->label(),
            ])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardWeekdayMask(array $attributes, ScheduleRule $rule): void
    {
        $mask = (int) ($attributes['weekday_mask'] ?? $rule->weekday_mask ?? 0);

        if ($mask > 0 && $mask <= WeekdayMask::DAILY) {
            return;
        }

        throw ValidationException::withMessages([
            'weekday_mask' => [trans('availability.schedule_rule.validation.empty_mask')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardValidityWindow(array $attributes, ScheduleRule $rule): void
    {
        $from = $this->date($attributes['valid_from'] ?? $rule->valid_from);
        $until = $this->date($attributes['valid_until'] ?? $rule->valid_until);

        if ($from === null || $until === null) {
            return;
        }

        // Inclusive on both ends, so the same day is a valid one-day window.
        if ($until->greaterThanOrEqualTo($from)) {
            return;
        }

        throw ValidationException::withMessages([
            'valid_until' => [trans('availability.schedule_rule.validation.inverted_window')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardVessel(array $attributes): void
    {
        $vesselId = $attributes['vessel_id'] ?? null;

        if ($vesselId === null || $vesselId === '') {
            return;
        }

        // Scoped by the global tenant scope, so this also refuses another
        // operator's boat — quietly, and without saying it exists.
        if (Vessel::query()->whereKey((int) $vesselId)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'vessel_id' => [trans('availability.schedule_rule.validation.unknown_vessel')],
        ]);
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value->copy()->startOfDay() : Carbon::parse((string) $value)->startOfDay();
    }
}
