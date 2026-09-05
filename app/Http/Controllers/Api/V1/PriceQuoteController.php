<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\PartyGuard;
use App\Domain\Catalog\Queries\PublicProductQuery;
use App\Domain\Catalog\Support\OfferedExtrasResolver;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Enums\BookingMode;
use App\Http\Requests\Api\V1\PriceQuoteRequest;
use App\Http\Resources\Api\V1\PriceQuoteResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\Departure;
use App\Models\Extra;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `POST /api/v1/price-quote` — the only way a client learns a price
 * (spec PRC-1, PRC-15, PRC-16, WGT-13; `docs/api.md` §5).
 *
 * ## A POST that changes nothing
 *
 * It is a POST because the inputs are a structured body, not because anything
 * happens. The contract is explicit: *"no side effects: it does not hold a
 * seat, does not create a booking and does not redeem a voucher. It is safe to
 * call on every pax change."*
 *
 * Two consequences are wired into the route rather than left to reading:
 *
 * - It is throttled as **class C, pricing** (`api-pricing`, §3.6), not as a
 *   booking write. A calendar fires one of these per pax change, and putting it
 *   in the write bucket would throttle a browsing guest at sixty requests.
 * - `tenant.writable` is **not** applied. SAA-7: a lapsed subscription closes
 *   bookings, not prices. `CheckSeatAvailability` still reports
 *   `tenant_read_only` on the availability endpoint, which is where a guest
 *   finds out they cannot book — showing a price and refusing the booking is
 *   the right order, because the operator's own website keeps working.
 *
 * ## The party is judged by the same rules the calendar used
 *
 * {@see PartyGuard}, extracted in this issue from `CheckSeatAvailability`. A
 * party the calendar offered and the quote refuses — or the reverse — is the
 * site contradicting itself in front of a guest, and two implementations of
 * "does this family fit" is how that happens.
 *
 * The date rules are deliberately **not** re-checked here. A quote is a
 * calculation, not a reservation (ADR-0006): whether that departure is still
 * bookable in ninety seconds is the locked write path's question in M2, and
 * answering it here would cost the range queries and still be stale.
 *
 * ## `no-store`
 *
 * §3.6 puts everything guest-specific there. A quote is a party's price, and a
 * shared cache holding one family's total against a URL another family will
 * request is the worst possible caching bug.
 */
final class PriceQuoteController
{
    public function __invoke(
        PriceQuoteRequest $request,
        ComputePrice $price,
        PartyGuard $party,
    ): JsonResponse {
        $product = PublicProductQuery::findForAvailability($request->productIdentifier());

        if (! $product instanceof Product) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        if ($product->mode === BookingMode::Quote) {
            // BKG-24 and the contract's own `PriceQuote.mode` enum, which has
            // no `quote` case: an operator quotes these by hand, so there is no
            // number this endpoint could honestly return.
            return $this->refuse('pricing.quote.validation.not_quotable', 'product_uuid');
        }

        $departure = $this->departure($request, $product);
        $date = $this->date($request, $product, $departure);

        if ($date === null) {
            return $this->refuse(
                $product->mode === BookingMode::PerSeat
                    ? 'pricing.quote.validation.departure_required'
                    : 'pricing.quote.validation.window_required',
                $product->mode === BookingMode::PerSeat ? 'departure_uuid' : 'window',
            );
        }

        $pax = $request->paxByCode($product);

        if (($rejection = $party->check($product->ageBands, $pax, $product, $departure)) !== null) {
            // AC of #37: the machine code the widget branches on, and the
            // sentence beside it in the negotiated locale. `AvailabilityRejection`
            // owns both so this endpoint and `GET /availability` cannot drift
            // into describing the same refusal two ways.
            return ApiErrorResponse::make(
                code: $rejection->value,
                message: $rejection->labelIn('en'),
                messageEl: $rejection->labelIn('el'),
                status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
                details: ['reason' => $rejection->value],
            );
        }

        // `ComputePrice` refuses a date with no resolvable rate plan (PRC-5) and
        // `ExtraLineBuilder` refuses a quantity over an extra's maximum
        // (PRC-10), both by throwing `ValidationException`. Not caught here:
        // `ApiExceptionRenderer` already turns one into the `422
        // validation_failed` envelope with the offending field named, and a
        // catch that re-threw would be a comment pretending to be code.
        $quote = $price(
            $product,
            $date,
            $pax,
            $request->extraQuantities($this->offeredExtras($product)),
            $this->extraHours($request, $product),
        );

        return (new JsonResponse([
            'data' => PriceQuoteResource::toArray(
                $quote,
                $product,
                $departure,
                $this->window($request, $product, $departure),
            ),
        ]))->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * The departure being priced, for `per_seat`.
     *
     * Scoped by product as well as by tenant: a uuid belonging to another of
     * this operator's trips would otherwise price the wrong thing at the right
     * operator, which no tenant scope catches.
     */
    private function departure(PriceQuoteRequest $request, Product $product): ?Departure
    {
        $uuid = $request->departureUuid();

        if ($uuid === null || $product->mode !== BookingMode::PerSeat) {
            return null;
        }

        /** @var Departure|null $departure */
        $departure = Departure::query()
            ->where('product_id', $product->getKey())
            ->where('uuid', $uuid)
            ->first();

        return $departure;
    }

    /**
     * The date the price is resolved against.
     *
     * The departure's own local date for `per_seat` — never the client's idea
     * of it, which is how a guest ends up quoted a low-season price for a
     * high-season sailing. The window's `local_date` for `per_vessel`, where
     * there is no departure to read it from.
     */
    private function date(PriceQuoteRequest $request, Product $product, ?Departure $departure): ?Carbon
    {
        if ($product->mode === BookingMode::PerSeat) {
            return $departure?->local_date;
        }

        $window = $request->window();
        $date = $window['local_date'] ?? null;

        return is_string($date) && $date !== '' ? Carbon::parse($date) : null;
    }

    /**
     * PRC-8: hours beyond the product's own duration, for a flexible charter.
     *
     * Floored at zero and only for a product that actually prices them, so a
     * shorter-than-standard `duration_minutes` cannot become a negative
     * extension and a discount.
     */
    private function extraHours(PriceQuoteRequest $request, Product $product): int
    {
        if (! $product->flexible_start) {
            return 0;
        }

        $requested = $request->window()['duration_minutes'] ?? null;

        if (! is_numeric($requested)) {
            return 0;
        }

        return max(0, (int) ceil(((int) $requested - $product->duration_minutes) / 60));
    }

    /**
     * The charter window echoed back, in the contract's `LocalWindow` shape.
     *
     * @return array<string, mixed>|null
     */
    private function window(PriceQuoteRequest $request, Product $product, ?Departure $departure): ?array
    {
        if ($product->mode !== BookingMode::PerVessel || $departure instanceof Departure) {
            return null;
        }

        $window = $request->window();

        if ($window === null) {
            return null;
        }

        $time = is_string($window['local_time'] ?? null)
            ? $window['local_time']
            : substr((string) $product->default_start_time, 0, 5);

        return [
            'local_date' => (string) $window['local_date'],
            'local_time' => $time,
            // The instants stay null here rather than being derived from the
            // date and the wall clock: this endpoint prices a window, it does
            // not schedule one, and `GET /availability` is what says which
            // windows exist and what their UTC bounds are. Inventing an instant
            // would be doing ADR-0016's conversion in the one place that has no
            // reason to.
            'starts_at' => null,
            'ends_at' => null,
            'timezone' => LocalDateTimeResolver::timezone(),
            'dst_ambiguous' => false,
        ];
    }

    /** @return list<Extra> */
    private function offeredExtras(Product $product): array
    {
        $ids = OfferedExtrasResolver::forProduct($product)->pluck('extraId')->all();

        return Extra::query()->whereIn('id', $ids)->get()->all();
    }

    private function refuse(string $key, string $field): JsonResponse
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.validation_failed',
            code: 'validation_failed',
            status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
            details: ['fields' => [$field => [(string) __($key)]]],
        );
    }
}
