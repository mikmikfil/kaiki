<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Availability\AvailabilityDayData;
use App\Data\Availability\VesselWindowData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Actions\CheckVesselAvailability;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Catalog\Queries\PublicProductQuery;
use App\Enums\BookingMode;
use App\Http\Requests\Api\V1\AvailabilityRequest;
use App\Http\Resources\Api\V1\AvailabilityDayResource;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /api/v1/availability` — the widget's call on every date change
 * (spec AVL-29, AVL-30, WGT-16, WGT-17, NFR-1, NFR-7; `docs/api.md` §5).
 *
 * Thin (CNV-5). The engine is {@see CheckSeatAvailability} and
 * {@see CheckVesselAvailability}, both built in #30 and #31; what is left here
 * is choosing which one the product's mode calls for and shaping the answer.
 *
 * ## The read path is advisory, and making it authoritative would be wrong
 *
 * ADR-0006, Option A: the seats reported here may be stale by the time the
 * guest posts. The authoritative check is the **locked** write path in M2, with
 * a conditional counter update that returns `409 insufficient_capacity`. Taking
 * a lock here to make the number exact would serialise the hottest read in the
 * product against every checkout — the ADR calls that out as explicitly the
 * wrong trade, and this docblock exists because this controller is where
 * somebody would be tempted.
 *
 * ADR-0005 is the other half and is already honoured inside the engine:
 * expired holds are filtered **lazily**, so a stale sweeper can never make a
 * seat look sold.
 *
 * ## Not paginated, capped instead
 *
 * §3.5: *"a client that receives half a calendar cannot render a month."* The
 * bound is the 62-day range (AVL-29), refused rather than truncated.
 *
 * ## `max-age=30`, and it is half the catalogue's for a reason
 *
 * §3.6 fixes it. Availability is the one payload that goes stale because
 * somebody else bought a seat, so it carries the shortest cache window of any
 * read — and no ETag: computing one would mean building the whole response to
 * discover it changed, which for this endpoint it usually has.
 */
final class AvailabilityController
{
    public function __invoke(
        AvailabilityRequest $request,
        CheckSeatAvailability $seats,
        CheckVesselAvailability $vessels,
    ): JsonResponse {
        $product = PublicProductQuery::findForAvailability($request->productIdentifier());

        if (! $product instanceof Product) {
            // Indistinguishable from another tenant's product, per SEC-1 and
            // SEC-2 — the same rule `GET /products/{uuid}` follows.
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        $range = $request->range($product);

        $days = match ($product->mode) {
            BookingMode::PerSeat => array_map(
                static fn (AvailabilityDayData $day): array => AvailabilityDayResource::perSeat(
                    $day->localDate,
                    $day->departures,
                    $product,
                ),
                $seats($product, $range),
            ),
            BookingMode::PerVessel => array_map(
                static fn (VesselWindowData $window): array => AvailabilityDayResource::perVessel($window, $product),
                $vessels($product, $range),
            ),
            // BKG-24: a `quote` product is sold by the operator answering an
            // enquiry. Every date is `on_request` and carries no price — the
            // engine is not consulted at all, because there is nothing it could
            // usefully say.
            BookingMode::Quote => array_map(
                static fn ($date): array => AvailabilityDayResource::onRequest($date->toDateString()),
                $range->dates(),
            ),
        };

        return (new JsonResponse([
            'data' => $days,
            'meta' => $this->meta($product, $request, $range->from->toDateString(), $range->to->toDateString()),
        ]))->withHeaders([
            'Cache-Control' => 'public, max-age=' . (int) config('kaiki.availability.api.cache_ttl_seconds', 30),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Product $product, AvailabilityRequest $request, string $from, string $to): array
    {
        // The same projection `GET /products/{uuid}` publishes as
        // `booking_window`: the strictest lead time and advance window across
        // the product's active plans. Repeated here because a calendar needs it
        // to grey out dates before it has fetched the product.
        //
        // The **loaded** relation, not a fresh query: `findForAvailability()`
        // has already narrowed it to the active plans, and `->ratePlans()->get()`
        // here would spend one of NFR-7's five queries re-fetching rows the
        // engine is holding.
        $plans = $product->ratePlans;

        $advance = $plans
            ->map(static fn ($plan): ?int => $plan->max_advance_days)
            ->reject(static fn (?int $days): bool => $days === null);

        return [
            'product_uuid' => $product->uuid,
            'mode' => $product->mode->value,
            'timezone' => LocalDateTimeResolver::timezone(),
            'currency' => MoneyFormatter::currency(),
            'from' => $from,
            'to' => $to,
            'pax' => $request->pax(),
            'min_lead_time_hours' => $plans->isEmpty()
                ? null
                : (int) $plans->max(static fn ($plan): int => $plan->min_lead_time_hours),
            'max_advance_days' => $advance->isEmpty() ? null : (int) $advance->min(),
        ];
    }
}
