<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\SeatCommitment;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The other half of BKG-9's trade (spec BKG-10).
 *
 * ## Why this job is not optional
 *
 * BKG-9 commits seats at **redirect** rather than at the webhook, because the
 * alternative leaves the last seat buyable while a guest is typing a card
 * number. The cost of that decision is a guest who closes the tab holding a
 * *committed* seat rather than a held one — and `ExpireStaleHolds` cannot help,
 * because there is no hold left to expire.
 *
 * So this exists to pay that cost back. Without it, every abandoned checkout
 * takes a seat off sale permanently, and a popular Saturday sailing sells out
 * to people who never paid.
 *
 * ## Sixty minutes total, not sixty on top
 *
 * BKG-10: *"the gateway session lifetime plus a grace period (default 60
 * minutes total)"*. A gateway session that lasts thirty minutes leaves thirty
 * minutes of grace, not ninety — the config is a ceiling on how long a seat can
 * be held by silence, which is the number an operator would want to reason
 * about.
 *
 * ## Idempotent, because a scheduler retries
 *
 * BKG-10 asks for it outright. The state transition is guarded by
 * {@see BookingStatus::canTransitionTo()} and the seat release goes through
 * {@see SeatCommitment::release()}, which floors at zero in SQL — so a second
 * run finds nothing to do rather than releasing the same seats twice.
 *
 * ## A confirmed booking is never touched
 *
 * The query is `pending_payment` only. A webhook that arrived a second before
 * this job wins, because the booking is no longer in a status this can move
 * — and that is checked inside the transaction, under the row lock, rather than
 * in the query that selected it.
 */
final class ExpireAbandonedCheckouts
{
    public function __invoke(?int $limit = null): int
    {
        $cutoff = now()->subMinutes((int) config('kaiki.booking.checkout_expiry_minutes'));

        $abandoned = Tenancy::withoutTenancy(static fn () => Booking::query()
            ->where('status', BookingStatus::PendingPayment->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get());

        $expired = 0;

        foreach ($abandoned as $booking) {
            if ($this->expire($booking)) {
                $expired++;
            }
        }

        return $expired;
    }

    private function expire(Booking $booking): bool
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );

        if ($tenant === null) {
            return false;
        }

        try {
            return (bool) Tenancy::forTenant($tenant, fn (): bool => DB::transaction(function () use ($booking): bool {
                // **AVL-45's order: vessel, then departure, then booking.**
                //
                // The obvious way to write this is to lock the booking first —
                // it is the row being expired, and its `departure_id` is what
                // the next lock needs. `LockDisciplineTest` caught that, and it
                // was a real deadlock: this job and a confirmation racing over
                // the same sailing would take the departure and the booking in
                // opposite orders, and MySQL would kill one of them after a
                // lock-wait timeout. The symptom is a confirmation that
                // randomly fails under load and nothing reproducible.
                //
                // The ids come from the unlocked outer read, which is safe
                // because neither of them ever changes on a booking — and the
                // *status*, which does, is re-checked under the lock below.
                if ($booking->vessel_id !== null) {
                    Vessel::query()->lockForUpdate()->find($booking->vessel_id);
                }

                $departure = $booking->departure_id === null
                    ? null
                    : Departure::query()->lockForUpdate()->find($booking->departure_id);

                /** @var Booking $locked */
                $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

                // Re-checked under the lock, not in the selecting query: a
                // webhook that landed between the two wins, and it should.
                if ($locked->status !== BookingStatus::PendingPayment) {
                    return false;
                }

                if ($departure instanceof Departure) {
                    SeatCommitment::release($departure, $locked->pax_capacity_total);
                }

                // The pending payment goes with it. Leaving it open would put
                // an abandoned checkout in the operator's stuck-payment feed
                // forever, competing for attention with real ones.
                Payment::query()
                    ->where('booking_id', $locked->getKey())
                    ->open()
                    ->update(['status' => PaymentStatus::Cancelled->value, 'updated_at' => now()]);

                $locked->forceFill([
                    'status' => BookingStatus::Expired,
                    'cancel_reason' => CancelReason::PaymentFailed,
                    'hold_expires_at' => null,
                ])->save();

                return true;
            }));
        } catch (Throwable $exception) {
            // The id and the class. A booking carries a guest's name, email and
            // phone, and a job that logs the row writes all three every hour.
            Log::warning('booking.checkout_expiry_failed', [
                'booking_id' => $booking->getKey(),
                'tenant_id' => $booking->tenant_id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
