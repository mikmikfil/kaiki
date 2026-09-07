<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Queries\PublicProductQuery;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\Api\V1\ProductDetailResource;
use App\Http\Resources\Api\V1\ProductListResource;
use App\Http\Responses\ConditionalJson;
use App\Models\Product;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /api/v1/products` and `GET /api/v1/products/{uuid}` — the hottest
 * catalogue reads (spec WGT-5, WGT-13, HOS-1, ARC-5, SEC-2, NFR-4, NFR-6;
 * `docs/api.md` §5).
 *
 * Thin (CNV-5). {@see PublicProductQuery} decides what a guest may see and the
 * resources decide what it looks like; what is left here is the HTTP layer —
 * pagination envelope, ETag, cache headers, 404.
 *
 * ## A cross-tenant uuid is 404, never 403
 *
 * SEC-1 and SEC-2. `BelongsToTenant` scopes the query, so another operator's
 * product simply is not there and the two cases become indistinguishable
 * without any code deciding to hide one — which is the point. A 403 would
 * confirm the product exists and belongs to somebody else, and a uuid is
 * guessable enough that confirming it is a real leak.
 *
 * The same holds for a `draft`, `inactive` or `archived` product: `sellable()`
 * removes it from the query rather than the controller checking the status
 * after loading, so there is no branch that can be forgotten.
 *
 * ## A read-only tenant is still served
 *
 * SAA-7. `tenant.writable` is not on these routes and must not be: a lapsed
 * subscription closes *bookings*, not the catalogue. An operator whose card
 * expired should still have their trips visible on their own website while they
 * sort it out.
 */
final class ProductController
{
    public function index(ProductIndexRequest $request): SymfonyResponse
    {
        // Called for its refusal as much as for its value: a malformed cursor
        // is `400 invalid_cursor` here rather than a silent page one further
        // down (§3.5).
        $request->cursor();

        $page = PublicProductQuery::page(
            perPage: $request->perPage(),
            categories: $request->categories(),
            mode: $request->mode(),
            vesselUuid: $request->vesselUuid(),
        );

        $data = ProductListResource::collection($page->items())->resolve($request);

        return $this->cacheable($request, [
            'data' => $data,
            'pagination' => $this->pagination($request, $page),
        ]);
    }

    public function show(Request $request, string $uuid): SymfonyResponse
    {
        $product = PublicProductQuery::find($uuid);

        if (! $product instanceof Product) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $this->cacheable($request, [
            'data' => (new ProductDetailResource($product))->resolve($request),
        ]);
    }

    /**
     * The §3.5 envelope.
     *
     * **No total count, deliberately.** Counting is a second query on every
     * page for a number nobody renders. `has_more` is authoritative and
     * `next_cursor` is null exactly when it is false — the contract states the
     * relationship, so it is derived from one value here rather than computed
     * twice.
     *
     * `next_url` keeps the caller's own query string so that a client paging
     * through `?category=sunset` does not silently widen to the whole catalogue
     * on page two.
     *
     * @param  CursorPaginator<int, Product>  $page
     * @return array<string, mixed>
     */
    private function pagination(Request $request, CursorPaginator $page): array
    {
        $next = $page->nextCursor()?->encode();

        return [
            'per_page' => $page->perPage(),
            'has_more' => $next !== null,
            'next_cursor' => $next,
            'prev_cursor' => $page->previousCursor()?->encode(),
            'next_url' => $next === null
                ? null
                : $request->fullUrlWithQuery(['cursor' => $next]),
        ];
    }

    /**
     * The response, with the conditional-request machinery §3.7 promises.
     *
     * {@see ConditionalJson} computes the ETag from the payload and answers the
     * conditional request; all this endpoint adds is its own `max-age`, which
     * §3.6 fixes at 60 seconds for the catalogue class.
     *
     * @param  array<string, mixed>  $payload
     */
    private function cacheable(Request $request, array $payload): SymfonyResponse
    {
        return ConditionalJson::respond($request, $payload, [
            'Cache-Control' => 'public, max-age=' . (int) config('kaiki.catalog.api.cache_ttl_seconds', 60),
        ]);
    }
}
