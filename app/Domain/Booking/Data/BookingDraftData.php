<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Domain\Booking\Actions\StartCheckout;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Enums\BookingSource;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Everything BKG-7 requires to create a draft.
 *
 * ## What is deliberately absent
 *
 * **A price.** PRC-1: the price is computed server-side and accepted from
 * nowhere. The widget runs on somebody else's page; a `total_cents` field here
 * would be a number a third party could set, and #37 already refuses one at the
 * quote endpoint for the same reason. `CreateBookingDraft` calls
 * {@see ComputePrice} and uses what it returns.
 *
 * **A status, a reference and any token.** All three are the Action's to
 * decide. A caller that could supply a reference could supply one that already
 * exists.
 *
 * ## `termsAcceptedAt` is a timestamp and not a boolean
 *
 * GDR-9 asks for consent *"with a timestamp and IP"*. A boolean records that
 * somebody ticked a box; a timestamp records when, which is the part that
 * answers a dispute a year later. The caller passes the moment the box was
 * ticked rather than the moment this object was built, because on a slow
 * connection those are not the same and only one of them is evidence.
 */
final class BookingDraftData extends Data
{
    /**
     * @param  array<string, int>  $paxByCode  age band code => how many
     * @param  array<int, int>  $extraQuantities  extra id => quantity
     * @param  array<string, string|null>  $utm  the five `utm_*` values, unvalidated
     */
    public function __construct(
        public readonly Product $product,
        public readonly Carbon $date,
        /**
         * Null until the checkout page asks (ADR-0030).
         *
         * A draft is a hold on seats, not yet a booking by a person: the widget
         * takes a date and a party and hands the guest to `/c/{manage_token}`
         * with the hold already running. {@see StartCheckout} refuses to open a
         * gateway session while either of these is still null, so the pair is
         * required before money moves rather than before seats are held.
         */
        public readonly ?string $guestName,
        public readonly ?string $guestEmail,
        public readonly ?string $guestPhone = null,
        public readonly ?string $guestCountry = null,
        public readonly string $locale = 'el',
        public readonly BookingSource $source = BookingSource::Widget,
        public readonly array $paxByCode = [],
        public readonly array $extraQuantities = [],
        /** Per-vessel flexible start, as `HH:MM` local. Null takes the product's default. */
        public readonly ?string $startTime = null,
        public readonly int $extraHours = 0,
        /** Stored now, applied at confirmation — the arithmetic is PRC-18 to PRC-22. */
        public readonly ?string $voucherCode = null,
        public readonly ?string $specialRequests = null,
        public readonly ?Carbon $termsAcceptedAt = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly array $utm = [],
        /** Sandbox bookings (PAY-11): excluded from every report and every invoice. */
        public readonly bool $isTest = false,
        /**
         * Leave the hold to the caller (BKG-32, added by #89).
         *
         * The **only** caller that sets this is {@see CreateManualBooking}, and
         * only when an operator has explicitly confirmed a capacity override.
         * `CreateBookingDraft` takes the hold without one, so a party that does
         * not fit would be refused before the override could apply — skipping
         * it there and taking it in the manual Action is the only ordering in
         * which BKG-32's override exists at all.
         *
         * A draft with no hold is not a new state: `hold_expires_at` is already
         * null on a quote-mode booking, and `ExpireStaleHolds` reads the column
         * rather than assuming one.
         */
        public readonly bool $skipHold = false,
    ) {}

    /**
     * The five `utm_*` columns, with anything else the caller passed dropped.
     *
     * A whitelist rather than a merge, because these are written straight into
     * columns from a query string an attacker controls, and `utm` is exactly
     * the kind of loosely typed bag somebody later adds `status` to.
     *
     * @return array<string, string|null>
     */
    public function utmColumns(): array
    {
        $columns = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $value = $this->utm[$key] ?? null;

            $columns[$key] = is_string($value) && trim($value) !== ''
                ? mb_substr(trim($value), 0, 120)
                : null;
        }

        return $columns;
    }
}
