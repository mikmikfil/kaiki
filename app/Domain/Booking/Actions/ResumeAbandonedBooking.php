<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\BookingDraftData;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\DiscountCodeRefused;
use App\Exceptions\HoldRefused;
use App\Models\Booking;
use App\Models\BookingExtra;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * «Ολοκλήρωση πληρωμής» from the abandoned-payment email (24/9, subject A).
 *
 * The email goes when the checkout has already expired, so the seats are back
 * on sale and the old booking is `expired` — a terminal state, on purpose. The
 * button does not revive it: it makes a **new** draft with the same trip, day,
 * time, party, extras and codes, which checks availability and prices again
 * exactly as the widget would, and the guest carries on at that draft's
 * checkout. What the mockup promised: «Θα ελέγξουμε ξανά τη διαθεσιμότητα
 * όταν πατήσετε το κουμπί».
 *
 * Pressed twice, or pressed after the guest already booked again from the
 * site, it finds that booking instead of making another.
 */
final class ResumeAbandonedBooking
{
    public function __construct(private readonly CreateBookingDraft $createDraft) {}

    /** Was this booking left at the payment page, rather than cancelled or lapsed another way? */
    public static function applies(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Expired
            && $booking->cancel_reason === CancelReason::PaymentFailed;
    }

    /**
     * The same guest's newer booking for the same sailing, if there is one.
     *
     * Their own second attempt, or a first press of this button.
     */
    public static function successor(Booking $booking): ?Booking
    {
        if ($booking->guest_email === null || $booking->departure_id === null) {
            return null;
        }

        return Booking::query()
            ->where('departure_id', $booking->departure_id)
            ->where('guest_email', $booking->guest_email)
            ->whereKeyNot($booking->getKey())
            ->where('created_at', '>=', $booking->created_at)
            ->whereIn('status', [
                BookingStatus::Draft->value,
                BookingStatus::PendingPayment->value,
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
                BookingStatus::Completed->value,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * @throws ValidationException when the trip cannot be sold that day any more
     * @throws HoldRefused when the boat has filled
     * @throws DiscountCodeRefused when the code has run out
     */
    public function __invoke(Booking $booking): Booking
    {
        $existing = self::successor($booking);

        if ($existing instanceof Booking) {
            return $existing;
        }

        $booking->loadMissing(['product', 'voucher', 'discountCode', 'extras']);

        $pax = [];

        foreach ((array) $booking->pax_breakdown as $line) {
            $code = (string) ($line['code'] ?? '');

            if ($code !== '' && (int) ($line['qty'] ?? 0) > 0) {
                $pax[$code] = (int) $line['qty'];
            }
        }

        $extras = $booking->extras
            ->filter(static fn (BookingExtra $extra): bool => $extra->extra_id !== null && $extra->qty > 0)
            ->mapWithKeys(static fn (BookingExtra $extra): array => [(int) $extra->extra_id => (int) $extra->qty])
            ->all();

        return ($this->createDraft)(new BookingDraftData(
            product: $booking->product,
            date: Carbon::parse($booking->local_date->toDateString()),
            guestName: $booking->guest_name,
            guestEmail: $booking->guest_email,
            guestPhone: $booking->guest_phone,
            guestCountry: $booking->guest_country,
            locale: $booking->locale ?: 'el',
            source: $booking->source,
            paxByCode: $pax,
            extraQuantities: $extras,
            startTime: (string) $booking->local_time,
            voucherCode: $booking->voucher?->code,
            discountCode: $booking->discountCode?->code,
            specialRequests: $booking->special_requests,
            utm: [
                'utm_source' => $booking->utm_source,
                'utm_medium' => $booking->utm_medium,
                'utm_campaign' => $booking->utm_campaign,
                'utm_term' => $booking->utm_term,
                'utm_content' => $booking->utm_content,
            ],
            isTest: (bool) $booking->is_test,
            originUrl: $booking->origin_url,
        ));
    }
}
