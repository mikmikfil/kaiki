<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Booking\Actions\CompleteDepartures;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The trip happened and the booking is done (spec BKG-21).
 *
 * > A scheduled job transitions `checked_in` **and `confirmed`** bookings to
 * > `completed` at `ends_at_utc` plus 3 hours.
 *
 * ## Both statuses complete, and that is not a mistake in the requirement
 *
 * A `confirmed` booking that was never checked in still sailed, in every case
 * that matters: small operators do not scan tickets on a six-person day boat,
 * and a platform that left those bookings `confirmed` forever would show an
 * operator a growing list of trips that apparently never ended. No-show is a
 * separate, explicit mark (BKG-23) — absence of a scan is not evidence of one.
 *
 * ## Ids, not a model
 *
 * {@see CompleteDepartures} runs cross-tenant and enters each tenant to act, so
 * the listener has to resolve the tenant first — the same discipline every
 * queued listener in the project follows.
 */
final class BookingCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}
}
