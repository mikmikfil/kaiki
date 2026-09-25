<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Enums\GuestDetailsStatus;
use App\Models\Booking;
use App\Models\Product;
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
}
