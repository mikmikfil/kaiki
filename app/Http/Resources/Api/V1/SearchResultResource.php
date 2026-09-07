<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Data\Catalog\SearchResultData;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit (`docs/api.md`, `SearchResult`; #105).
 *
 * The product is the ordinary `ProductSummary` — the same shape the list mount
 * and the WordPress plugin already render, so a client that can draw a card can
 * draw a search result. What is added is the pair a from-price grid cannot give:
 * what **this** party pays, and when the next sailing leaves.
 *
 * `from_price_cents` is still in the nested summary and is still the from-price.
 * The two coexisting is deliberate: a card can show "from €65 · €180 for four"
 * and both numbers are true. Dropping the from-price would break the shared
 * summary schema for one endpoint's benefit.
 */
final class SearchResultResource extends JsonResource
{
    /** @var SearchResultData */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $price = $result->partyPriceCents;

        return [
            'product' => (new ProductListResource($result->product))->resolve($request),
            'availability' => $result->availability,
            'party_price_cents' => $price,
            'party_price_formatted' => $price !== null
                ? MoneyFormatter::format($price, app()->getLocale(), MoneyFormatter::currency())
                : null,
            'next_departure' => $this->departure(),
        ];
    }

    /**
     * The soonest sailing this party fits into, or null.
     *
     * Null for a charter, whose day is a window rather than a departure, and for
     * an on-request product, which has neither. The times are the operator's own
     * wall clock with its offset — a client rendering `18:30` without knowing
     * where has no way to turn it into an instant.
     *
     * @return array<string, mixed>|null
     */
    private function departure(): ?array
    {
        $departure = $this->resource->nextDeparture;

        if ($departure === null) {
            return null;
        }

        // The **tenant's** zone, not the server's. `app.timezone` is UTC by
        // convention (ENV-2) and a client shown `15:30Z` for a boat leaving at
        // 18:30 has been told the wrong time in a technically true way.
        $localStart = $departure->starts_at_utc->copy()->setTimezone(LocalDateTimeResolver::timezone());

        return [
            'uuid' => $departure->uuid,
            'starts_at' => $localStart->toIso8601String(),
            'local_date' => $departure->local_date->toDateString(),
            'local_time' => substr((string) $departure->local_time, 0, 5),
            // Advisory, exactly as on `GET /availability` (ADR-0006): holds count
            // against it, and an expired hold is treated as released on read.
            'seats_available' => $departure->seatsAvailable(),
        ];
    }
}
