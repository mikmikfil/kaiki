<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The operator writing an offer (`docs/data-model.md` §4.4, spec BKG-27).
 *
 * ## A revision is a new row, and that is the whole design
 *
 * §4.4 draws `sent → draft: revise` and then says in its own transition table
 * that the row *"never returns to `draft` in place — the diagram edge is the
 * operator-visible action, the implementation is create-and-supersede, so the
 * audit trail is intact."*
 *
 * Editing the sent row in place would be simpler and would destroy the record
 * of what the guest was actually offered. It would also break the link they are
 * holding: `/q/{token}` would render the *new* price under the *old* URL, with
 * nothing anywhere saying the offer had changed. So a revision mints a new
 * version, a new token, and expires its predecessor — and the old link keeps
 * working and says "this quote was replaced".
 *
 * ## The booking has to be in quote mode, and stays where it is
 *
 * Building a quote changes nothing about the booking. BKG-25: a
 * `quote_requested` booking **holds nothing**, and it goes on holding nothing
 * while the operator types. The transition to `quote_sent` belongs to
 * {@see SendQuote}, because that is the moment the guest learns anything.
 */
final class BuildQuote
{
    /**
     * @param  int|null  $byUserId  the operator; recorded for the trail
     *
     * @throws RuntimeException when the booking is not awaiting a quote
     */
    public function __invoke(Booking $booking, ?int $byUserId = null): Quote
    {
        if (! in_array($booking->status, [BookingStatus::QuoteRequested, BookingStatus::QuoteSent], strict: true)) {
            // §4.4's guard: *"parent booking is `quote_requested` or
            // `quote_sent`"*. Quoting a confirmed booking is not a revision, it
            // is a different conversation.
            throw new RuntimeException(
                "A booking in {$booking->status->value} is not awaiting a quote (§4.4).",
            );
        }

        return DB::transaction(function () use ($booking, $byUserId): Quote {
            // Locked, so two operators pressing "revise" at the same moment
            // cannot both mint version 3. The unique index on
            // (tenant_id, booking_id, version) would refuse the second write
            // anyway — this turns a 500 into a wait.
            Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            $previous = Quote::query()
                ->where('booking_id', $booking->getKey())
                ->orderByDesc('version')
                ->first();

            $quote = new Quote;

            $quote->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->getKey(),
                'version' => $previous === null ? 1 : $previous->version + 1,
                'status' => QuoteStatus::Draft,
                // A new token per version. Reusing it would make the guest's
                // old link silently show the new offer, which is the failure
                // superseding exists to avoid.
                'quote_token' => self::mintToken(),
                'subtotal_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => 0,
                'deposit_cents' => 0,
                'vat_rate_bp' => $booking->vat_rate_bp,
                'valid_until' => now()->addDays(self::defaultValidityDays()),
                'created_by_user_id' => $byUserId,
            ])->save();

            return $quote;
        });
    }

    /**
     * Recompute the quote's totals from its lines.
     *
     * Called after the panel writes line items. The totals live on the quote as
     * well as on the lines because §2.5 puts them there — the "pending quotes"
     * card and the operator's list both sort and sum on them, and a list view
     * that joined the line items to show a total would be a join per row.
     *
     * The sign lives in `kind` (§1.4), so a discount subtracts here and is
     * stored positive there.
     */
    public function recomputeTotals(Quote $quote): Quote
    {
        $lines = QuoteLineItem::query()->where('quote_id', $quote->getKey())->get();

        $subtotal = 0;
        $discount = 0;

        foreach ($lines as $line) {
            if ($line->kind->signum() < 0) {
                $discount += $line->total_cents;

                continue;
            }

            $subtotal += $line->total_cents;
        }

        $quote->forceFill([
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            // Never below zero: a discount larger than the boat is an operator
            // slip, and a negative total would propagate into a gateway session
            // asking a guest for minus fifty euros (PRC-19.1's rule, applied
            // here for the same reason).
            'total_cents' => max(0, $subtotal - $discount),
        ])->save();

        return $quote;
    }

    /** How long a quote is good for by default, from config. */
    public static function defaultValidityDays(): int
    {
        return (int) config('kaiki.booking.quote_validity_days', 7);
    }

    /**
     * A guest token.
     *
     * Forty characters of `Str::random()`, the same shape as `manage_token` and
     * `guest_details_token` — the URL segment *is* the credential, so it is
     * sized to resist guessing rather than to be typed.
     */
    private static function mintToken(): string
    {
        return Str::random(40);
    }
}
