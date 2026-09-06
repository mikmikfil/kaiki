<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\QuoteStatus;
use App\Events\QuoteSent;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\QuoteLineItem;
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
 * `vessel_blocks` row with reason `manual`, and **its expiry equals the
 * quote's `valid_until`**. Not "roughly", not "the operator can set it": equal,
 * because two dates that are supposed to match and are entered separately are
 * two dates that will not match.
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

            $this->supersedeOlderVersions($locked);

            $locked->forceFill([
                'status' => QuoteStatus::Sent,
                'sent_at' => now(),
            ])->save();

            /** @var Booking $booking */
            $booking = Booking::query()->lockForUpdate()->findOrFail($locked->booking_id);

            if ($booking->status === BookingStatus::QuoteRequested) {
                $booking->forceFill(['status' => BookingStatus::QuoteSent])->save();
            }

            if ($holdVessel) {
                $this->holdTheWindow($booking, $locked);
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
     * BKG-25's opt-in hold: a `vessel_blocks` row expiring with the quote.
     *
     * Reason `manual`, because that is what it is — an operator taking a boat
     * off sale on purpose, visible in the vessel calendar beside every other
     * block, rather than an invisible reservation only the quote knows about.
     * `booking_id` carries the link back; §2.5 makes it an indexed column with
     * no foreign key, which is the FK-less side of the `vessel_blocks` ↔
     * `bookings` cycle.
     */
    private function holdTheWindow(Booking $booking, Quote $quote): void
    {
        if ($booking->vessel_id === null) {
            return;
        }

        VesselBlock::query()->updateOrCreate(
            // One block per booking, refreshed rather than duplicated: an
            // operator who revises and re-sends must not leave the first hold
            // behind on a boat nobody can sell.
            ['booking_id' => $booking->getKey()],
            [
                'vessel_id' => $booking->vessel_id,
                'starts_at_utc' => $booking->starts_at_utc,
                // **Equal to `valid_until`**, not merely near it. See the class
                // docblock: this is the whole of BKG-25's resolution.
                'ends_at_utc' => $quote->valid_until,
                'local_date' => $booking->local_date,
                'local_end_date' => $quote->valid_until->toDateString(),
                'is_all_day' => false,
                'reason' => BlockReason::Manual,
                'title' => Str::limit('Quote ' . $booking->reference, 120),
            ],
        );
    }
}
