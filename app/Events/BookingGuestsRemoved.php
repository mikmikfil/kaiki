<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * People came off a booking that is still going ahead (2026-09-17).
 *
 * Ids only, for the reason {@see BookingConfirmed} gives: its listeners are
 * queued, and a serialised model would be re-read under whatever tenant the
 * worker's previous job left behind. Two listen for it — the guest's email and
 * the new e-tickets — separately, so a Chromium failure does not stop the
 * email and a mail outage does not stop the tickets.
 */
final class BookingGuestsRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        public readonly int $removed,
    ) {}
}
