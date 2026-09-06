<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Events\BookingCompleted;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The trip is over (spec BKG-21).
 *
 * > A scheduled job transitions `checked_in` **and `confirmed`** bookings to
 * > `completed` at `ends_at_utc` plus 3 hours, and transitions the departure to
 * > `completed`.
 *
 * ## Three hours, added to a UTC instant, and never to a local one
 *
 * This is the DST trap in its purest form. `ends_at_utc + 3h` is an instant plus
 * a duration and is the same instant whichever side of a clock change it falls
 * on. Computing it as *"local finish time plus three hours, converted back"*
 * looks equivalent, reads more naturally, and is wrong by an hour on the last
 * Sunday in October — which is inside the Greek season, on a day boats are
 * sailing.
 *
 * So the comparison is made against the stored UTC column with UTC arithmetic
 * and no timezone appears in this file at all. `CompletionSweepTest` brackets a
 * clock change specifically to hold that.
 *
 * ## Why three hours and not zero
 *
 * A grace period, and BKG-21 chose it rather than this. A sunset cruise that
 * came back late, a skipper who took the long way round, a guest checked in
 * from a phone with no signal until the boat docked — all of them write to a
 * booking after `ends_at_utc`, and a booking that had already gone `completed`
 * refuses the write (§4.1: `completed` transitions only to `refunded`).
 *
 * ## `confirmed` completes too, and that is not a mistake in the requirement
 *
 * Small operators do not scan tickets on a six-person day boat. Leaving those
 * bookings `confirmed` forever would give an operator a growing list of trips
 * that apparently never ended, and would make "how many trips did we run" a
 * question the platform cannot answer. Absence of a scan is not evidence of a
 * no-show; BKG-23 makes that a separate, explicit mark.
 *
 * ## Cross-tenant, and idempotent per row
 *
 * The same shape as {@see ExpireQuotes} and the hold sweeper: rows are found
 * `withoutTenancy()` and each tenant is entered to act. Every write is a
 * conditional update keyed on the status it is leaving, so two overlapping
 * sweeps complete each booking once and the second run of the day is a no-op.
 */
final class CompleteDepartures
{
    /** BKG-21's grace period. */
    public const GRACE_HOURS = 3;

    /** @return int how many bookings were completed */
    public function __invoke(?int $limit = null): int
    {
        $cutoff = Carbon::now()->subHours(self::GRACE_HOURS);

        $due = Tenancy::withoutTenancy(static fn () => Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->where('ends_at_utc', '<=', $cutoff)
            ->orderBy('ends_at_utc')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get());

        $completed = 0;

        foreach ($due as $booking) {
            if ($this->complete($booking)) {
                $completed++;
            }
        }

        $this->completeDepartures($cutoff);

        return $completed;
    }

    private function complete(Booking $booking): bool
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );

        if ($tenant === null) {
            return false;
        }

        try {
            $claimed = (bool) Tenancy::forTenant($tenant, static fn (): bool => DB::table('bookings')
                ->where('id', $booking->getKey())
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
                ->update([
                    'status' => BookingStatus::Completed->value,
                    'completed_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]) > 0);
        } catch (Throwable $exception) {
            // Ids and the exception class. A booking carries a guest's name,
            // email and phone, and this runs every fifteen minutes.
            Log::warning('booking.completion_failed', [
                'booking_id' => $booking->getKey(),
                'tenant_id' => $booking->tenant_id,
                'exception' => $exception::class,
            ]);

            return false;
        }

        if ($claimed) {
            BookingCompleted::dispatch($booking->getKey(), $booking->tenant_id);
        }

        return $claimed;
    }

    /**
     * The departures themselves, after the bookings on them.
     *
     * In that order deliberately. A departure marked `completed` while its
     * bookings are still `confirmed` is a sailing the operator's dashboard
     * counts as finished and whose guests it still lists as expected — and the
     * window between the two passes is exactly when somebody looks.
     *
     * `cancelled` departures are left alone: a trip that did not sail did not
     * complete, and folding the two together would destroy the one number a
     * weather-prone operator most wants.
     */
    private function completeDepartures(Carbon $cutoff): void
    {
        $due = Tenancy::withoutTenancy(static fn () => Departure::query()
            ->whereIn('status', [DepartureStatus::Scheduled->value, DepartureStatus::Guaranteed->value])
            ->where('ends_at_utc', '<=', $cutoff)
            ->get());

        foreach ($due as $departure) {
            $tenant = Tenancy::withoutTenancy(
                static fn (): ?Tenant => Tenant::query()->find($departure->tenant_id),
            );

            if ($tenant === null) {
                continue;
            }

            Tenancy::forTenant($tenant, static function () use ($departure): void {
                DB::table('departures')
                    ->where('id', $departure->getKey())
                    ->whereIn('status', [DepartureStatus::Scheduled->value, DepartureStatus::Guaranteed->value])
                    ->update([
                        'status' => DepartureStatus::Completed->value,
                        'completed_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
            });
        }
    }
}
