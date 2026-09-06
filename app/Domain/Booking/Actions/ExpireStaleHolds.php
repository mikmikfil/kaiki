<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\ReleaseHold;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Events\BookingHoldExpired;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The half of expiry that tidies (spec AVL-38, ADR-0005).
 *
 * ## It is not what makes expiry correct
 *
 * That is the point worth holding onto. AVL-38 asks for expiry *twice over*,
 * and the two halves are not a belt-and-braces duplicate — they fail in
 * opposite directions:
 *
 * - **The read side** ({@see Booking::holdsSeats()}) is the
 *   authority. A hold is gone the instant `hold_expires_at` passes, whether or
 *   not this job has run. That is what makes a backlogged queue incapable of
 *   causing an oversell.
 * - **This sweeper** brings `departures.seats_held` back into line with that
 *   truth and moves the booking to `expired`. Without it the stored counter
 *   drifts, and an operator's dashboard shows seats held by nobody.
 *
 * `HoldExpiryTest` asserts the read-side release **with this job never run**,
 * which is the assertion that proves the order of dependence rather than merely
 * describing it.
 *
 * ## It runs across every tenant
 *
 * A platform job, like the audit purge. `bookings_hold_expiry_idx` leads with
 * `status` rather than `tenant_id` for exactly this query, and it is the only
 * index in that table that does. Each booking is then processed **inside its
 * own tenant's context**, because `ReleaseHold` writes a tenant-owned departure
 * and the isolation gate exists to stop that happening from the wrong one.
 *
 * ## A failure on one booking does not stop the rest
 *
 * A sweeper that abandons its batch on the first bad row leaves every later
 * hold stuck, and the failure compounds each minute. Each booking is released
 * independently and a failure is logged with its id — never with guest data.
 */
final class ExpireStaleHolds
{
    public function __construct(private readonly ReleaseHold $releaseHold) {}

    /** @return int how many holds were released */
    public function __invoke(?int $limit = null): int
    {
        $expired = Tenancy::withoutTenancy(static fn () => Booking::query()
            ->withExpiredHold()
            ->orderBy('hold_expires_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get());

        $released = 0;

        foreach ($expired as $booking) {
            if ($this->expire($booking)) {
                $released++;
            }
        }

        return $released;
    }

    private function expire(Booking $booking): bool
    {
        $tenant = Tenancy::withoutTenancy(
            static fn () => Tenant::query()->find($booking->tenant_id),
        );

        if ($tenant === null) {
            return false;
        }

        try {
            Tenancy::forTenant($tenant, function () use ($booking): void {
                ($this->releaseHold)($booking);

                $booking->forceFill([
                    'status' => BookingStatus::Expired,
                    'cancel_reason' => CancelReason::HoldExpired,
                ])->save();
            });

            BookingHoldExpired::dispatch($booking->getKey(), (int) $booking->tenant_id);

            return true;
        } catch (Throwable $exception) {
            // The id and the class, and nothing else. A booking carries a
            // guest's name, email and phone, and a sweeper that logs the row it
            // failed on writes all three into the log every minute.
            Log::warning('booking.hold_expiry_failed', [
                'booking_id' => $booking->getKey(),
                'tenant_id' => $booking->tenant_id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
