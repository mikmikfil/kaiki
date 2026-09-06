<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Actions\ApplyGuestChoice;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\MintBalanceSession;
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
use Symfony\Component\HttpFoundation\Response;

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
            'canCancel' => self::canCancel($booking),
            'weatherChoiceDue' => self::weatherChoiceIsOpen($booking),
        ]);
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
