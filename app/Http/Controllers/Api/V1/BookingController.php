<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CreateBookingDraft;
use App\Domain\Booking\Actions\MintCheckoutSession;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\CheckoutRefused;
use App\Exceptions\HoldRefused;
use App\Exceptions\IllegalStateTransition;
use App\Http\Middleware\AuthenticateGuestToken;
use App\Http\Middleware\EnforceIdempotencyKey;
use App\Http\Requests\Api\V1\BookingCreateRequest;
use App\Http\Requests\Api\V1\CancelBookingRequest;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\Api\V1\BookingResource;
use App\Http\Resources\Api\V1\CancellationResultResource;
use App\Http\Resources\Api\V1\CheckoutSessionResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\Booking;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The four booking endpoints (`docs/api.md` §5).
 *
 * ## Thin, because CNV-5 says so and because these are the expensive ones
 *
 * Every method here resolves, delegates to an Action, and renders. The seat
 * arithmetic is `StartCheckout`'s, the refund arithmetic is `CancelBooking`'s,
 * and the hold is `CreateBookingDraft`'s — all three already used by the guest
 * pages (#86) and the panel, which is the point: a controller that reimplemented
 * any of them would be a second answer to a question the product must only
 * answer once.
 *
 * ## The booking is never resolved here
 *
 * {@see AuthenticateGuestToken} resolves it and puts it on
 * the request. That is not a convenience — it is what stops the credential
 * check and the action from applying to different rows. A controller that
 * looked the uuid up again would be a second resolution, and a second
 * resolution is how an authorisation check ends up guarding the wrong booking.
 *
 * ## `manage_token` leaves exactly once
 *
 * On the `201` from {@see self::store()}, through the single opt-in on
 * {@see BookingResource::withManageToken()}. §5: *"this is the one moment the
 * client can capture it. Always null on subsequent reads."*
 *
 * ## Idempotency is middleware, not code here
 *
 * `Idempotency-Key` is handled by {@see EnforceIdempotencyKey}
 * for all three writes at once. Hand-rolled per endpoint it would be three
 * dialects of the same guard, and the third would be missing the in-flight case.
 */
final class BookingController
{
    public function __construct(
        private readonly CreateBookingDraft $createDraft,
        private readonly MintCheckoutSession $mintSession,
        private readonly CancelBooking $cancelBooking,
    ) {}

    /** `POST /api/v1/bookings` — a draft, and a hold. */
    public function store(BookingCreateRequest $request): JsonResponse
    {
        $product = $request->product();

        if (! $product instanceof Product) {
            return ApiErrorResponse::fromKey(
                key: 'api.errors.product_not_found',
                code: 'not_found',
                status: SymfonyResponse::HTTP_NOT_FOUND,
            );
        }

        $departure = $request->departure();

        try {
            $booking = ($this->createDraft)($request->toData($product, $departure));
        } catch (CapacityExceeded $exceeded) {
            // §5: "two simultaneous requests for the last seat cannot both
            // succeed. The loser receives 409 insufficient_capacity."
            return ApiErrorResponse::fromKey(
                key: 'api.errors.insufficient_capacity',
                code: 'insufficient_capacity',
                status: SymfonyResponse::HTTP_CONFLICT,
            );
        } catch (HoldRefused $refused) {
            return ApiErrorResponse::make(
                code: $refused->reason,
                message: $refused->getMessage(),
                messageEl: $refused->getMessage(),
                status: SymfonyResponse::HTTP_CONFLICT,
            );
        }

        return BookingResource::make($booking->fresh($this->relations()))
            ->withManageToken()
            ->response()
            ->setStatusCode(SymfonyResponse::HTTP_CREATED)
            ->header('Location', route('guest.booking', ['token' => $booking->manage_token]))
            // §3.6: everything guest-specific. A booking must never sit in a
            // shared cache, and the token is in the body of this one.
            ->header('Cache-Control', 'no-store');
    }

    /** `GET /api/v1/bookings/{uuid}` — the first endpoint that refuses a `pk_`. */
    public function show(Request $request): JsonResponse
    {
        return BookingResource::make($this->booking($request)->fresh($this->relations()))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /** `POST /api/v1/bookings/{uuid}/checkout` — a gateway session. */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $booking = $this->booking($request);

        try {
            $result = ($this->mintSession)($booking, $request->kind(), $request->gateway());
        } catch (CheckoutRefused $refused) {
            return ApiErrorResponse::make(
                code: $refused->errorCode,
                message: (string) __('api.errors.' . $refused->errorCode, [], 'en'),
                messageEl: (string) __('api.errors.' . $refused->errorCode, [], 'el'),
                status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (CapacityExceeded $exceeded) {
            return ApiErrorResponse::fromKey(
                key: 'api.errors.insufficient_capacity',
                code: 'insufficient_capacity',
                status: SymfonyResponse::HTTP_CONFLICT,
            );
        } catch (IllegalStateTransition $transition) {
            return ApiErrorResponse::fromKey(
                key: 'api.errors.booking_not_payable',
                code: 'booking_not_payable',
                status: SymfonyResponse::HTTP_CONFLICT,
            );
        }

        if ($result['target'] === null) {
            // BKG-19: a voucher covered it entirely, so it is already
            // confirmed. The client gets the booking rather than a gateway URL
            // it would have nothing to do with.
            return BookingResource::make($result['booking']->fresh($this->relations()))
                ->response()
                ->header('Cache-Control', 'no-store');
        }

        return CheckoutSessionResource::make($result['payment'])
            ->withTarget($result['target'], $result['booking'])
            ->response()
            ->setStatusCode(SymfonyResponse::HTTP_CREATED)
            ->header('Cache-Control', 'no-store');
    }

    /** `POST /api/v1/bookings/{uuid}/cancel` — and its dry run. */
    public function cancel(CancelBookingRequest $request): JsonResponse
    {
        $booking = $this->booking($request);

        // Computed *before* anything moves, so the dry run and the real thing
        // are the same number from the same frozen snapshot — which is the one
        // thing a guest will check.
        $entitlement = RefundEntitlement::forCancellation($booking);

        if ($request->isDryRun()) {
            return CancellationResultResource::make($booking)
                ->withEntitlement($entitlement, performed: false)
                ->response()
                ->header('Cache-Control', 'no-store');
        }

        try {
            $booking = ($this->cancelBooking)(
                $booking,
                CancelReason::GuestRequest,
                CancelledBy::Guest,
            );
        } catch (IllegalStateTransition $transition) {
            return ApiErrorResponse::fromKey(
                key: 'api.errors.booking_not_cancellable',
                code: 'booking_not_cancellable',
                status: SymfonyResponse::HTTP_CONFLICT,
            );
        }

        return CancellationResultResource::make($booking->fresh($this->relations()))
            ->withEntitlement($entitlement, performed: true)
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The booking the middleware already resolved and authorised.
     *
     * It is always there — `AuthenticateGuestToken` 404s otherwise — but the
     * type has to be narrowed for anything downstream to be sure of it.
     */
    private function booking(Request $request): Booking
    {
        $booking = $request->attributes->get('booking');

        abort_unless($booking instanceof Booking, SymfonyResponse::HTTP_NOT_FOUND);

        return $booking;
    }

    /**
     * Everything {@see BookingResource} reads, loaded once.
     *
     * A booking payload touches the product, its meeting point, the vessel, the
     * departure and every manifest row; without this it is six queries per
     * response and the guest page renders one for each of a family's four
     * tickets.
     *
     * @return list<string>
     */
    private function relations(): array
    {
        return ['product.meetingPoint', 'vessel', 'departure', 'guests'];
    }
}
