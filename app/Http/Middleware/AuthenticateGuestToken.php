<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\BookingStatus;
use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiKey;
use App\Models\Booking;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `X-Kaiki-Guest-Token` on the three per-booking endpoints
 * (`docs/api.md` §2.1, §2.2).
 *
 * ## The first place in the product where a publishable key is refused
 *
 * Every SEC-5 test so far has proved a `pk_` *can* do something. This proves
 * one cannot, and the reasoning is §2.1's own:
 *
 * > A `uuid` alone never authorises anything.
 *
 * `GET /bookings/{uuid}` and `POST /bookings/{uuid}/cancel` need either the
 * booking's own `manage_token` or a secret key, because the caller is claiming
 * to be **a specific guest** rather than a specific website — and a `pk_` sits
 * in the source of somebody's home page. A publishable key that could read a
 * booking by uuid would turn every uuid that ever appeared in a redirect URL,
 * an analytics payload or a browser history into a guest's name, phone number
 * and itinerary.
 *
 * ## `optional` is checkout, and its rule has a state in it
 *
 * §2.1 footnote 1: a `pk_` is accepted on `POST /bookings/{uuid}/checkout`
 * **only while the booking is still `draft` with an unexpired hold** — that is
 * the widget finishing the flow it started in the same session. Once the
 * booking is `confirmed` the guest is paying a balance, the caller is claiming
 * to be that guest, and a `manage_token` is required.
 *
 * That state check lives here rather than in the controller because it is an
 * *authentication* rule: whether this credential may act at all, not what it
 * may do. A controller that ran first would already have loaded the booking a
 * `pk_` is not entitled to see.
 *
 * ## The booking is resolved once and handed on
 *
 * Set on the request as `booking`, so the controller does not look it up again
 * — and, more importantly, cannot look it up *differently*. Two resolutions of
 * the same identifier is how an authorisation check ends up applying to a
 * different row than the action does.
 */
class AuthenticateGuestToken
{
    public const HEADER = 'X-Kaiki-Guest-Token';

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $uuid = (string) $request->route('uuid');

        $token = trim((string) $request->header(self::HEADER, ''));
        $apiKey = $request->attributes->get('api_key');

        $booking = $token === ''
            ? $this->bookingByUuid($uuid)
            : $this->bookingByToken($token, $uuid);

        if (! $booking instanceof Booking) {
            // One answer for "no such booking", "wrong token" and "the token is
            // for a different booking" — TOK-4's rule, which applies just as
            // much here as on the guest pages. A distinguishable 403 would let
            // somebody with a uuid learn whether it exists.
            return $this->notFound();
        }

        if ($token !== '') {
            $request->attributes->set('booking', $booking);

            return $next($request);
        }

        if (! $apiKey instanceof ApiKey) {
            return $this->notFound();
        }

        if (! $apiKey->type->isPublic()) {
            // A secret key is the operator acting on their own data, which
            // §2.1's table permits on all three endpoints.
            $request->attributes->set('booking', $booking);

            return $next($request);
        }

        if ($mode !== 'optional' || ! $this->isWidgetStillFinishingItsOwnFlow($booking)) {
            return $this->guestTokenRequired();
        }

        $request->attributes->set('booking', $booking);

        return $next($request);
    }

    /**
     * §2.1 footnote 1, in one method.
     *
     * `draft` **and** an unexpired hold. Either alone is not enough: a `draft`
     * whose hold ran out is a booking the widget's session no longer owns, and
     * "unexpired hold" without the status would also match `pending_payment`,
     * where the guest is already on a gateway page and a second session started
     * by a publishable key is a second charge waiting to happen.
     */
    private function isWidgetStillFinishingItsOwnFlow(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Draft && ! $booking->holdHasExpired();
    }

    private function bookingByUuid(string $uuid): ?Booking
    {
        if ($uuid === '') {
            return null;
        }

        // Tenant-scoped: the key has already resolved a tenant, and a uuid from
        // another operator's fleet must miss.
        return Booking::query()->where('uuid', $uuid)->first();
    }

    /**
     * The booking a `manage_token` names — and only if the path and the tenant agree.
     *
     * ## Tenant-scoped here, unscoped on the guest pages, and that is not an
     * inconsistency
     *
     * {@see GuestTokenResolver::booking()} looks a token up `withoutTenancy()`
     * because `/b/{token}` has **no tenant at all** — the token is what
     * resolves one. This request already has one: the API key resolved it
     * before this middleware ran.
     *
     * So the scoped query is both stricter and more correct. A token from
     * another operator's fleet would otherwise render **inside the wrong
     * tenant**, where the global scope hides its own product, its vessel and
     * its meeting point — a payload of nulls that looks like a bug in the
     * resource rather than a credential used in the wrong place.
     *
     * ## The path is checked too
     *
     * A valid token for booking A presented on booking B's path is refused
     * rather than silently answering about A. The caller asked about one
     * booking; showing them another is the shape of bug that turns a correct
     * credential into a wrong answer.
     */
    private function bookingByToken(string $token, string $uuid): ?Booking
    {
        $booking = Booking::query()->where('manage_token', $token)->first();

        if (! $booking instanceof Booking) {
            return null;
        }

        return $uuid === '' || $booking->uuid === $uuid ? $booking : null;
    }

    private function notFound(): Response
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.booking_not_found',
            code: 'not_found',
            status: SymfonyResponse::HTTP_NOT_FOUND,
        );
    }

    private function guestTokenRequired(): Response
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.guest_token_required',
            code: 'forbidden',
            status: SymfonyResponse::HTTP_FORBIDDEN,
            details: ['required_credential' => 'manage_token'],
        );
    }
}
