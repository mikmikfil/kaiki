<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Models\Booking;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The passenger list a trip asks for, tracked from the booking on (BKG-15/16,
 * TOK-8, ν. 4926/2022 άρθρο 13 — 2026-09-25).
 *
 * Until this, every booking was written `not_required` and nothing ever moved
 * it: the reminders, the attention list and the confirmation's `/g/` link all
 * key on `pending`, so none of them ever fired. Now a booking on a trip with
 * «Στοιχεία επιβατών» switched on starts `pending` ({@see self::initialStatus()}),
 * and when it is confirmed or imported it gets its deadline and its `/g/` token
 * ({@see self::open()}) — lazily, as §2.5 wants: a draft nobody paid for leaves
 * no live URL behind.
 */
final class GuestDetailsTracking
{
    public static function initialStatus(?Product $product): GuestDetailsStatus
    {
        return $product?->guest_details_required === true
            ? GuestDetailsStatus::Pending
            : GuestDetailsStatus::NotRequired;
    }

    /** `starts_at_utc − guest_details_deadline_hours` (BKG-15). */
    public static function deadlineFor(Booking $booking, ?Product $product): ?Carbon
    {
        if ($product?->guest_details_required !== true) {
            return null;
        }

        return $booking->starts_at_utc->copy()->subHours(max(0, (int) $product->guest_details_deadline_hours));
    }

    /**
     * Status, deadline and token for a booking that is going ahead, then the
     * status asked of the rows (a party that filled everything in at checkout
     * is `complete` straight away). Nothing on a trip that asks for nothing.
     */
    public static function open(Booking $booking): void
    {
        $product = Product::query()->find($booking->product_id);

        if ($product?->guest_details_required !== true) {
            // A draft made while the trip still asked is `pending`; if the
            // trip stopped asking before it was confirmed, it is not chased
            // for a list nobody wants (audit 2).
            self::close($booking);

            return;
        }

        $booking->forceFill([
            'guest_details_status' => $booking->guest_details_status === GuestDetailsStatus::NotRequired
                ? GuestDetailsStatus::Pending
                : $booking->guest_details_status,
            'guest_details_deadline_at' => $booking->guest_details_deadline_at ?? self::deadlineFor($booking, $product),
            'guest_details_token' => $booking->guest_details_token ?? GuestTokenResolver::mint(),
        ])->save();

        app(SaveGuestDetails::class)->syncStatus($booking);
    }

    /**
     * «Στοιχεία επιβατών» switched on or off, or its deadline moved, on a trip
     * that already has bookings (audit 2, 2026-09-25).
     *
     * On: every confirmed booking still to sail is opened — status, deadline,
     * `/g/` link — so the reminders find it. A booking not yet confirmed is
     * marked `pending` and opened when it is. Off: nothing is chased any more.
     * Either way the stored deadline follows the trip's hours.
     *
     * @return int how many confirmed bookings were opened that were not before
     */
    public static function syncProduct(Product $product): int
    {
        $opened = 0;

        foreach (self::upcoming($product)->get() as $booking) {
            if ($product->guest_details_required !== true) {
                self::close($booking);

                continue;
            }

            if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)) {
                if ($booking->guest_details_status === GuestDetailsStatus::NotRequired) {
                    $booking->forceFill(['guest_details_status' => GuestDetailsStatus::Pending])->save();
                }

                continue;
            }

            $wasTracked = $booking->guest_details_token !== null
                && $booking->guest_details_status !== GuestDetailsStatus::NotRequired;

            $booking->forceFill(['guest_details_deadline_at' => self::deadlineFor($booking, $product)])->save();

            self::open($booking);

            if (! $wasTracked) {
                $opened++;
            }
        }

        return $opened;
    }

    /**
     * Confirmed bookings still to sail that have no passenger list asked of
     * them — what switching «Στοιχεία επιβατών» on would open.
     */
    public static function untrackedCount(Product $product): int
    {
        return self::upcoming($product)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->where(static fn (Builder $query) => $query
                ->whereNull('guest_details_token')
                ->orWhere('guest_details_status', GuestDetailsStatus::NotRequired->value))
            ->count();
    }

    /**
     * The `/g/` link while the list is still missing something, for the crew
     * to open on their own phone; null when there is nothing to fill in.
     */
    public static function urlWhilePending(Booking $booking): ?string
    {
        if ($booking->guest_details_status !== GuestDetailsStatus::Pending
            || blank($booking->guest_details_token)
            || ! $booking->status->isLive()) {
            return null;
        }

        return route('guest.details', ['token' => $booking->guest_details_token]);
    }

    private static function close(Booking $booking): void
    {
        if ($booking->guest_details_status === GuestDetailsStatus::NotRequired && $booking->guest_details_deadline_at === null) {
            return;
        }

        $booking->forceFill([
            'guest_details_status' => GuestDetailsStatus::NotRequired,
            'guest_details_deadline_at' => null,
        ])->save();
    }

    /** @return Builder<Booking> */
    private static function upcoming(Product $product): Builder
    {
        return Booking::query()
            ->where('product_id', $product->getKey())
            ->where('starts_at_utc', '>', now())
            ->whereIn('status', [
                BookingStatus::Draft->value,
                BookingStatus::PendingPayment->value,
                BookingStatus::QuoteRequested->value,
                BookingStatus::QuoteSent->value,
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
            ]);
    }
}
