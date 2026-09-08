<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Actions;

use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Erasure, which is anonymisation and not deletion (spec GDR-5, GDR-10).
 *
 * ## Why the rows stay
 *
 * GDR-10 is precise: deleting a tenant *"purges or anonymises all tenant-owned
 * personal data … retaining only what accounting and tax law require (invoices,
 * payment records)"*. The same logic governs one guest.
 *
 * A booking that is deleted takes its invoice's foreign key with it, and an
 * invoice is a document in a state tax register that the operator is required to
 * keep for years. A right-to-erasure request cannot oblige an operator to break
 * tax law, and the GDPR does not ask it to — Article 17(3)(b) says so directly.
 *
 * So the **person** is removed and the **transaction** remains: a booking with a
 * pseudonym, an amount, a date and no way back to a human being.
 *
 * ## What "no way back" means here, and what it does not
 *
 * Every field that identifies a person is overwritten in place: name, email,
 * telephone, date of birth, nationality, identity document, and the notification
 * log's recipient. Overwritten rather than nulled, because a null column reads
 * as *never collected* and this needs to read as *erased on request*, which is
 * the difference between an operator answering an audit and shrugging at one.
 *
 * The pseudonym is stable within one request and carries the erasure date, so a
 * booking and its passengers still hang together for accounting without hanging
 * together as a person.
 *
 * **It is not a cryptographic guarantee.** A payment amount, a date and a trip
 * are still a row, and somebody holding an external record could match them.
 * Saying otherwise in a docblock would be the kind of claim that ends up quoted
 * in a DPA.
 *
 * ## One transaction
 *
 * A half-erased person is worse than an un-erased one: the operator believes the
 * request was honoured and it was not. Either every row changes or none does.
 */
final class EraseGuestData
{
    /**
     * Erase everything this email address identifies.
     *
     * @return array<string, int> what was touched, for the audit record
     */
    public function __invoke(string $email, ?Carbon $now = null): array
    {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('An erasure needs a resolved tenant.');
        }

        $email = mb_strtolower(trim($email));

        if ($email === '') {
            throw new RuntimeException('An erasure needs an email address.');
        }

        $now ??= Carbon::now();
        $pseudonym = $this->pseudonym($now);

        return DB::transaction(function () use ($email, $pseudonym, $now): array {
            /*
             * The bookings are resolved **once, first**, and everything else
             * works from those ids.
             *
             * The first version erased bookings and then looked passengers up
             * through `bookings.guest_email` — which the first step had just
             * overwritten. The passenger query matched nothing and **every
             * passenger name and passport number survived an erasure the
             * operator was told had succeeded.** A test caught it; nothing in
             * the code would have.
             *
             * Resolving the ids up front removes the ordering dependency
             * altogether, rather than leaving it correct and fragile behind a
             * comment about which line has to come second.
             */
            $bookingIds = Booking::query()
                ->whereRaw('lower(guest_email) = ?', [$email])
                ->pluck('id')
                ->all();

            return [
                'passengers' => $this->erasePassengers($bookingIds, $pseudonym, $now),
                'bookings' => $this->eraseBookings($bookingIds, $pseudonym),
                'messages' => $this->eraseMessages($email, $pseudonym),
            ];
        });
    }

    /**
     * «Διαγράφηκε κατόπιν αιτήματος · 2026-09-08».
     *
     * The date is in it on purpose. An operator looking at a booking a year
     * later needs to know the name is absent because somebody asked, not
     * because a form was skipped — and the date is what makes the record
     * evidence rather than a gap.
     */
    private function pseudonym(Carbon $now): string
    {
        return __('gdpr.erasure.pseudonym', ['date' => $now->toDateString()]);
    }

    /** @param  list<int|string>  $bookingIds */
    private function eraseBookings(array $bookingIds, string $pseudonym): int
    {
        $count = 0;

        Booking::query()
            ->whereIn('id', $bookingIds)
            ->each(function (Booking $booking) use ($pseudonym, &$count): void {
                $booking->forceFill([
                    'guest_name' => $pseudonym,
                    // A syntactically valid address that reaches nobody. Null
                    // would break every screen that renders a booking, and a
                    // real-looking one could be delivered to somebody.
                    'guest_email' => $this->erasedAddress($booking->getKey()),
                    'guest_phone' => null,
                    'guest_nationality' => null,
                    'guest_vat_number' => null,
                    'guest_company_name' => null,
                    'special_requests' => null,
                ])->save();

                $count++;
            });

        return $count;
    }

    /** @param  list<int|string>  $bookingIds */
    private function erasePassengers(array $bookingIds, string $pseudonym, Carbon $now): int
    {
        $count = 0;

        BookingGuest::query()
            // By booking id, because `booking_guests` carries no contact details
            // of its own — a passenger's address is never collected — and
            // because the ids were resolved before anything was overwritten.
            ->whereIn('booking_id', $bookingIds)
            ->each(function (BookingGuest $guest) use ($pseudonym, $now, &$count): void {
                $guest->forceFill([
                    'full_name' => $pseudonym,
                    'date_of_birth' => null,
                    'nationality' => null,
                    'document_number' => null,
                    'document_type' => null,
                    // The same stamp the retention job uses, so a document
                    // erased on request and one that aged out read alike to
                    // everything downstream — both mean "there is nothing here
                    // and there was something".
                    'document_purged_at' => $guest->document_purged_at ?? $now,
                ])->save();

                $count++;
            });

        return $count;
    }

    /**
     * The message log keeps that a message was sent, not who to.
     *
     * The row is an operational record — a reminder went out at this hour and
     * was delivered — and deleting it would lose the operator's answer to "did
     * you tell them". The address in `to` is the personal part and the only
     * part that has to go.
     *
     * `to` is quoted in the raw comparison because it is a reserved word in
     * more than one dialect, and an unquoted one is a syntax error on the day
     * somebody runs this against MySQL rather than SQLite.
     */
    private function eraseMessages(string $email, string $pseudonym): int
    {
        $count = 0;

        NotificationLog::query()
            ->whereRaw('lower("to") = ?', [$email])
            ->each(function (NotificationLog $log) use ($pseudonym, &$count): void {
                $log->forceFill(['to' => $pseudonym])->save();

                $count++;
            });

        return $count;
    }

    /**
     * A deliverable-looking address that is not deliverable.
     *
     * `.invalid` is reserved by RFC 2606 precisely for this: it can never be
     * registered, so nothing sent here can reach a person by accident. The
     * booking id keeps two erased bookings distinct, which matters to any screen
     * that groups by address.
     */
    private function erasedAddress(int|string $bookingId): string
    {
        return "erased-{$bookingId}@erased.invalid";
    }
}
