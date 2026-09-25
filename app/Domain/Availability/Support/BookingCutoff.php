<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Actions\CheckVesselAvailability;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Pricing\Support\RatePlanResolver;
use App\Enums\AvailabilityRejection;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Is it too late, or too early, to book a sailing that starts at this instant
 * (spec AVL-19, AVL-20)?
 *
 * ## One rule, four callers
 *
 * `GET /availability` for shared trips ({@see CheckSeatAvailability}),
 * `GET /availability` for charters ({@see CheckVesselAvailability}),
 * `POST /price-quote` and `POST /bookings`. Until 25 September 2026 only the
 * first of them asked: a whole-boat charter offered every date of the month,
 * yesterday included, and the guest who chose one was refused by the quote for
 * a different reason («no price table») — the calendar and the checkout
 * disagreeing in front of a guest. Every caller now asks here, so the two can
 * only change together.
 *
 * ## Hours for the lead time, calendar days for the advance window
 *
 * AVL-19's lead time is **absolute** hours from now: an hour is an hour whatever
 * the clocks did last night. AVL-20's advance window is **calendar** days in the
 * tenant's timezone, because «ninety days ahead» is a date an operator can point
 * at on a calendar. No plan resolves for the date: the lead time is zero, so a
 * sailing that has already started is still refused.
 */
final class BookingCutoff
{
    /**
     * The refusal for a sailing starting at `$startsAtUtc` on `$localDate`, or
     * null when it may be booked.
     */
    public static function check(?RatePlan $plan, Carbon $startsAtUtc, Carbon|string $localDate, string $timezone): ?AvailabilityRejection
    {
        $leadHours = $plan === null ? 0 : max(0, $plan->min_lead_time_hours);

        if ($startsAtUtc->lessThan(Carbon::now()->addHours($leadHours))) {
            return AvailabilityRejection::LeadTimeTooShort;
        }

        $maxAdvanceDays = $plan?->max_advance_days;

        if ($maxAdvanceDays !== null) {
            $limit = Carbon::parse(LocalDay::today($timezone)->localDate)->addDays($maxAdvanceDays);
            $date = Carbon::parse($localDate instanceof Carbon ? $localDate->toDateString() : $localDate);

            if ($date->greaterThan($limit)) {
                return AvailabilityRejection::TooFarAhead;
            }
        }

        return null;
    }

    /**
     * The same, resolving the plan from preloaded plans and seasons.
     *
     * @param  Collection<int, RatePlan>  $plans  active plans for one product
     * @param  Collection<int, Season>  $seasons
     */
    public static function checkAgainst(Collection $plans, Collection $seasons, Carbon $startsAtUtc, Carbon|string $localDate, string $timezone): ?AvailabilityRejection
    {
        $date = Carbon::parse($localDate instanceof Carbon ? $localDate->toDateString() : $localDate);

        return self::check(RatePlanResolver::resolve($plans, $seasons, $date)->plan, $startsAtUtc, $date, $timezone);
    }

    /**
     * The same for what a guest posts to `POST /price-quote` or
     * `POST /bookings`: a departure for a shared trip, a date and a start for a
     * charter. Loads what it needs, since those ask about one sailing.
     *
     * A charter's start is the one {@see ProposedWindowBuilder} builds — the
     * product's own time for a fixed start, the guest's proposal for a
     * flexible one — which is the start `GET /availability` tested. A proposal
     * the builder refuses has no start to test; the end of that local day
     * stands in, so a date already gone is still refused and the rest is left
     * to the checks that own the proposal.
     */
    public static function forRequest(
        Product $product,
        ?Departure $departure,
        Carbon $localDate,
        ?string $startTime = null,
        int $extraHours = 0,
    ): ?AvailabilityRejection {
        $timezone = LocalDateTimeResolver::timezone();

        if ($departure instanceof Departure) {
            $startsAtUtc = $departure->starts_at_utc;
            $localDate = $departure->local_date;
        } else {
            $window = ProposedWindowBuilder::build($product, $localDate, $startTime, $extraHours)['window'];
            $startsAtUtc = $window instanceof Window
                ? $window->startUtc
                : LocalDay::of($localDate, $timezone)->endUtcExclusive;
        }

        ['plans' => $plans, 'seasons' => $seasons] = RatePlanResolver::load($product);

        return self::checkAgainst($plans, $seasons, $startsAtUtc, $localDate, $timezone);
    }

    /**
     * Is it too late to pay for this booking (2026-09-25)?
     *
     * The lead time only (AVL-19): the advance window was asked when the draft
     * was made, and only shrinks from there. `$leadTime: false` asks only
     * whether the trip has started — for a booking the operator priced (an
     * accepted quote, BKG-32's override) or one already at the gateway.
     */
    public static function forBooking(Booking $booking, bool $leadTime = true): ?AvailabilityRejection
    {
        if (! $booking->starts_at_utc->isFuture()) {
            return AvailabilityRejection::LeadTimeTooShort;
        }

        $product = $leadTime ? Product::query()->find($booking->product_id) : null;

        if (! $product instanceof Product) {
            return null;
        }

        ['plans' => $plans, 'seasons' => $seasons] = RatePlanResolver::load($product);
        $date = Carbon::parse($booking->local_date->toDateString());
        $plan = RatePlanResolver::resolve($plans, $seasons, $date)->plan;

        return self::check($plan, $booking->starts_at_utc, $date, LocalDateTimeResolver::timezone()) === AvailabilityRejection::LeadTimeTooShort
            ? AvailabilityRejection::LeadTimeTooShort
            : null;
    }
}
