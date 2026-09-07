<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\Actions\SearchCatalogue;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Http\Resources\Api\V1\SearchResultResource;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/search` — one operator's catalogue for a date and a party (#105).
 *
 * Thin (CNV-5). {@see SearchCatalogue} is the engine, {@see SearchRequest}
 * applies the operator's filter settings before anything can honour a value they
 * switched off, and what is left here is the envelope.
 *
 * ## Not paginated
 *
 * An operator's catalogue is a handful of trips — `docs/data-model.md` sizes a
 * busy one at a couple of dozen — and a search that returned half of them would
 * make a client page through a result set the guest is comparing at a glance.
 * The bound is the catalogue itself, which is why there is no cursor here and no
 * `pagination` block in the contract.
 *
 * ## `max-age=30`, the availability window rather than the catalogue's
 *
 * §3.6. The payload embeds seat counts, which go stale because somebody else
 * bought one — so it caches like `/availability` and not like `/products`.
 */
final class SearchController
{
    public function __invoke(SearchRequest $request, SearchCatalogue $search): JsonResponse
    {
        $criteria = $request->criteria();
        $results = $search($criteria);

        return (new JsonResponse([
            'data' => SearchResultResource::collection($results)->resolve($request),
            'meta' => [
                'date' => $criteria->date->toDateString(),
                'pax' => $criteria->pax,
                'currency' => MoneyFormatter::currency(),
                'timezone' => LocalDateTimeResolver::timezone(),
                // What the operator exposes, and what actually shaped this
                // answer. A guest whose `?vessel=` was dropped can see that it
                // was, rather than concluding the filter is broken.
                'filters_enabled' => $criteria->filtersEnabled,
                'applied' => $criteria->applied,
            ],
        ]))->withHeaders([
            'Cache-Control' => 'public, max-age=' . (int) config('kaiki.availability.api.cache_ttl_seconds', 30),
        ]);
    }
}
