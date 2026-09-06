<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\CancelReason;
use App\Enums\RefundMethod;
use App\Enums\WeatherChoice;
use App\Events\WeatherChoiceApplied;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Honouring what the guest chose after a weather cancellation (spec CXL-7).
 *
 * ## Recorded first, acted on second
 *
 * CXL-7: *"the choice is recorded with timestamp and IP"*. The recording is a
 * conditional update — `where weather_choice is null` — and **that condition is
 * the idempotency guarantee**, not a check before it. Two clicks from a guest
 * on a slow connection, or a click racing the deadline sweeper, both arrive
 * here; the first writes the row and the second updates nothing, and only the
 * one that wrote goes on to move money.
 *
 * A `SELECT` first would leave a window between the read and the write that
 * two requests drive straight through, and the consequence here is a guest
 * refunded twice — the same reasoning `gateway_webhook_events` and
 * `bookings.reference` both follow.
 *
 * ## The entitlement is recomputed, and can only be computed once
 *
 * `RefundEntitlement::forWeather()` reads `paid_cents`, and honouring the
 * choice changes `paid_cents`. That is safe **because** the write above happens
 * first: a second call never gets past it, so the figure is never recomputed
 * against a booking that has already been refunded. Storing the entitlement in
 * a column would be the other way to make it safe, and would put a derived
 * number in the schema for the benefit of a race that is already closed.
 *
 * ## Voucher and rebook issue the same credit
 *
 * See {@see WeatherChoice}: there is no seat-transfer flow, and a voucher is
 * the only thing that carries a guest's money to a new booking. The choice is
 * still recorded distinctly, because "coming back" and "took the credit" are
 * different facts about a guest and the operator's list should be able to tell
 * them apart.
 */
final class ApplyGuestChoice
{
    public function __construct(
        private readonly RefundBooking $refundBooking,
    ) {}

    /**
     * @param  string|null  $ip  CXL-7's evidence; never a guest's name
     * @param  bool  $automatic  true when the deadline chose, not the guest
     * @return int the cents this call put in motion
     */
    public function __invoke(
        Booking $booking,
        WeatherChoice $choice,
        ?string $ip = null,
        bool $automatic = false,
    ): int {
        if ($booking->cancel_reason !== CancelReason::Weather) {
            // There is nothing to choose about. A guest who guesses the URL for
            // a booking cancelled for any other reason gets no money out of it.
            return 0;
        }

        $entitlement = RefundEntitlement::forWeather($booking);

        $recorded = DB::table('bookings')
            ->where('id', $booking->getKey())
            // **The idempotency guarantee.** See the class docblock.
            ->whereNull('weather_choice')
            ->update([
                'weather_choice' => $choice->value,
                'weather_choice_at' => now(),
                'weather_choice_ip' => $ip,
                'updated_at' => now(),
            ]);

        if ($recorded < 1) {
            return 0;
        }

        $booking->refresh();

        $moved = ($this->refundBooking)(
            $booking,
            $entitlement,
            $choice->issuesVoucher() ? RefundMethod::Voucher : RefundMethod::Cash,
            // No reason: this is not CXL-5's override. Nobody overruled
            // anything — the guest picked from the three options the policy
            // already gave them.
            null,
        );

        WeatherChoiceApplied::dispatch(
            $booking->getKey(),
            $booking->tenant_id,
            $choice,
            $moved,
            $automatic,
        );

        return $moved;
    }
}
