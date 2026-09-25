<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\CreateVesselBlock;
use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\Window;
use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\QuoteStatus;
use App\Events\QuoteSent;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sending the offer, and the one moment a quote may hold a boat
 * (spec BKG-25 RESOLVED, BKG-26; `docs/data-model.md` §4.4).
 *
 * ## The hold is opt-in, and it expires when the quote does
 *
 * BKG-25 is marked RESOLVED with its reason attached: *"otherwise a quote
 * request would block a vessel indefinitely."* A `quote_requested` booking
 * holds **nothing** — an operator with a dozen open enquiries would otherwise
 * have a boat that cannot be sold to anybody.
 *
 * So the hold is a deliberate act at the moment of sending, it is a
 * `vessel_blocks` row with reason `manual`, and **it lasts as long as the
 * quote does**: `ExpireQuotes` deletes it when `valid_until` passes, and
 * `AcceptQuote` and `DeclineQuote` when the guest answers. What it covers is
 * the charter's own window (2026-09-25). Until then the block ran from the
 * charter's start to `valid_until`, which for a charter further out than the
 * quote's validity ended before it began and held nothing at all.
 *
 * ## Superseding, in the same transaction
 *
 * §4.4: sending version N expires every older version. The old links keep
 * working and render "this quote was replaced by a newer one" — which is only
 * possible because superseding sets a status rather than deleting a row.
 *
 * ## The email is dispatched after commit
 *
 * AVL-46, as everywhere. {@see QuoteSent} carries ids; the mail is #87's.
 */
final class SendQuote
{
    /**
     * @param  bool  $holdVessel  BKG-25's explicit opt-in
     *
     * @throws RuntimeException when the quote cannot be sent from where it is
     */
    public function __invoke(Quote $quote, bool $holdVessel = false): Quote
    {
        $this->guard($quote);

        $sent = DB::transaction(function () use ($quote, $holdVessel): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());

            if ($locked->status !== QuoteStatus::Draft) {
                // Re-checked under the lock: a second click, or an operator on
                // two screens. Sending twice would supersede the version that
                // was just sent.
                return $locked;
            }

            // AVL-45's order, as `AcceptQuote` takes it: the boat before the
            // booking, and only when the boat is to be held (audit 2).
            $vesselId = Booking::query()->whereKey($locked->booking_id)->value('vessel_id');

            $vessel = $holdVessel && $vesselId !== null
                ? Vessel::query()->lockForUpdate()->find($vesselId)
                : null;

            /** @var Booking $booking */
            $booking = Booking::query()->lockForUpdate()->findOrFail($locked->booking_id);

            // Only a booking still waiting for an offer is sent one (audit 2):
            // a guest who accepted an earlier version, or a cancelled booking,
            // is not mailed a second offer.
            if (! in_array($booking->status, [BookingStatus::QuoteRequested, BookingStatus::QuoteSent], true)) {
                throw new RuntimeException((string) __('quotes.quote.actions.send.not_awaiting'));
            }

            $this->supersedeOlderVersions($locked);

            $locked->forceFill([
                'status' => QuoteStatus::Sent,
                'sent_at' => now(),
                // Never open past the trip's start (2026-09-25), whatever date
                // the form was given: the guest could otherwise accept, and
                // pay, after the boat has left.
                'valid_until' => $locked->valid_until->greaterThan($booking->starts_at_utc)
                    ? $booking->starts_at_utc->copy()
                    : $locked->valid_until,
            ])->save();

            if ($booking->status === BookingStatus::QuoteRequested) {
                $booking->forceFill(['status' => BookingStatus::QuoteSent])->save();
            }

            if ($holdVessel && $vessel instanceof Vessel) {
                $this->holdTheWindow($booking, $vessel);
            }

            return $locked;
        });

        QuoteSent::dispatch($sent->getKey(), $sent->booking_id, $sent->tenant_id);

        return $sent->refresh();
    }

    /**
     * §4.4's send guard, in full.
     *
     * All three conditions are the operator's own mistakes and each has a
     * different consequence: an empty quote is an email with nothing in it, a
     * zero total is a free charter, and a `valid_until` in the past is an offer
     * the guest cannot accept and cannot be told why.
     */
    private function guard(Quote $quote): void
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw new RuntimeException("A quote in {$quote->status->value} cannot be sent (§4.4).");
        }

        if (! QuoteLineItem::query()->where('quote_id', $quote->getKey())->exists()) {
            throw new RuntimeException('A quote must have at least one line item before it is sent (§4.4).');
        }

        if ($quote->total_cents < 1) {
            throw new RuntimeException('A quote must total more than zero before it is sent (§4.4).');
        }

        if ($quote->valid_until->isPast()) {
            throw new RuntimeException('A quote cannot be sent already expired (§4.4).');
        }

        $startsAt = Booking::query()->find($quote->booking_id)?->starts_at_utc;

        if ($startsAt !== null && ! $startsAt->isFuture()) {
            throw new RuntimeException('A quote cannot be sent for a trip that has already started.');
        }
    }

    /**
     * Every older version goes to `expired`, never to deleted.
     *
     * §2.5: *"the guest may still have the old link open, and `/q/{token}` must
     * be able to say 'this quote was replaced'."*
     */
    private function supersedeOlderVersions(Quote $quote): void
    {
        Quote::query()
            ->where('booking_id', $quote->booking_id)
            ->where('version', '<', $quote->version)
            ->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])
            ->update([
                'status' => QuoteStatus::Expired->value,
                'expired_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * BKG-25's opt-in hold: a `vessel_blocks` row over the charter's window,
     * removed when the quote expires or is answered.
     *
     * Reason `manual`, because that is what it is — an operator taking a boat
     * off sale on purpose, visible in the vessel calendar beside every other
     * block, rather than an invisible reservation only the quote knows about.
     * `booking_id` carries the link back; §2.5 makes it an indexed column with
     * no foreign key, which is the FK-less side of the `vessel_blocks` ↔
     * `bookings` cycle.
     */
    private function holdTheWindow(Booking $booking, Vessel $vessel): void
    {
        // Only on a boat nobody else has (audit 2), asked under the vessel
        // lock like `CreateVesselBlock`: a hold over a charter or a sold
        // sailing is an offer that can never be accepted, and the block would
        // take that sailing off sale without a word. This booking's own hold,
        // from an earlier version, is set aside first, as `AcceptQuote` does.
        VesselBlock::query()->where('booking_id', $booking->getKey())->get()->each(static fn (VesselBlock $block) => $block->delete());

        $window = Window::of($booking->starts_at_utc, $booking->ends_at_utc);

        if (CreateVesselBlock::bookingsUnder($vessel, $window, (int) $booking->getKey()) !== []
            || ! OccupationCollector::forRange($vessel, $window, (int) $booking->getKey())->isFree($window)) {
            throw new RuntimeException((string) __('quotes.quote.actions.send.vessel_taken'));
        }

        VesselBlock::query()->updateOrCreate(
            // One block per booking, refreshed rather than duplicated: an
            // operator who revises and re-sends must not leave the first hold
            // behind on a boat nobody can sell.
            ['booking_id' => $booking->getKey()],
            [
                'vessel_id' => $booking->vessel_id,
                // The charter's own window, not the quote's life: how long
                // the hold lasts is the quote's, where it sits is the trip's.
                'starts_at_utc' => $booking->starts_at_utc,
                'ends_at_utc' => $booking->ends_at_utc,
                'local_date' => $booking->local_date,
                // The local date of the end, in the operator's zone.
                'local_end_date' => $booking->ends_at_utc->copy()
                    ->setTimezone($booking->tenant->timezone ?? (string) config('kaiki.defaults.timezone'))
                    ->toDateString(),
                'is_all_day' => false,
                'reason' => BlockReason::Manual,
                'title' => Str::limit('Quote ' . $booking->reference, 120),
            ],
        );
    }
}
