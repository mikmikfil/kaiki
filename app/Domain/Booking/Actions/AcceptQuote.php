<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Data\Pricing\CancellationPolicyData;
use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\Window;
use App\Domain\Booking\Support\QuoteSnapshot;
use App\Enums\BookingStatus;
use App\Enums\QuoteStatus;
use App\Events\QuoteAccepted;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The guest saying yes (`docs/data-model.md` §4.4, spec BKG-26).
 *
 * ## Acceptance re-checks the boat, and can fail
 *
 * §4.4's note calls this *"the single most important behaviour to get right in
 * quote mode"*. A quote is **not** a hold: the boat was on sale the whole time
 * the guest was thinking, and somebody else may have taken it.
 *
 * So the vessel window is read again **under a lock**, at this instant, and if
 * it has gone the guest is told plainly and **the quote stays `sent`** — so the
 * operator can re-quote another date rather than having to rebuild the offer
 * from nothing. Marking it `declined` would be tidier and would lose the
 * operator's work over a race they did not cause.
 *
 * The block this quote's own send may have created is **released first**, in
 * the same transaction, before the window is read. A quote that held the boat
 * and then refused to let the guest accept because the boat was held would be a
 * perfect little deadlock — and releasing it inside the transaction means a
 * failed acceptance rolls the release back with everything else, so the
 * operator's hold survives a race the guest did not cause.
 *
 * ## The snapshots are written here, and CXL-2 is why
 *
 * *"The policy snapshot is written when the Booking row is first persisted with
 * a resolved price — that is, at draft creation for guest bookings, at creation
 * for manual bookings, and at **quote acceptance** for quote bookings."* This
 * is that moment: before it, a quote-mode booking has no price and therefore no
 * terms; after it, both are frozen and a refund years later is computable.
 *
 * ## What it does not do
 *
 * It does not create the gateway session. `StartCheckout` does that, and it
 * makes an external call — AVL-46 keeps that out of a transaction holding a
 * vessel lock. This leaves the booking in `pending_payment`, which is exactly
 * the state the checkout endpoint expects.
 */
final class AcceptQuote
{
    /**
     * @throws RuntimeException when the offer is not live, or the boat has gone
     */
    public function __invoke(Quote $quote): Booking
    {
        if (! $quote->canBeAccepted()) {
            throw new RuntimeException(
                "This quote can no longer be accepted ({$quote->status->value}).",
            );
        }

        $booking = $this->accept($quote);

        // After commit (AVL-46). The confirmation mail, the operator
        // notification and the outbound webhook are all somebody else's
        // latency, and none of them may hold the vessel lock taken above.
        QuoteAccepted::dispatch($quote->getKey(), $booking->getKey(), $booking->tenant_id);

        return $booking;
    }

    private function accept(Quote $quote): Booking
    {
        return DB::transaction(function () use ($quote): Booking {
            /** @var Quote $locked */
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());

            if (! $locked->canBeAccepted()) {
                throw new RuntimeException('This quote can no longer be accepted.');
            }

            /** @var Booking $booking */
            $booking = Booking::query()->findOrFail($locked->booking_id);

            // AVL-45's order: the vessel first, then the booking. The vessel row
            // is locked before the calendar is read, so the answer cannot go
            // stale between the read and the write — which is the whole
            // difference between checking availability and holding it.
            if ($booking->vessel_id !== null) {
                Vessel::query()->lockForUpdate()->find($booking->vessel_id);

                // BKG-25's opt-in hold has done its job. Released **before**
                // the read, and inside this transaction, so a refusal below
                // rolls it back along with everything else.
                VesselBlock::query()->where('booking_id', $booking->getKey())->delete();

                $this->assertWindowIsStillFree($booking);
            }

            /** @var Booking $lockedBooking */
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if (! $lockedBooking->status->canTransitionTo(BookingStatus::PendingPayment)) {
                throw new RuntimeException(
                    "A booking in {$lockedBooking->status->value} cannot accept a quote (§4.1).",
                );
            }

            $policy = $this->policyFor($lockedBooking);
            $snapshot = QuoteSnapshot::fromQuote($locked, $lockedBooking->mode->value, $policy);

            $lockedBooking->forceFill([
                'status' => BookingStatus::PendingPayment,
                'subtotal_cents' => $snapshot->subtotalCents,
                'extras_cents' => $snapshot->extrasCents,
                'discount_cents' => $snapshot->discountCents,
                'total_cents' => $snapshot->totalCents,
                'deposit_cents' => $locked->deposit_cents,
                'balance_cents' => $snapshot->totalCents,
                'vat_rate_bp' => $locked->vat_rate_bp,
                'price_snapshot' => $snapshot->toArray(),
                // CXL-2. Both snapshots, at the same instant, because a booking
                // with a price and no terms is a booking nobody can refund.
                'policy_snapshot' => $policy?->toSnapshot(),
            ])->save();

            $locked->forceFill([
                'status' => QuoteStatus::Accepted,
                'accepted_at' => now(),
            ])->save();

            return $lockedBooking;
        });
    }

    /**
     * Is the boat still free, at this instant, under the lock?
     *
     * Through {@see OccupationCollector}, which is the same reader the
     * calendar and the availability endpoint use — ADR-0023 hides the union of
     * departures, blocks and private holds behind one port precisely so that a
     * second opinion about "is this boat busy" cannot exist.
     *
     * The quote's own block is already gone by the time this runs; see the
     * class docblock.
     */
    private function assertWindowIsStillFree(Booking $booking): void
    {
        $vessel = Vessel::query()->find($booking->vessel_id);

        if ($vessel === null) {
            return;
        }

        $window = Window::of($booking->starts_at_utc, $booking->ends_at_utc);

        if (! OccupationCollector::forRange($vessel, $window)->isFree($window)) {
            // The quote stays `sent`. See the class docblock: the operator's
            // work survives a race the guest did not cause, and the guest is
            // told the date has gone rather than that something broke.
            throw new RuntimeException('vessel_unavailable');
        }
    }

    /**
     * The product's cancellation policy, frozen (CXL-2).
     *
     * Read through the model and frozen immediately, which is the *only* route
     * `CancellationPolicyData` offers — CXL-1 makes a refund a function of this
     * snapshot and never of the live row.
     */
    private function policyFor(Booking $booking): ?CancellationPolicyData
    {
        $product = Product::query()->find($booking->product_id);

        if ($product?->cancellation_policy_id === null) {
            return null;
        }

        $policy = CancellationPolicy::query()->with('tiers')->find($product->cancellation_policy_id);

        return $policy instanceof CancellationPolicy
            ? CancellationPolicyData::fromModel($policy)
            : null;
    }
}
