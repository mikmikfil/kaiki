<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Booking\Actions\ExpireStaleHolds;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A draft booking's hold ran out and the seats went back (spec AVL-38).
 *
 * ## Ids, not a model
 *
 * The same lesson #53 paid for with the audit trail. Listeners are queued (§3),
 * a queued listener is constructed **on the worker**, and a `SerializesModels`
 * payload is re-fetched there — inside whatever tenant context the previous job
 * left behind, which is a cross-tenant read the isolation gate exists to
 * prevent. Carrying plain integers means the listener resolves the tenant
 * first and the booking second, in that order, deliberately.
 *
 * ## Not an `Auditable`
 *
 * ADR-0025 scoped the trail to operator actions and rejected complete history
 * outright: *"every extra row is another row naming a person that the retention
 * and erasure story has to account for."* A hold expiring is the system
 * noticing a clock, not somebody doing something — and at one row per abandoned
 * checkout it would be the single noisiest thing in the table.
 *
 * Dispatched by {@see ExpireStaleHolds}. Nothing listens yet; the abandoned-cart
 * follow-up in M5 is the first thing that will.
 */
final class BookingHoldExpired
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}
}
