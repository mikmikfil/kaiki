<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\Actions\SubmitEnquiry;
use App\Http\Requests\Api\V1\EnquiryCreateRequest;
use App\Http\Resources\Api\V1\EnquiryResource;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `POST /api/v1/enquiries` — "ask a question"
 * (spec BKG-28 FIXED, BKG-29; `docs/api.md` §5).
 *
 * ## The most spam-exposed endpoint in the system, and it is sized for that
 *
 * §3.6 class **F**: 120 per key and **5 per IP** — the tightest per-IP number
 * in the contract, and by a factor of four. The honeypot and the timing check
 * in {@see EnquiryCreateRequest} are cheap filters on top of it, and neither is
 * mistaken for security: they stop scripts that fill every field they find,
 * which is most of the traffic, and stop nobody who is trying.
 *
 * ## A rejection creates nothing and notifies nobody
 *
 * `docs/api.md`: *"A filled value is answered `422 enquiry_rejected` with no row
 * created and no notification sent."* Both halves matter. A row would make the
 * operator's inbox the spam target instead of the endpoint; a notification
 * would make it their phone.
 *
 * The message is deliberately not "you failed our bot check". It says the
 * message could not be sent and invites a retry, because the one person this
 * ever refuses wrongly is a human on a fast connection, and they need a way
 * forward rather than an accusation.
 *
 * ## It never touches the availability engine
 *
 * BKG-28, and `EnquiryEndpointTest` counts the queries to prove it. An enquiry
 * names a product and a preferred date, and checking whether that date is free
 * is one helpful line away — which would put this endpoint on the hot path of
 * the engine and make a spam run an availability load test.
 */
final class EnquiryController
{
    public function __construct(private readonly SubmitEnquiry $submit) {}

    public function __invoke(EnquiryCreateRequest $request): JsonResponse
    {
        if ($request->looksAutomated()) {
            // The honeypot is refused by validation (`max:0`); this is the
            // other filter. Same code and same message, because a client that
            // knows *which* check it failed knows which one to work around.
            return $this->reject();
        }

        $enquiry = ($this->submit)($request->toData());

        return EnquiryResource::make($enquiry)
            ->response()
            ->setStatusCode(SymfonyResponse::HTTP_CREATED)
            // §3.6 puts everything guest-specific here. Nothing about a
            // stranger's message belongs in a shared cache.
            ->header('Cache-Control', 'no-store');
    }

    private function reject(): JsonResponse
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.enquiry_rejected',
            code: 'enquiry_rejected',
            status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
