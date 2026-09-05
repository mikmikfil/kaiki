<?php

declare(strict_types=1);

namespace App\Enums;

use App\Data\Availability\DepartureAvailabilityData;
use App\Data\Availability\VesselWindowData;

/**
 * One calendar date's answer, as `docs/api.md`'s `AvailabilityDay.status`
 * (spec AVL-29, WGT-16).
 *
 * ## Why a day needs a status at all when every departure already carries a reason
 *
 * WGT-16: *"the widget can explain rather than showing an empty calendar."* A
 * calendar cell renders one thing, not a list of rejections — and the three
 * ways a date can have nothing to sell are three different sentences to a
 * guest:
 *
 * - `not_operating` — the boat does not sail that day. Try another date.
 * - `sold_out` — it sails and is full. Try another date, or fewer people.
 * - `past` — too late to book this one. Nothing to try.
 *
 * Collapsing them into "unavailable" is what produces the grey calendar nobody
 * can act on, and it is the specific failure WGT-16 names.
 *
 * ## The mapping is here, not in the resource
 *
 * `AvailabilityRejection` is the engine's vocabulary — fifteen precise reasons,
 * one per rule in AVL-22 to AVL-31. This enum is the API's, and the contract
 * fixes it at six. The reduction is lossy on purpose, and doing it in one named
 * place means the widget and the hosted calendar cannot disagree about what
 * "sold out" means.
 */
enum AvailabilityDayStatus: string
{
    /** Something on this date is bookable. */
    case Available = 'available';

    /** Departures exist and cannot seat the requested party. */
    case SoldOut = 'sold_out';

    /** A block, a conflict or a rule makes the day unsellable. */
    case Unavailable = 'unavailable';

    /** No schedule rule produces a departure on this date. */
    case NotOperating = 'not_operating';

    /** `mode: quote` — there is nothing to sell directly; send an enquiry. */
    case OnRequest = 'on_request';

    /** Before the tenant's today, or inside the lead-time window. */
    case Past = 'past';

    /**
     * The status for a `per_seat` date, from the departures the engine judged.
     *
     * Note the ordering: **`sold_out` outranks `past`**. A date carrying both a
     * full morning sailing and an afternoon one that has slipped past its lead
     * time is a full day to a guest, and "sold out" is the reading that sends
     * them to another date rather than to another operator.
     *
     * @param  list<DepartureAvailabilityData>  $departures
     */
    public static function forDepartures(array $departures): self
    {
        if ($departures === []) {
            return self::NotOperating;
        }

        foreach ($departures as $departure) {
            if ($departure->available) {
                return self::Available;
            }
        }

        $reasons = array_map(
            static fn (DepartureAvailabilityData $d): ?AvailabilityRejection => $d->rejection,
            $departures,
        );

        if (self::anyOf($reasons, [
            AvailabilityRejection::NotEnoughSeats,
            AvailabilityRejection::LegalCapacityExceeded,
        ])) {
            return self::SoldOut;
        }

        if (self::anyOf($reasons, [AvailabilityRejection::LeadTimeTooShort])) {
            return self::Past;
        }

        return self::Unavailable;
    }

    /**
     * The status for a `per_vessel` date.
     *
     * There is exactly one window per date, so there is no ranking to do — but
     * the same three-way distinction survives: a charter refused because no
     * window could be built at all is `not_operating`, and one refused because
     * the boat is taken is `unavailable`. A charter is never `sold_out`; the
     * boat is not partly available.
     */
    public static function forWindow(VesselWindowData $window): self
    {
        if ($window->available) {
            return self::Available;
        }

        return match ($window->rejection) {
            AvailabilityRejection::NoProposedWindow,
            AvailabilityRejection::OutsideOperatingWindow,
            AvailabilityRejection::OffGrid => self::NotOperating,
            AvailabilityRejection::LeadTimeTooShort => self::Past,
            default => self::Unavailable,
        };
    }

    /**
     * @param  list<AvailabilityRejection|null>  $reasons
     * @param  list<AvailabilityRejection>  $wanted
     */
    private static function anyOf(array $reasons, array $wanted): bool
    {
        foreach ($reasons as $reason) {
            if ($reason !== null && in_array($reason, $wanted, true)) {
                return true;
            }
        }

        return false;
    }
}
