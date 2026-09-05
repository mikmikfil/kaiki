<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Availability\Support\OccupationCollector;

/**
 * Why a `per_vessel` day cannot be chartered, in the vocabulary
 * `docs/api.md`'s `VesselWindowOption.unavailable_reason` fixes.
 *
 * ## Deliberately coarser than the engine's reasons, and that is a privacy rule
 *
 * The contract states it outright: *"Never names the conflicting booking or
 * guest — a competitor must not be able to read the operator's calendar in
 * detail."*
 *
 * `AvailabilityRejection` distinguishes `VesselBusy` from `VesselHeld` because
 * the engine and the operator's panel need to; a public charter calendar must
 * not, because the difference tells a caller that somebody is *at the checkout
 * right now* for a specific boat on a specific afternoon. Both collapse to
 * `vessel_blocked`.
 *
 * `seats_sold_on_departure` is the one exception and it earns it: AVL-32 says a
 * scheduled departure with **no** seats sold does not block a charter, so being
 * told this reason is being told the operator has real passengers booked, which
 * they were going to find out by being refused anyway. It is also the one case
 * with no remedy — AVL-34: *"There is no override in the guest flow."*
 *
 * `external_calendar` and `maintenance` are in the contract's enum and are not
 * produced yet: the iCal source tables landed in #29 but nothing distinguishes
 * an imported block from a manual one at this level, and `BlockReason` is not
 * consulted by {@see OccupationCollector}.
 * They stay in the enum so the M5 iCal sync narrows a value rather than adding
 * one — a client that has already shipped a `switch` should not have to grow a
 * new arm.
 */
enum WindowUnavailableReason: string
{
    /** The boat is not free: a block, another charter, or a live hold. */
    case VesselBlocked = 'vessel_blocked';

    /** Real passengers are booked on a departure in this window (AVL-32, AVL-34). */
    case SeatsSoldOnDeparture = 'seats_sold_on_departure';

    /** Out of service. Reserved for M5, when `BlockReason` reaches this layer. */
    case Maintenance = 'maintenance';

    /** An imported iCal busy period. Reserved for M5. */
    case ExternalCalendar = 'external_calendar';

    /** Too late to book (AVL-19). */
    case LeadTime = 'lead_time';

    /** Too far ahead to book (AVL-20). */
    case AdvanceWindow = 'advance_window';

    /**
     * The public reason for an engine rejection, or null when the day is simply
     * not chartered rather than blocked.
     *
     * A rejection with no window at all — off-grid, outside operating hours, no
     * proposal — is **not** a reason this enum can carry, and inventing one
     * would be wrong: the day is `not_operating`, which
     * {@see AvailabilityDayStatus} already says. Null here means "the status
     * has already explained it".
     */
    public static function fromRejection(?AvailabilityRejection $rejection): ?self
    {
        return match ($rejection) {
            AvailabilityRejection::VesselBusy,
            AvailabilityRejection::VesselHeld,
            AvailabilityRejection::VesselNotActive => self::VesselBlocked,
            AvailabilityRejection::LeadTimeTooShort => self::LeadTime,
            AvailabilityRejection::TooFarAhead => self::AdvanceWindow,
            default => null,
        };
    }
}
