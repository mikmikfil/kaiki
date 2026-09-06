<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Domain\Booking\Support\GuestTokenResolver;
use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/g/{guest_details_token}` — the manifest (spec TOK-8, TOK-9, TOK-10, SEC-14).
 *
 * ## It stays reachable after the deadline, and after the boat has gone
 *
 * TOK-10, and it is the requirement most likely to be discovered broken in M6
 * rather than here. A page that 404'd after departure would be a guest tapping
 * a link in their own inbox and being told it is not valid — which is
 * indistinguishable, to them, from the security failure TOK-4 is about.
 *
 * So it is **read-only**, not gone. And after ADR-0012's document purge the
 * page still renders: a missing document number is a *rendering* branch here
 * rather than a 500 in a year's time, which is exactly what the issue's own
 * note asked for.
 *
 * ## Why the page explains itself
 *
 * TOK-9: the form says, in plain Greek and English, *why* a passport number is
 * wanted (the Λιμεναρχείο manifest), how long it is kept (GDR-4) and who sees
 * it. That is not decoration. A guest asked for a document number by a website
 * with no explanation is a guest who closes the tab, and an operator who then
 * cannot sail.
 *
 * ## The ναυλοσύμφωνο acceptance, but not the document
 *
 * TOK-8 asks for a checkbox capturing *"timestamp and IP"* on per-vessel
 * bookings. The **document** is M6's; the evidence columns already exist —
 * `bookings.terms_accepted_at` with `bookings.ip_address` beside it, which
 * §2.5 describes as *"also the ναυλοσύμφωνο acceptance evidence"*. So there is
 * no migration here, and M6 renders a document against evidence already
 * collected.
 */
final class GuestDetailsController extends GuestPageController
{
    public function show(Request $request, string $token): Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $locale = $this->resolveLocale($request, $booking->locale);

        return $this->renderInTenant($tenant, 'guest.details', fn (): array => [
            'brand' => $this->brandFor($tenant, $locale),
            'booking' => $booking,
            'token' => $token,
            // Ordered by `position`, because the form writes back by position
            // and a re-ordered list would put a passport against the wrong
            // person.
            'guests' => BookingGuest::query()
                ->where('booking_id', $booking->getKey())
                ->orderBy('position')
                ->get(),
            'readOnly' => self::isReadOnly($booking),
            'needsDocuments' => SaveGuestDetails::documentsRequiredFor($booking),
            'needsCharterAgreement' => self::needsCharterAgreement($booking),
        ]);
    }

    public function save(Request $request, string $token): RedirectResponse|Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        if (self::isReadOnly($booking)) {
            // TOK-10: readable, not writable. A guest arriving after departure
            // sees their manifest and cannot rewrite it.
            return redirect()->route('guest.details', ['token' => $token]);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($request->input('guests')) ? $request->input('guests') : [];

        Tenancy::forTenant($tenant, function () use ($booking, $rows, $request): void {
            app(SaveGuestDetails::class)($booking, $rows);

            if (self::needsCharterAgreement($booking) && $request->boolean('charter_agreement')) {
                // TOK-8's checkbox. The fact is the timestamp; the address is
                // the evidence, in the column §2.5 names for it.
                $booking->forceFill([
                    'terms_accepted_at' => now(),
                    'ip_address' => $request->ip(),
                ])->save();
            }
        });

        return redirect()->route('guest.details', ['token' => $token]);
    }

    /**
     * TOK-10's read-only window.
     *
     * After departure, or once the booking has stopped being live. Not after
     * the *deadline*: a guest who is late with a passport number is a guest an
     * operator would still rather have the number from, and refusing it at the
     * deadline would send them to the phone instead.
     */
    public static function isReadOnly(Booking $booking): bool
    {
        return ! $booking->status->isLive() || $booking->starts_at_utc->isPast();
    }

    /** Per-vessel bookings sign a ναυλοσύμφωνο; per-seat ones do not (TOK-8). */
    public static function needsCharterAgreement(Booking $booking): bool
    {
        return $booking->mode !== BookingMode::PerSeat && $booking->terms_accepted_at === null;
    }

    /** @return array{0: Booking|null, 1: Tenant|null} */
    private function resolve(string $token): array
    {
        $booking = GuestTokenResolver::guestDetails($token);

        return [$booking, $booking === null ? null : GuestTokenResolver::tenantOf($booking->tenant_id)];
    }
}
