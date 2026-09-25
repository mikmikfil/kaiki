<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * Which passengers get a scannable code, and under what name.
 *
 * One rule, read by the guest's booking page and by the emails that carry the
 * whole trip (product owner, 2026-09-23: «το QR και μέσα στο email»). Two
 * copies of it would drift, and the drift that matters is the dangerous one —
 * a code in an inbox for a booking the page has already stopped showing one
 * for, which would scan green at a gangway.
 *
 * ## "Whenever there is one" is three conditions
 *
 * Each of them is somebody else's decision rather than this class's:
 *
 * - the platform has switched QR boarding on for this operator
 *   ({@see Tenant::usesQrCheckIn()}, set on `/admin`) — the same switch the
 *   e-ticket PDF reads, so a ticket, a page and an email never disagree about
 *   whether this operator scans at all;
 * - the booking **has a ticket** ({@see BookingStatus::hasTicket()}):
 *   a draft or a booking still at the gateway is live to the seat engine but
 *   its codes are refused at the gangway, and a cancelled one must not scan;
 * - the trip has not already sailed.
 *
 * It only reads. The rows come from `ManifestRows::ensure()` when the draft is
 * made; a booking with none yet gets no codes rather than a write from inside
 * the rendering of a page or a message.
 */
final class BoardingPasses
{
    /**
     * @return list<array{name: string, code: string, guest: BookingGuest}>
     */
    public static function for(Booking $booking, ?Tenant $tenant, ?string $locale = null): array
    {
        // `exists` first: the branding page previews an email with a booking
        // it never saves, which has no status, no departure and no guests.
        if (! $tenant instanceof Tenant
            || ! $booking->exists
            || ! $tenant->usesQrCheckIn()
            || ! $booking->status->hasTicket()
            || $booking->starts_at_utc->isPast()) {
            return [];
        }

        return Tenancy::forTenant($tenant, static function () use ($booking, $locale): array {
            $passes = [];

            foreach ($booking->guests()->orderBy('position')->get() as $guest) {
                if (trim((string) $guest->ticket_code) === '') {
                    continue;
                }

                $passes[] = [
                    // A booking of four gets four codes, and the crew scans one
                    // per person — so each has to say whose it is. The position
                    // is the fallback for a passenger whose name has not been
                    // given yet, because «Επιβάτης 3» is still an answer.
                    'name' => trim((string) $guest->full_name) !== ''
                        ? (string) $guest->full_name
                        : __('guest.booking.passenger', ['position' => $guest->position], $locale),
                    'code' => (string) $guest->ticket_code,
                    'guest' => $guest,
                ];
            }

            return $passes;
        });
    }
}
