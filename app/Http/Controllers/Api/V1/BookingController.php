<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\Support\BookingCutoff;
use App\Domain\Availability\Support\CountedSeats;
use App\Domain\Availability\Support\PartyGuard;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CreateBookingDraft;
use App\Domain\Booking\Actions\MintCheckoutSession;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Payments\Actions\ReconcilePendingPayments;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentStatus;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\CheckoutRefused;
use App\Exceptions\DiscountCodeRefused;
use App\Exceptions\HoldRefused;
use App\Exceptions\IllegalStateTransition;
use App\Exceptions\PartyRefused;
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
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

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
    public function store(BookingCreateRequest $request, PartyGuard $party): JsonResponse
    {
        $product = $request->product();

        if (! $product instanceof Product) {
            return ApiErrorResponse::fromKey(
                key: 'api.errors.product_not_found',
                code: 'not_found',
                status: SymfonyResponse::HTTP_NOT_FOUND,
            );
        }

        $departure = $request->departure($product);

        // A named sailing that is not this trip's (another trip, another
        // operator, or gone) is refused, never replaced by the day's first
        // sailing (2026-09-25). Its status is `CreateBookingDraft`'s to check.
        if ($departure === null && $request->hasDepartureUuid()) {
            return self::holdRefused(HoldRefused::departureUnavailable());
        }

        // AVL-26 and AVL-26b, in the same words `GET /availability` and
        // `POST /price-quote` use. Only the party rules: seats and the boat's
        // certificate are `HoldSeats`' answer below, and §5 owes a **409** for
        // those, not a 422 — asking twice here would be a second answer with
        // the wrong status attached.
        //
        // Checked at all because a widget is under nobody's control: the
        // calendar greys a party out and the quote refuses it, and neither
        // stops a client posting it anyway. Until this, `requires_adult` was a
        // promise the trip form made and the checkout never kept.
        $data = $request->toData($product, $departure);

        // AVL-19 and AVL-20 (2026-09-25), by the rule the calendar and the
        // quote use, so a date the calendar calls past cannot become a draft.
        // `CreateManualBooking` and imports do not come through here, which is
        // BKG-32's operator override kept.
        $cutoff = BookingCutoff::forRequest($product, $departure, $data->date, $data->startTime, $data->extraHours);

        if ($cutoff !== null) {
            return ApiErrorResponse::make(
                code: $cutoff->value,
                message: $cutoff->labelIn('en'),
                messageEl: $cutoff->labelIn('el'),
                status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
                details: ['reason' => $cutoff->value],
            );
        }

        $pax = CountedSeats::sanitise($product->ageBands, $request->paxByCode($product));

        if (($rejection = $party->blanket($product->ageBands, $pax)) !== null) {
            return ApiErrorResponse::make(
                code: $rejection->value,
                message: $rejection->labelIn('en'),
                messageEl: $rejection->labelIn('el'),
                status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
                details: ['reason' => $rejection->value],
            );
        }

        try {
            $booking = ($this->createDraft)($data);
        } catch (CapacityExceeded $exceeded) {
            // §5: "two simultaneous requests for the last seat cannot both
            // succeed. The loser receives 409 insufficient_capacity."
            return ApiErrorResponse::fromKey(
                key: 'api.errors.insufficient_capacity',
                code: 'insufficient_capacity',
                status: SymfonyResponse::HTTP_CONFLICT,
            );
        } catch (HoldRefused $refused) {
            return self::holdRefused($refused);
        } catch (PartyRefused $refused) {
            // Too few, too many, or more people than the boat's certificate
            // (2026-09-25): the quote's code and sentence, in both languages.
            return ApiErrorResponse::make(
                code: $refused->rejection->value,
                message: $refused->messageIn('en'),
                messageEl: $refused->messageIn('el'),
                status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
                details: ['reason' => $refused->rejection->value],
            );
        } catch (DiscountCodeRefused $refused) {
            // «Κουπόνι» (2026-09-17). The sentence is already in the booking's
            // language; the widget shows it under the code field.
            return ApiErrorResponse::make(
                code: 'invalid_discount_code',
                message: $refused->getMessage(),
                messageEl: $refused->getMessage(),
                status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
                details: ['fields' => ['discount_code' => [$refused->getMessage()]]],
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
        $booking = $this->booking($request);

        $this->settlePendingPayment($booking);

        return BookingResource::make($booking->fresh($this->relations()))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Ask the gateway, when a guest is waiting on the answer.
     *
     * `pending_payment` means two opposite things — the money is on its way and
     * the webhook has not landed, or the guest left the gateway's page without
     * paying — and the widget has to pick one of two sentences. It cannot tell
     * them apart and neither can this application: only the gateway knows. So it
     * is asked here, on the read the widget is already making, rather than up to
     * five minutes later when the scheduled sweep comes round. `docs/api.md` item
     * 12's re-fetch, at the moment somebody needs it.
     *
     * Bounded on both sides. **A minute old**, so the ordinary case — a webhook
     * arriving a second after the redirect — is never overtaken by an outbound
     * call nobody needed. **Once a minute per booking**, through a lock, so a
     * widget polling every few seconds does not turn into a request per poll
     * against somebody else's API. Failure is silent by design: this is an
     * optimisation of the answer, and a gateway that is down must not turn a
     * booking read into an error.
     */
    private function settlePendingPayment(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::PendingPayment) {
            return;
        }

        $payment = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
            ->whereNotNull('gateway_ref')
            ->where('created_at', '<=', now()->subMinute())
            ->latest('id')
            ->first();

        if (! $payment instanceof Payment) {
            return;
        }

        $lock = Cache::lock('kaiki:payments:settle:' . $booking->getKey(), 60);

        if (! $lock->get()) {
            return;
        }

        try {
            app(ReconcilePendingPayments::class)->forPayment($payment);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** `POST /api/v1/bookings/{uuid}/checkout` — a gateway session. */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $booking = $this->booking($request);

        try {
            $result = ($this->mintSession)($booking, $request->kind(), $request->gateway(), $request->returnUrl());
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
        } catch (HoldRefused $refused) {
            // A charter whose hold lapsed while somebody else took the boat
            // (2026-09-25), refused at the line before money.
            return self::holdRefused($refused);
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

    /**
     * §5's 409 for a hold that could not be taken.
     *
     * The sentence in both languages from the lang files for a boat already
     * taken (2026-09-25), which the widget shows as it comes; the older
     * refusals keep the one sentence they have always carried.
     */
    private static function holdRefused(HoldRefused $refused): JsonResponse
    {
        // Refusals that have a fixed sentence, in both languages. The sailing
        // being gone is a 409 like the boat being taken; a day with two
        // sailings and no time is the request's to fix, so a 422 (2026-09-25).
        $bilingual = [
            'vessel_unavailable' => SymfonyResponse::HTTP_CONFLICT,
            'departure_unavailable' => SymfonyResponse::HTTP_CONFLICT,
            'product_unavailable' => SymfonyResponse::HTTP_CONFLICT,
            'departure_time_required' => SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
            // AVL-25 under the departure lock: more people than the boat's
            // certificate, infants counted. Fewer people is the remedy.
            'legal_capacity' => SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
        ];

        if (isset($bilingual[$refused->reason])) {
            return ApiErrorResponse::make(
                code: $refused->reason,
                message: (string) __('booking.hold.' . $refused->reason, [], 'en'),
                messageEl: (string) __('booking.hold.' . $refused->reason, [], 'el'),
                status: $bilingual[$refused->reason],
                details: ['reason' => $refused->reason],
            );
        }

        return ApiErrorResponse::make(
            code: $refused->reason,
            message: $refused->getMessage(),
            messageEl: $refused->getMessage(),
            status: SymfonyResponse::HTTP_CONFLICT,
        );
    }
}
