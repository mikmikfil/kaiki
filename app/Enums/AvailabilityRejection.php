<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Why a departure is not bookable (spec AVL-22, AVL-26, AVL-28).
 *
 * ## Machine codes, because the widget is on somebody else's page
 *
 * The widget renders in a locale we do not control and needs to decide what to
 * *do* — grey out a date, offer the next one, show a message. A sentence cannot
 * be branched on, so the code is the contract and the sentence is a lang line
 * beside it. This is also what lets the API return a stable reason across a
 * copy edit.
 *
 * ## Order is significance, not enumeration
 *
 * A departure usually fails several conditions at once — cancelled *and* past
 * its lead time *and* full. {@see CheckSeatAvailability}
 * reports the **first** that applies in this order, chosen so the guest hears
 * the most actionable thing: "that boat is full" is useful, "the trip is not
 * published" is not, and both are true.
 */
enum AvailabilityRejection: string
{
    use HasTranslatedLabel;

    /** The trip itself is not on sale (AVL-22.6). */
    case ProductNotActive = 'product_not_active';

    /** The boat is out of service (AVL-22.6). */
    case VesselNotActive = 'vessel_not_active';

    /** The operator called this sailing off (AVL-22.2, AVL-28). */
    case DepartureCancelled = 'departure_cancelled';

    /** Too late to book (AVL-19). */
    case LeadTimeTooShort = 'lead_time_too_short';

    /** Too far ahead to book (AVL-20). */
    case TooFarAhead = 'too_far_ahead';

    /** Something else has the boat at that time (AVL-22.1, AVL-7). */
    case VesselBusy = 'vessel_busy';

    /** Not enough seats left for the counted pax (AVL-22.3). */
    case NotEnoughSeats = 'not_enough_seats';

    /**
     * The boat's certificate would be exceeded (AVL-25).
     *
     * Distinct from `NotEnoughSeats` on purpose: this one counts infants, and a
     * party can pass the seat check and fail this. Merging the two would tell a
     * family the boat is full when it is not, and would hide the fact that the
     * limit is legal rather than commercial.
     */
    case LegalCapacityExceeded = 'legal_capacity_exceeded';

    /** A party of infants alone (AVL-26). */
    case NoCountedPax = 'no_counted_pax';

    /** The operator's subscription has lapsed (AVL-22.7, TEN-9). */
    case TenantReadOnly = 'tenant_read_only';

    /** A charter start time off the 15-minute grid (AVL-31). */
    case OffGrid = 'off_grid';

    /** A charter start outside the operator's daily hours (AVL-31). */
    case OutsideOperatingWindow = 'outside_operating_window';

    /** More extra hours than the operator allows (AVL-31). */
    case ExtensionTooLong = 'extension_too_long';

    /**
     * The local time does not exist on that date (ADR-0016).
     *
     * Rare, and worth its own code rather than folding into "unavailable": the
     * guest's remedy is to move by half an hour, and no other reason has that
     * remedy.
     */
    case DstNonExistent = 'dst_non_existent';

    /** A charter with no default start and no proposal (AVL-6). */
    case NoProposedWindow = 'no_proposed_window';

    /**
     * Another guest is at the checkout for this window (AVL-33).
     *
     * Distinct from `VesselBusy`, and the distinction is worth a code: a hold
     * lasts twenty minutes, so this is the one unavailability a guest might
     * reasonably wait out. Reporting it as "booked" would send them away from a
     * boat that is about to be free again.
     */
    case VesselHeld = 'vessel_held';

    /** The guest-facing sentence for this code (CNV-11, I18N-1). */
    public function message(): string
    {
        return $this->label();
    }
}
