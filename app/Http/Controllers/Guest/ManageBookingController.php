<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Actions\ApplyGuestChoice;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\GenerateETicket;
use App\Domain\Booking\Actions\MintBalanceSession;
use App\Domain\Booking\Support\BookingCalendarInvite;
use App\Domain\Booking\Support\GuestTokenResolver;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\WeatherChoice;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/b/{manage_token}` — the guest's own booking (spec TOK-6, TOK-7, TOK-13).
 *
 * ## The refund is computed before the guest confirms, from the snapshot
 *
 * TOK-6: *"cancel per policy **showing the exact refund amount computed from
 * the policy snapshot** before confirming."* The figure on the confirmation
 * screen and the figure that is actually refunded come from the same call to
 * {@see RefundEntitlement::forCancellation()} — not from two places that agree
 * today. `ManageBookingTest` asserts they are equal, because "the amount shown
 * equals the amount charged" is the one thing a guest will check.
 *
 * ## Cancel is shown even when it is worth nothing
 *
 * TOK-7, and its reasoning is commercial rather than technical: where the
 * policy yields 0%, the action *"is shown but clearly states that no refund is
 * due, and still allows the guest to release the seat."* An operator would far
 * rather have the seat back to resell than have a guest conclude the button is
 * broken and simply not turn up.
 *
 * ## Every action is a POST, and every action is already idempotent
 *
 * TOK-13: *"a double submit never double-cancels or double-charges."* Nothing
 * here implements that; the Actions behind it already do, and each in its own
 * way. {@see CancelBooking} returns early on a booking that is already
 * cancelled, {@see MintBalanceSession} reuses an open payment row rather than
 * writing a second, and {@see ApplyGuestChoice} records the choice with a
 * conditional update that a second click loses.
 *
 * That is deliberate: idempotency implemented at the controller would protect
 * this page and leave the API and the panel exposed to the same double click.
 */
final class ManageBookingController extends GuestPageController
{
    public function show(Request $request, string $token): Response
    {
        $booking = GuestTokenResolver::booking($token);
        $tenant = $booking === null ? null : GuestTokenResolver::tenantOf($booking->tenant_id);

        if ($booking === null || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $locale = $this->resolveLocale($request, $booking->locale);

        return $this->renderInTenant($tenant, 'guest.booking', fn (): array => [
            'brand' => $this->brandFor($tenant, $locale),
            'booking' => $booking,
            'token' => $token,
            'payments' => Payment::query()
                ->where('booking_id', $booking->getKey())
                ->orderBy('id')
                ->get(),
            // The figure the guest is shown. The same call runs again inside
            // `CancelBooking`, against the same frozen snapshot, so the two
            // cannot disagree — see the class docblock.
            'entitlement' => RefundEntitlement::forCancellation($booking),
            // The same two links as the email (2026-09-18), for the guest who
            // deleted the email and kept the page.
            'calendar' => self::calendarFor($booking, $locale),
            // TOK-6's download, offered rather than promised (2026-09-18): the
            // page said «λίγο πριν την αναχώρηση» from M2 onwards and never
            // grew the button, although #88 had built both the PDF and its
            // route. The link works even before the queue has rendered
            // anything — see {@see self::ticket()}.
            'ticketUrl' => self::ticketUrlFor($booking, $tenant, $token),
            'canCancel' => self::canCancel($booking),
            'weatherChoiceDue' => self::weatherChoiceIsOpen($booking),
            'backUrl' => $this->backToSiteUrl($booking, $tenant),
        ]);
    }

    /**
     * TOK-6's *"downloadable e-ticket"* (#88's PDF, streamed).
     *
     * The file lives on the **private** disk, so there is no URL a web server
     * will serve and this method is the only way to it. That is the point: a
     * ticket carries the guest's name, the meeting point and a scannable code,
     * and on the public disk it would be readable by anybody who guessed a
     * path, with no session and no policy in the way.
     *
     * The credential is the `manage_token` in the URL, exactly as for the page
     * it is linked from — and the same `GuestTokenPage` middleware sets
     * `no-store` and `no-referrer` on it, so tapping a download does not send
     * the token anywhere.
     *
     * ## A ticket that was never made is made here, on the spot (2026-09-18)
     *
     * `GenerateETicketOnConfirmation` is queued on purpose — a Chromium that
     * will not start must never unwind a payment — and the cost of that is a
     * window, from a few seconds on a healthy host to for ever on one whose
     * queue is stopped, in which the booking is confirmed and the file does not
     * exist. Answering that with "this link is not valid" tells a guest at a
     * quay that their ticket is gone, which is both untrue and the worst
     * possible moment to say it. So the file is rendered now, kept, and served.
     *
     * The listener still owns the normal path: this is the fallback, it runs
     * once per missing file, and after it the queued job has nothing left to do.
     * If the render itself fails — no browser on the host — the guest gets the
     * old page and the operator has the booking in the failure feed, which is
     * the same place the queued failure would have landed.
     *
     * Two bookings never get one: a cancelled booking, whose ticket would board
     * somebody onto a trip they are not on, and an operator who does not check
     * anybody in ({@see Tenant::usesCheckIn()}), for whom a ticket is a
     * document nobody will ever ask for. An already-rendered file is still
     * served in both cases — it exists because it was valid when it was made.
     */
    public function ticket(Request $request, string $token): Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if ($booking === null || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $disk = Storage::disk((string) config('kaiki.tickets.disk', 'local'));
        $path = $booking->eticket_path;

        if ($path === null || ! $disk->exists($path)) {
            $path = self::renderTicketNow($booking, $tenant);
        }

        if ($path === null || ! $disk->exists($path)) {
            return $this->linkNotValid($request);
        }

        return response(
            $disk->get($path) ?? '',
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                // The reference rather than the uuid: this filename ends up in
                // a guest's downloads folder, and `KAI-7F3K2.pdf` is the thing
                // they can find again.
                'Content-Disposition' => 'inline; filename="' . $booking->reference . '.pdf"',
            ],
        );
    }

    /**
     * The ticket link for the page, or null when this booking has no ticket.
     *
     * The same two exclusions as {@see self::renderTicketNow()} — a cancelled
     * booking and an operator who boards nobody — so the page never offers a
     * button that the download would then refuse. Everyone else gets it
     * immediately, whether or not the file has been rendered yet.
     */
    private static function ticketUrlFor(Booking $booking, Tenant $tenant, string $token): ?string
    {
        if ($booking->status === BookingStatus::Cancelled || ! $tenant->usesCheckIn()) {
            return null;
        }

        return route('guest.ticket', ['token' => $token]);
    }

    /**
     * Render the missing ticket inside its tenant, and keep it.
     *
     * Returns the stored path, or null when there should be no ticket or the
     * render failed. The failure is swallowed deliberately: the caller's answer
     * to "no file" is a page that explains itself, and a 500 on a guest's phone
     * explains nothing.
     */
    private static function renderTicketNow(Booking $booking, Tenant $tenant): ?string
    {
        if ($booking->status === BookingStatus::Cancelled || ! $tenant->usesCheckIn()) {
            return null;
        }

        try {
            return Tenancy::forTenant($tenant, static fn (): string => app(GenerateETicket::class)($booking));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The trip as a calendar entry (product owner, 2026-09-18).
     *
     * Served from a route and not only attached to the email, for three
     * reasons. A guest who deleted the email still has the page. A guest whose
     * trip moved needs *today's* times, and a file attached in March carries
     * the March times for ever. And an attachment is stripped by some corporate
     * mail filters, where a link survives.
     *
     * Rendered inside the tenant, so the meeting point and the labels come out
     * in the operator's own data and the guest's own language — the same reason
     * {@see self::show()} does it.
     *
     * `attachment`, not `inline`: on iOS and Android it is the download handler
     * that offers "add to calendar", while an inline `text/calendar` is shown
     * to some guests as a screenful of raw text.
     */
    public function calendar(Request $request, string $token): Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if ($booking === null || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $locale = $this->resolveLocale($request, $booking->locale);

        /** @var array{0: string, 1: string}|null $file */
        $file = Tenancy::forTenant($tenant, static function () use ($booking, $locale): ?array {
            $invite = BookingCalendarInvite::for($booking, $locale);

            // A draft that never got a departure has nothing to put in a
            // calendar. The same "link not valid" page as a ticket that was
            // never generated: at that point the difference is not actionable.
            return $invite->isAvailable() ? [$invite->ics(), $invite->filename()] : null;
        });

        if ($file === null) {
            return $this->linkNotValid($request);
        }

        return response($file[0], Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $file[1] . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The page's two calendar links, or null when there is nothing to add.
     *
     * Already inside the tenant: {@see self::show()} calls this from within
     * `renderInTenant`, which is what makes the meeting point readable.
     *
     * Nothing is offered for a trip that has already sailed or a booking that
     * was cancelled. The route still serves both — an old email is a link
     * somebody may tap, and a cancelled booking's file is what *removes* the
     * entry — but a page offering to diary last Tuesday is a page that looks
     * broken.
     *
     * @return array{ics: string, google: string}|null
     */
    private static function calendarFor(Booking $booking, string $locale): ?array
    {
        if ($booking->status === BookingStatus::Cancelled || $booking->starts_at_utc?->isPast() !== false) {
            return null;
        }

        $invite = BookingCalendarInvite::for($booking, $locale);
        $ics = $invite->isAvailable() ? $invite->downloadUrl() : null;

        return $ics === null ? null : ['ics' => $ics, 'google' => $invite->googleUrl()];
    }

    /** TOK-6's cancel, per policy. */
    public function cancel(Request $request, string $token): RedirectResponse|Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        if (! self::canCancel($booking)) {
            // TOK-7's disabled cases: after departure, and already ended. Not
            // an error page — the guest is sent back to a page that explains
            // why the button is not there.
            return redirect()->route('guest.booking', ['token' => $token]);
        }

        Tenancy::forTenant($tenant, function () use ($booking): void {
            app(CancelBooking::class)(
                booking: $booking,
                reason: CancelReason::GuestRequest,
                by: CancelledBy::Guest,
            );
        });

        return redirect()->route('guest.booking', ['token' => $token]);
    }

    /**
     * TOK-6's pay-balance, minting a fresh session (ADR-0004 Option D).
     *
     * The session is created **now**, not at confirmation, so the guest is
     * charged the balance as it stands rather than as it stood in an email
     * three weeks ago. That is ADR-0004's own clause — *"so a legitimately
     * changed balance is charged correctly"* — and it is why the emailed link
     * points here rather than at a gateway URL that would already have expired.
     */
    public function payBalance(Request $request, string $token): RedirectResponse|Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $target = Tenancy::forTenant($tenant, fn () => app(MintBalanceSession::class)($booking));

        if ($target === null) {
            return redirect()->route('guest.booking', ['token' => $token]);
        }

        // Away to the gateway. `away()` rather than `to()` because the
        // destination is not this application and Laravel's route-aware
        // redirect would try to validate it.
        return redirect()->away($target->url);
    }

    /** CXL-7's three options, from the page the choice email links to. */
    public function weatherChoice(Request $request, string $token): RedirectResponse|Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $choice = WeatherChoice::tryFrom((string) $request->input('choice'));

        if ($choice === null) {
            return redirect()->route('guest.booking', ['token' => $token]);
        }

        Tenancy::forTenant($tenant, function () use ($booking, $choice, $request): void {
            app(ApplyGuestChoice::class)(
                booking: $booking,
                choice: $choice,
                // CXL-7's evidence, recorded with the choice itself.
                ip: $request->ip(),
            );
        });

        return redirect()->route('guest.booking', ['token' => $token]);
    }

    /**
     * TOK-6's "edit lead-guest contact details".
     *
     * Name, email and phone — and **not** the pax breakdown, the date or
     * anything else that would change what was sold. A guest who wants a
     * different trip is making a new booking, and a page that let them edit the
     * party silently would let them edit the price.
     */
    public function updateContact(Request $request, string $token): RedirectResponse|Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $validated = $request->validate([
            'guest_name' => ['required', 'string', 'max:190'],
            'guest_email' => ['required', 'email:rfc', 'max:190'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
        ]);

        Tenancy::forTenant($tenant, function () use ($booking, $validated): void {
            $booking->forceFill([
                'guest_name' => $validated['guest_name'],
                'guest_email' => $validated['guest_email'],
                'guest_phone' => $validated['guest_phone'] ?? null,
            ])->save();
        });

        return redirect()->route('guest.booking', ['token' => $token]);
    }

    /**
     * TOK-7, in full.
     *
     * Three conditions disable it and **a nil refund is not one of them** —
     * see the class docblock. The zero-percent case renders with a plain
     * sentence saying so and still releases the seat.
     */
    public static function canCancel(Booking $booking): bool
    {
        return $booking->status->isLive()
            && $booking->status->canTransitionTo(BookingStatus::Cancelled)
            // After the boat has gone, this is the operator's to record by
            // hand (CXL-4), with its own trail.
            && $booking->starts_at_utc->isFuture();
    }

    /** Is this guest still being asked what they want after a cancelled sailing? */
    public static function weatherChoiceIsOpen(Booking $booking): bool
    {
        return $booking->cancel_reason === CancelReason::Weather
            && $booking->weather_choice === null;
    }

    /** @return array{0: Booking|null, 1: Tenant|null} */
    private function resolve(string $token): array
    {
        $booking = GuestTokenResolver::booking($token);

        return [$booking, $booking === null ? null : GuestTokenResolver::tenantOf($booking->tenant_id)];
    }
}
