<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\DepartureCancelReason;
use App\Enums\DepartureStatus;
use App\Enums\WeatherChoice;
use App\Events\DepartureCancelled;
use App\Events\WeatherChoiceRequested;
use App\Jobs\ApplyWeatherChoiceDefaults;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calling a sailing off, and what that does to the people on it
 * (spec CXL-6, CXL-7, CXL-9, SEC-16).
 *
 * ## Weather is not "cancel every booking at 100%"
 *
 * CXL-6: *"applies `weather_refund_percent` from the policy snapshot to all
 * bookings on the departure"* — **each booking's own snapshot**, which is the
 * whole reason this loops rather than computing one figure and applying it. Two
 * guests on the same boat who booked in different months, under policies the
 * operator has since edited, are owed different proportions of what they paid,
 * and a workflow that read the product's *current* policy would give them both
 * the same wrong answer and break CXL-1 in the most expensive place.
 *
 * ## The money does not move yet, and that is the point of CXL-7
 *
 * A weather cancellation ends the trip and opens a question. The guest chooses
 * refund, voucher or rebook, and until they answer — or until the deadline
 * answers for them — nothing is refunded and no voucher is issued. So each
 * booking is cancelled with `settle: false`, which is not an override: nobody
 * is overruling the policy and CXL-5's mandatory reason would be a lie. The
 * entitlement is real and waiting, recorded as `weather_choice_due_at` rather
 * than as a payment row.
 *
 * That is the difference between this and an operator cancellation, where the
 * refund is immediate because there is nothing to ask.
 *
 * ## An operator cancellation refunds straight away
 *
 * Every other `DepartureCancelReason` — `operator`, `min_pax`,
 * `vessel_booked_privately` — leaves the guest no choice to make, so
 * {@see CancelBooking} runs the ordinary path and the refund is queued
 * immediately. Asking somebody whether they would like their money back for a
 * trip that was never going to sail is a question with one answer.
 */
final class CancelDeparture
{
    public function __construct(
        private readonly CancelBooking $cancelBooking,
    ) {}

    /**
     * @param  string|null  $note  the operator's own words (SEC-16); on the audit row
     * @return int how many bookings were cancelled with it
     */
    public function __invoke(
        Departure $departure,
        DepartureCancelReason $reason = DepartureCancelReason::Operator,
        ?string $note = null,
        ?int $byUserId = null,
        ?Carbon $at = null,
    ): int {
        $at ??= now();

        if ($departure->status === DepartureStatus::Cancelled) {
            // Idempotent: a second call must not cancel the bookings twice, and
            // cancelling twice is how seats come back into `seats_sold` as a
            // negative number.
            return 0;
        }

        $bookings = Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereIn('status', array_map(
                static fn (BookingStatus $status): string => $status->value,
                array_filter(BookingStatus::cases(), static fn (BookingStatus $s): bool => $s->isLive()),
            ))
            ->get();

        DB::transaction(function () use ($departure, $reason, $note, $byUserId, $at): void {
            /** @var Departure $locked */
            $locked = Departure::query()->lockForUpdate()->findOrFail($departure->getKey());

            $locked->forceFill([
                'status' => DepartureStatus::Cancelled,
                'cancel_reason' => $reason,
                'cancelled_at' => $at,
                'cancelled_by_user_id' => $byUserId,
                'cancellation_note' => $note,
            ])->save();
        });

        $departure->refresh();

        $cancelled = 0;

        foreach ($bookings as $booking) {
            if ($reason === DepartureCancelReason::Weather) {
                $this->openTheChoice($booking, $at);
            } else {
                ($this->cancelBooking)(
                    booking: $booking,
                    reason: self::bookingReasonFor($reason),
                    by: CancelledBy::Operator,
                    at: $at,
                );
            }

            $cancelled++;
        }

        // SEC-16's reason, on the audit row, after commit like every other
        // event. Already wired since #53 — this is the path that finally fires
        // it with an operator's own words attached.
        DepartureCancelled::dispatch($departure, $note);

        return $cancelled;
    }

    /**
     * Cancel the booking and start CXL-7's clock, without moving any money.
     *
     * The seats come back and the status is written by the ordinary path — that
     * is CXL-9 and it is the same transaction either way. What is deliberately
     * *not* done is the refund: {@see ApplyGuestChoice} does that when the
     * guest answers, or {@see ApplyWeatherChoiceDefaults} does it for
     * them at the deadline.
     */
    private function openTheChoice(Booking $booking, Carbon $at): void
    {
        $entitlement = RefundEntitlement::forWeather($booking);

        $cancelled = ($this->cancelBooking)(
            booking: $booking,
            reason: CancelReason::Weather,
            by: CancelledBy::Operator,
            // A weather cancellation is not an override — nobody is overruling
            // the policy, and CXL-5's mandatory reason would be a lie here. The
            // money is held back by the *choice*, not by a decision.
            override: null,
            at: $at,
            // The whole difference between this and every other reason.
            settle: false,
        );

        $due = $at->copy()->addDays(self::deadlineDays());

        // Only the deadline. The operator's note belongs to the *departure*
        // and to the audit row; writing it into `internal_notes` would overwrite
        // whatever the operator had already written about this guest.
        $cancelled->forceFill(['weather_choice_due_at' => $due])->save();

        WeatherChoiceRequested::dispatch(
            $cancelled->getKey(),
            $cancelled->tenant_id,
            $entitlement->totalCents,
            $due,
        );
    }

    /**
     * The `bookings.cancel_reason` that goes with a departure's.
     *
     * Two enums rather than one, because §2.5 and §2.4 count different things:
     * a departure is cancelled for `min_pax`, and so is every booking on it,
     * but a *booking* can also be cancelled for `guest_request` and a departure
     * never can. The mapping is total and explicit so a new case on either side
     * is a compile-time question rather than a silent default.
     */
    public static function bookingReasonFor(DepartureCancelReason $reason): CancelReason
    {
        return match ($reason) {
            DepartureCancelReason::Weather => CancelReason::Weather,
            DepartureCancelReason::Operator => CancelReason::Operator,
            DepartureCancelReason::MinPax => CancelReason::MinPax,
            DepartureCancelReason::VesselBookedPrivately => CancelReason::VesselBookedPrivately,
        };
    }

    /** CXL-7's fourteen days, from config so a tenant's support case can move it. */
    public static function deadlineDays(): int
    {
        return (int) config('kaiki.booking.weather_choice_deadline_days', 14);
    }

    /**
     * The operator's default for a tenant, or the platform's (CXL-7).
     *
     * Read here rather than in the sweeper so the two agree: the job applies it
     * and the choice page displays it, and a discrepancy would mean telling a
     * guest one thing and doing another.
     */
    public static function defaultChoiceFor(Tenant $tenant): WeatherChoice
    {
        return WeatherChoice::tryFrom((string) $tenant->weather_choice_default)
            ?? WeatherChoice::platformDefault();
    }

    /** The tenant a booking belongs to, with no tenant in context. */
    public static function tenantOf(Booking $booking): ?Tenant
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );
    }
}
