<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Enums\ProductStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Vessel;
use App\Support\Tenancy;

/**
 * What an operator with no bookings yet should do next (spec OPS-1).
 *
 * The dashboard's figures are the right screen for an operator in July and the
 * wrong one for an operator on their first afternoon: six zeros and a link to an
 * empty list is a product that looks broken on the day somebody decides whether
 * to keep paying for it.
 *
 * So when there is nothing to count, the dashboard says what to do instead —
 * four steps, in the order the catalogue needs them, each one answered from the
 * database rather than from a checkbox somebody has to remember to tick.
 *
 * ## Why the chain is boat → trip → published → departure
 *
 * It is the order the availability engine requires, and each step is genuinely
 * blocked by the one before it: a trip needs a boat to sail on, a departure
 * needs a trip to be a departure *of*, and a trip nobody can see is a trip
 * nobody can book. An operator who does these in a different order gets stuck,
 * and the place they get stuck does not say why.
 *
 * `ProductPublishChecklist` covers the inside of one product. This is the step
 * before that: whether they have one at all.
 */
final class FirstSteps
{
    public const VESSEL = 'vessel';

    public const PRODUCT = 'product';

    public const PUBLISHED = 'published';

    public const DEPARTURE = 'departure';

    /**
     * Is this an operator who has not started, rather than one having a quiet
     * week?
     *
     * A quiet week is the dangerous confusion. An operator in November with no
     * departures scheduled and a season's bookings behind them must not be shown
     * a getting-started panel — so this asks whether they have *ever* had a
     * booking, not whether they have one now.
     */
    public static function applies(): bool
    {
        // No tenant is "not applicable", never an exception.
        //
        // Every dashboard widget's `canView()` runs through here and `bookings`
        // is tenant-owned, so without this a request that reaches a widget
        // before a tenant is resolved raises `TenantContextMissingException`
        // instead of rendering — and the operator gets a stack trace naming a
        // model they never asked about.
        //
        // False is the honest answer as well as the safe one: "has this
        // operator started trading" has no meaning when there is no operator.
        if (! Tenancy::check()) {
            return false;
        }

        return ! Booking::query()->exists();
    }

    /**
     * The four steps and whether each is done, in order.
     *
     * @return array<string, bool>
     */
    public static function state(): array
    {
        return [
            self::VESSEL => Vessel::query()->exists(),
            self::PRODUCT => Product::query()->exists(),
            self::PUBLISHED => Product::query()->where('status', ProductStatus::Active->value)->exists(),
            self::DEPARTURE => Departure::query()->exists(),
        ];
    }

    /**
     * The first step not yet done, or null when they are all done.
     *
     * One step at a time. A checklist of four open items is a decision about
     * where to start, and the whole value of this panel is that there is nothing
     * to decide.
     */
    public static function next(): ?string
    {
        foreach (self::state() as $step => $done) {
            if (! $done) {
                return $step;
            }
        }

        return null;
    }
}
