<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\OpenGatewayOrders;
use App\Enums\BookingStatus;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\HoldRefused;
use App\Exceptions\IllegalStateTransition;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * A booking still in checkout that somebody has paid part of: confirmed, with
 * the rest as its balance (audit 2).
 *
 * The same answer {@see CreateManualBooking}'s deposit gives a phone booking.
 * A `draft` or `pending_payment` booking holding money used to stay where it
 * was: never confirmed, no ticket, no due date, and a sweeper free to expire it
 * with the money still on it. What was paid becomes the deposit, any card page
 * still open is withdrawn (paid anyway, the webhook gives the surplus back),
 * and {@see ConfirmBooking} does the rest — the seats, the due date, BKG-13.
 */
final class ConfirmPartPaid
{
    public function __construct(private readonly ConfirmBooking $confirmBooking) {}

    /**
     * @throws CapacityExceeded when a draft's seats went while its hold lapsed
     * @throws HoldRefused when a charter's boat went to somebody else
     * @throws IllegalStateTransition when the booking is not in checkout
     */
    public function __invoke(Booking $booking): Booking
    {
        $locked = DB::transaction(static function () use ($booking): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if (! in_array($locked->status, [BookingStatus::Draft, BookingStatus::PendingPayment], true)) {
                throw IllegalStateTransition::forBooking($locked->status, BookingStatus::Confirmed);
            }

            $locked->forceFill([
                'deposit_cents' => min($locked->total_cents, Payment::paidCentsFor($locked->getKey())),
            ])->save();

            OpenGatewayOrders::withdraw($locked);

            return $locked;
        });

        // A `pending_payment` booking's seats went into `seats_sold` at the
        // redirect (BKG-9); a draft's are still held and move now.
        return ($this->confirmBooking)(
            $locked,
            fromCheckout: $locked->status === BookingStatus::PendingPayment,
        );
    }
}
