<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Actions\CheckVesselAvailability;
use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Channels\Data\ChannelResult;
use App\Enums\ChannelKey;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;

/**
 * An outside system that sells, or occupies, the same boat (spec EXT-1, per
 * ADR-0034).
 *
 * ## The rule this interface exists to enforce
 *
 * The product owner's requirement for the OTAs was one sentence — *«να μην
 * υπάρχει overlap μεταξύ των πλατφορμών»* — and it is met by a prohibition
 * rather than by a mechanism:
 *
 * > **An implementation MUST NOT compute availability.**
 *
 * It calls {@see CheckSeatAvailability} and {@see CheckVesselAvailability}, and
 * it holds seats through {@see HoldSeats}. It issues no query of its own against
 * `departures`. Everything those actions already know —
 * holds, vessel blocks, charter conflicts, the turnaround buffer, the seven
 * AVL-22 conditions, another platform's `external_ical` block — a channel then
 * inherits for free and permanently, including the parts added after it was
 * written. A channel that answers from its own query is a second opinion about
 * the same boat, and a second opinion is the whole of the problem.
 *
 * ## Direction is not uniform, and the interface does not pretend otherwise
 *
 * GetYourGuide's servers **call Kaiki**: availability, reserve, book and cancel
 * are endpoints we host, so those arrive as HTTP requests and are handled by
 * controllers, not by this interface. What is left for a `Channel` to *do* is
 * the outbound half, which is why {@see self::pushAvailability()} exists and why
 * an iCal feed answers it with `notApplicable` — a feed is fetched from us and
 * there is nobody to tell.
 *
 * The reverse holds for {@see self::pullBookings()}: iCal is the implementation
 * that actually pulls, and GetYourGuide has nothing to fetch because it has
 * already told us. **Every implementation declines at least one method**, and
 * {@see ChannelResult::notApplicable()} is how it says so without lying about
 * success. That asymmetry is the reason EXT-1 insisted on a second
 * implementation before an OTA arrived.
 *
 * ## What an implementation must not do
 *
 * **Never throw for an ordinary answer.** A channel that is not connected, or
 * that refuses, is a {@see ChannelResult} — the same argument
 * {@see MyDataGateway} and `CredentialVerifier` are written on. An exception
 * puts a predictable outcome into a retry ladder, where an operator sees it
 * eight times and reads it as an outage.
 *
 * **Never let a credential out.** A channel holds an operator's keys to somebody
 * else's platform. `providerDetail` is stored and shown to support, so the
 * redaction happens before it is returned. `NoCredentialLeakTest` scans for the
 * shapes that get this wrong.
 */
interface Channel
{
    /** Which channel this is — the value stored on mapping and audit rows. */
    public function key(): ChannelKey;

    /**
     * Tell the channel that a departure's availability has moved.
     *
     * Called after every seat movement, which is what keeps a cached date
     * picker on the far side fresh. The staleness this removes can only ever
     * fail in the safe direction — an OTA showing a date as available when it
     * is not, whose booking call then refuses — because availability is
     * answered live and never from what was pushed. So a failure here is worth
     * retrying and never worth blocking a booking for.
     */
    public function pushAvailability(Departure $departure): ChannelResult;

    /**
     * Ask the channel for occupancy or bookings it holds that we do not.
     *
     * EXT-1 calls this "pull bookings"; for iCal what comes back is occupancy —
     * `VesselBlock` rows, not `Booking` rows — because somebody else's calendar
     * says a boat is out without saying who is on it. The distinction is kept in
     * the implementations rather than in two methods, since both answers mean
     * the same thing to availability: this hull is not for sale then.
     */
    public function pullBookings(Tenant $tenant): ChannelResult;

    /**
     * The Kaiki product behind one of the channel's own product ids.
     *
     * EXT-1's "map external product ids", read direction — the one a request
     * from an OTA needs, with an id assigned on their side and no tenant
     * context. Returns `null` when nothing is mapped, which a caller must treat
     * as *this product is not sold here* rather than as *not available*: the
     * two answers send an OTA down very different paths.
     *
     * The write direction is operator configuration and does not belong on a
     * channel; it is a row in the mapping table.
     */
    public function productFor(Tenant $tenant, string $externalProductId): ?Product;

    /**
     * Tell the channel we have processed a cancellation.
     *
     * Acknowledgement is not the cancellation. By the time this is called the
     * booking is already cancelled in Kaiki and the seats are already back —
     * that is the point of AVL-47, and it is why a failure here does not roll
     * anything back. It leaves the far side believing a booking lives that does
     * not, which the nightly reconciliation is for.
     */
    public function acknowledgeCancellation(Booking $booking): ChannelResult;
}
