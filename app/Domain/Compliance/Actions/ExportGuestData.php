<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Actions;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Everything one person's email address is attached to (spec GDR-5, GDR-11).
 *
 * ## The request this answers
 *
 * A guest writes to an operator and asks what is held about them. Under the
 * GDPR the operator has a month to answer, and the answer has to be complete and
 * intelligible. Without this, the operator's options are a database query they
 * cannot write and a screenshot of a booking list — neither of which is an
 * answer, and both of which take the deadline.
 *
 * ## The email address reaches passengers through their booking
 *
 * `booking_guests` holds **no contact details at all** — no email, no telephone.
 * That is worth knowing rather than working around: a passenger's address is
 * simply not collected, so the only person this product can be asked about by
 * email is the one who made the booking.
 *
 * Their fellow passengers are found through it. Somebody who books for four and
 * travels with three is one address and four people, and an export that returned
 * only the lead's own row would be an incomplete answer to a request the whole
 * party is covered by.
 *
 * ## What comes back, and the one field that does not
 *
 * Bookings, passengers, messages and payments — GDR-11's four. **Document
 * numbers are excluded**, and that is not an oversight either: GDR-6 keeps them
 * out of every export, and this file is emailed to whoever asked. The export
 * says a document *was* recorded and whether it has since been purged, which is
 * the honest answer to "what do you hold" without putting a passport number in
 * an inbox.
 *
 * ## Amounts stay in cents
 *
 * Machine-readable means machine-readable. A localised «1.234,50 €» in a JSON
 * export is a string somebody has to parse back, and the CSV built alongside it
 * is where a human-readable form belongs.
 */
final class ExportGuestData
{
    /**
     * @return array<string, mixed> the JSON document GDR-11 asks for
     */
    public function __invoke(string $email): array
    {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('A data-subject export needs a resolved tenant.');
        }

        $email = mb_strtolower(trim($email));

        if ($email === '') {
            throw new RuntimeException('A data-subject export needs an email address.');
        }

        $bookings = $this->bookings($email);

        return [
            'subject' => $email,
            'operator' => $tenant->name,
            'generated_at' => Carbon::now()->toIso8601String(),
            // Said out loud in the document rather than left to a policy page:
            // somebody reading this needs to know a field was withheld and why,
            // or the export looks incomplete rather than deliberate.
            'notes' => [
                __('gdpr.export.notes.documents'),
                __('gdpr.export.notes.retention'),
            ],
            'bookings' => $bookings,
            'passengers' => $this->passengers($email),
            'messages' => $this->messages($email),
            'payments' => $this->payments(array_column($bookings, 'id')),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function bookings(string $email): array
    {
        return Booking::query()
            ->whereRaw('lower(guest_email) = ?', [$email])
            ->with('product')
            ->get()
            ->collect()
            ->map(/** @return array<string, mixed> */ fn (Booking $booking): array => [
                'id' => $booking->getKey(),
                'reference' => $booking->reference,
                'status' => $booking->status->value,
                'product' => $booking->product?->getTranslation('title', 'el'),
                'local_date' => $booking->local_date?->toDateString(),
                'guest_name' => $booking->guest_name,
                'guest_email' => $booking->guest_email,
                'guest_phone' => $booking->guest_phone,
                'total_cents' => $booking->total_cents,
                'paid_cents' => $booking->paid_cents,
                'created_at' => $booking->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function passengers(string $email): array
    {
        return BookingGuest::query()
            ->whereHas('booking', fn ($query) => $query->whereRaw('lower(guest_email) = ?', [$email]))
            ->with('booking')
            ->get()
            ->collect()
            ->map(/** @return array<string, mixed> */ fn (BookingGuest $guest): array => [
                'booking_reference' => $guest->booking?->reference,
                'full_name' => $guest->full_name,
                'date_of_birth' => $guest->date_of_birth?->toDateString(),
                'nationality' => $guest->nationality,
                // GDR-6: the fact, never the number.
                'identity_document' => $this->documentState($guest),
                'checked_in_at' => $guest->checked_in_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether a document was recorded, and what has become of it.
     *
     * Three honest answers, none of which is the number itself: it was never
     * given, it is held, or it was destroyed on this date under the retention
     * policy. The third is the one that turns a privacy notice from a promise
     * into evidence.
     */
    private function documentState(BookingGuest $guest): string
    {
        if ($guest->document_purged_at !== null) {
            return __('gdpr.export.document.purged', [
                'date' => $guest->document_purged_at->toDateString(),
            ]);
        }

        return $guest->document_number === null
            ? __('gdpr.export.document.none')
            : __('gdpr.export.document.held');
    }

    /** @return list<array<string, mixed>> */
    private function messages(string $email): array
    {
        return NotificationLog::query()
            ->whereRaw('lower("to") = ?', [$email])
            ->get()
            ->collect()
            ->map(/** @return array<string, mixed> */ fn (NotificationLog $log): array => [
                'sent_at' => $log->sent_at?->toIso8601String(),
                'channel' => $log->channel?->value,
                'template' => $log->template?->value,
                'locale' => $log->locale,
                'status' => $log->status?->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $bookingIds
     * @return list<array<string, mixed>>
     */
    private function payments(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        return Payment::query()
            ->whereIn('booking_id', $bookingIds)
            ->get()
            ->collect()
            ->map(/** @return array<string, mixed> */ fn (Payment $payment): array => [
                'booking_id' => $payment->booking_id,
                'status' => $payment->status?->value,
                'amount_cents' => $payment->amount_cents,
                'gateway' => $payment->gateway?->value,
                'created_at' => $payment->created_at?->toIso8601String(),
                // Deliberately no gateway reference and nothing card-shaped:
                // a payment identifier in an emailed file is a lever for
                // somebody impersonating the person who asked.
            ])
            ->values()
            ->all();
    }
}
