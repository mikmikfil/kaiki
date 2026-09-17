<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\BookingGuest;
use Illuminate\Support\Str;

/**
 * One `booking_guests` row per person on a booking, created when missing
 * (2026-09-17).
 *
 * ## Why this exists
 *
 * {@see SaveGuestDetails} writes a passenger's name and document **by
 * position, onto rows that already exist**, and creates nothing. Only the
 * importer ever created those rows. A booking made from the widget, a hosted
 * page, the panel or an accepted quote had none — so the passenger fields at
 * checkout and on `/g/` saved into nothing, the manifest listed nobody, and
 * the boarding list had no one to tick. Found while moving passenger details
 * into checkout.
 *
 * ## One row per person, in the party's order
 *
 * From `pax_breakdown`, band by band, so row 2 is «Παιδί» when the second
 * person booked was a child — which is what the checkout's passenger panels
 * label them with. Every person, including an infant who takes no seat: the
 * manifest counts people on the boat. The first row is the lead guest and
 * carries the booking's name until somebody types a better one.
 *
 * Idempotent: a booking that already has rows is left alone, so calling this
 * from every place a passenger list is read or written is safe.
 */
final class ManifestRows
{
    public static function ensure(Booking $booking): void
    {
        if (BookingGuest::query()->where('booking_id', $booking->getKey())->exists()) {
            return;
        }

        $people = self::people($booking);

        foreach ($people as $index => $band) {
            $position = $index + 1;

            BookingGuest::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->getKey(),
                'age_band_id' => $band['id'],
                'age_band_code' => $band['code'],
                'position' => $position,
                'is_lead' => $position === 1,
                'full_name' => $position === 1 ? $booking->guest_name : null,
                'ticket_code' => strtoupper(Str::random(24)),
            ]);
        }
    }

    /**
     * The party, one entry per person.
     *
     * @return list<array{id: int|null, code: string}>
     */
    private static function people(Booking $booking): array
    {
        $people = [];

        foreach ((array) $booking->pax_breakdown as $band) {
            $code = (string) ($band['code'] ?? '');
            $uuid = $band['age_band_uuid'] ?? null;
            $id = is_string($uuid)
                ? AgeBand::query()->where('uuid', $uuid)->value('id')
                : null;

            for ($i = 0; $i < max(0, (int) ($band['qty'] ?? 0)); $i++) {
                $people[] = ['id' => $id === null ? null : (int) $id, 'code' => $code];
            }
        }

        if ($people === []) {
            // A booking with no breakdown (a charter, or one written before it
            // existed): as many rows as people, with no band.
            for ($i = 0; $i < max(1, (int) $booking->pax_total); $i++) {
                $people[] = ['id' => null, 'code' => ''];
            }
        }

        return $people;
    }
}
