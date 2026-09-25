<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Enums\TripQuestionScope;
use App\Exceptions\CheckoutRefused;
use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\BookingGuest;
use App\Models\TripQuestion;

/**
 * What the hosted checkout asks before money moves, asked again at the line
 * money crosses (audit 2, 2026-09-25).
 *
 * The checkout page refuses to pay without every passenger on a trip with
 * «Στοιχεία επιβατών», and without the trip's required questions. The API has
 * no fields for either — the Kaiki widget hands the guest to that page for
 * exactly this — so `POST /bookings/{uuid}/checkout` used to open the gateway
 * for a party the page would have refused. Now it answers
 * `passengers_required` or `answers_required`, and the integrator sends the
 * guest to `checkout_url`, as the widget does.
 *
 * Phone and quay bookings do not come through here: they are confirmed by the
 * operator, and the list follows on `/g/` (Mike, 25/9).
 */
final class CheckoutDetails
{
    /** @throws CheckoutRefused */
    public static function assertComplete(Booking $booking): void
    {
        if (SaveGuestDetails::documentsRequiredFor($booking)) {
            ManifestRows::ensure($booking);

            $tripDate = SaveGuestDetails::tripDateOf($booking);

            $missing = BookingGuest::query()
                ->with('ageBand')
                ->where('booking_id', $booking->getKey())
                ->get()
                ->contains(static fn (BookingGuest $guest): bool => ! SaveGuestDetails::isComplete($guest, true, $tripDate));

            if ($missing) {
                throw CheckoutRefused::passengersRequired();
            }
        }

        $required = TripQuestionForm::questionsFor($booking)
            ->filter(static fn (TripQuestion $question): bool => $question->is_required);

        if ($required->isEmpty()) {
            return;
        }

        $answers = BookingAnswer::query()->where('booking_id', $booking->getKey())->get();
        $guestIds = BookingGuest::query()->where('booking_id', $booking->getKey())->pluck('id');

        foreach ($required as $question) {
            $answered = $answers->where('trip_question_id', $question->getKey());

            $complete = $question->scope === TripQuestionScope::PerPerson
                ? $guestIds->every(static fn (int $id): bool => $answered->contains('booking_guest_id', $id))
                : $answered->contains(static fn (BookingAnswer $answer): bool => $answer->booking_guest_id === null);

            if (! $complete) {
                throw CheckoutRefused::answersRequired();
            }
        }
    }
}
