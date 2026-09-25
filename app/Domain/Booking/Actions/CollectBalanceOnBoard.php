<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Models\Booking;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * «Οφείλει €X» → «Πληρώθηκε» at boarding (Mike, 2026-09-25; plan Β2).
 *
 * Until now boarding showed no money at all (TEN-8) and only somebody with
 * `ManageBookings` could record a payment. An operator who collects the balance
 * on the boat then had to phone the office for every guest who paid it, so the
 * rule is reversed — narrowly.
 *
 * ## What crew may do, and nothing more
 *
 * - **The whole open balance**, read here and never typed. Crew choose how it
 *   was paid, not how much: a smaller amount is a conversation the office has.
 * - **Cash or POS.** A bank transfer is not something that happens on a quay.
 * - **A booking in the boarding window**: the same twelve hours back and
 *   twenty-four forward the boarding pages list. A Livewire argument can name
 *   any booking id, so the window is asserted here, not only by the list.
 * - **Confirmed or checked in.** Nothing that is cancelled, expired or unpaid
 *   for at checkout.
 *
 * No refunds and no edits: those stay behind `ManageBookings`, which crew do
 * not hold.
 *
 * ## It is {@see RecordManualPayment}, called with fixed arguments
 *
 * So the payment row, the recomputed `paid/balance`, the withdrawn open gateway
 * order and the audit row (`ManualPaymentRecorded`, which captures the signed-in
 * user) are the same ones the office writes. The amount is re-checked inside
 * that action's lock: a webhook that settled the balance a second ago makes
 * this refuse rather than take the money twice.
 */
final class CollectBalanceOnBoard
{
    /** The boarding pages' window (`CheckIn::todaysBookings()`, `BoardingController`). */
    public const HOURS_BACK = 12;

    public const HOURS_FORWARD = 24;

    public function __construct(
        private readonly RecordManualPayment $recordPayment,
    ) {}

    /**
     * @throws AuthorizationException without `CollectBalanceOnBoard`
     * @throws ValidationException when there is nothing to collect here
     */
    public function __invoke(Booking $booking, PaymentGatewayName $gateway, User $actor): Booking
    {
        if (! $actor->hasCapability(Capability::CollectBalanceOnBoard)) {
            throw new AuthorizationException;
        }

        if (! in_array($gateway, [PaymentGatewayName::Cash, PaymentGatewayName::Pos], true)) {
            throw ValidationException::withMessages(['gateway' => [__('checkin.balance.refused.gateway')]]);
        }

        $booking->refresh();

        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)) {
            throw ValidationException::withMessages(['booking' => [__('checkin.balance.refused.status')]]);
        }

        if (! self::inWindow($booking)) {
            throw ValidationException::withMessages(['booking' => [__('checkin.balance.refused.not_today')]]);
        }

        if ($booking->balance_cents < 1) {
            throw ValidationException::withMessages(['booking' => [__('checkin.balance.refused.nothing_owed')]]);
        }

        return ($this->recordPayment)(
            booking: $booking,
            amountCents: $booking->balance_cents,
            gateway: $gateway,
            userId: (int) $actor->getKey(),
        );
    }

    /** Whether the booking sails inside the boarding window. */
    public static function inWindow(Booking $booking): bool
    {
        $now = Carbon::now();

        return $booking->starts_at_utc->betweenIncluded(
            $now->copy()->subHours(self::HOURS_BACK),
            $now->copy()->addHours(self::HOURS_FORWARD),
        );
    }

    /** Whether this person is shown «Οφείλει» and the button, on this booking. */
    public static function offeredTo(?User $user, Booking $booking): bool
    {
        return $user instanceof User
            // TEN-9 (audit 2): no «Πληρώθηκε» on a read-only account.
            && Tenancy::current()?->allowsWrites() !== false
            && $user->hasCapability(Capability::CollectBalanceOnBoard)
            && $booking->balance_cents > 0
            && in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)
            && self::inWindow($booking);
    }
}
