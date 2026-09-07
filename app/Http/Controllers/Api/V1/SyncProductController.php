<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Queries\CatalogueSyncQuery;
use App\Http\Requests\Api\V1\SyncProductsRequest;
use App\Http\Resources\Api\V1\ProductSyncResource;
use App\Http\Responses\ConditionalJson;
use App\Models\Product;
use App\Support\Tenancy;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /api/v1/sync/products` — the server-to-server catalogue feed
 * (spec WPP-3, WPP-6; `docs/api.md` §5).
 *
 * The one endpoint in v1 that requires a secret key, and the one that returns
 * translations unresolved. Both follow from its single consumer: the WordPress
 * plugin's `kaiki_trip` sync, which runs in WP-Cron and in the inbound webhook
 * handler, never in a browser, and which creates one post per WPML/Polylang
 * language and so needs `el` and `en` in the same response.
 *
 * ## `no-store`, on a read
 *
 * §3.7. Everything else cacheable in this API is public catalogue data; this
 * payload includes an operator's unpublished drafts and their internal SEO
 * fields, and a shared cache holding that is the same disclosure the `sk_`
 * requirement exists to prevent. The `ETag` still works — a validator is not a
 * cache — and is how the plugin's poll costs a hash instead of a catalogue.
 *
 * ## The cursor in `meta` is not the cursor in `pagination`
 *
 * They answer different questions and a client needs both. `pagination.next_cursor`
 * walks *this* traversal — keep calling with it until `has_more` is false.
 * `meta.sync_cursor` is where the *next run* starts, days later, and is a
 * timestamp rather than an opaque cursor precisely because it has to survive
 * being stored in a WordPress option and used against a catalogue that has
 * changed underneath it.
 */
final class SyncProductController
{
    public function __invoke(SyncProductsRequest $request): SymfonyResponse
    {
        // Called for their refusals as much as for their values: a malformed
        // cursor and an unparseable `updated_since` are both 400s here rather
        // than a silently wrong page further down.
        $request->cursor();
        $since = $request->updatedSince();

        $page = CatalogueSyncQuery::page(
            perPage: $request->perPage(),
            since: $since,
            includeInactive: $request->includeInactive(),
        );

        $tenant = Tenancy::current();

        /** @var list<string> $locales */
        $locales = $tenant?->supported_locales ?: [(string) config('app.fallback_locale', 'en')];

        $data = array_map(
            fn (Product $product): array => (new ProductSyncResource($product, $locales))->resolve($request),
            $page->items(),
        );

        return ConditionalJson::respond($request, [
            'data' => $data,
            'pagination' => $this->pagination($request, $page),
            'meta' => [
                'sync_cursor' => $this->syncCursor($page, $since),
                'timezone' => $tenant?->timezone,
                'default_locale' => $tenant?->default_locale,
                'locales' => $locales,
            ],
        ], ['Cache-Control' => 'no-store']);
    }

    /**
     * The §3.5 envelope, identical in shape to every other paginated read.
     *
     * @param  CursorPaginator<int, Product>  $page
     * @return array<string, mixed>
     */
    private function pagination(SyncProductsRequest $request, CursorPaginator $page): array
    {
        $next = $page->nextCursor()?->encode();

        return [
            'per_page' => $page->perPage(),
            'has_more' => $next !== null,
            'next_cursor' => $next,
            'prev_cursor' => $page->previousCursor()?->encode(),
            'next_url' => $next === null ? null : $request->fullUrlWithQuery(['cursor' => $next]),
        ];
    }

    /**
     * Where the next run starts: the newest `updated_at` on this page.
     *
     * **Not `now()`**, which is the obvious implementation and the one that
     * loses rows. A product saved between the query running and the response
     * being written has an `updated_at` earlier than `now()` and is not on this
     * page; a cursor of `now()` would step straight over it and the mirror would
     * never see that edit again. The newest row we actually sent cannot skip
     * anything, because the boundary is inclusive.
     *
     * An empty page keeps the caller's own cursor rather than inventing one —
     * "nothing changed" must not move the mark.
     *
     * @param  CursorPaginator<int, Product>  $page
     */
    private function syncCursor(CursorPaginator $page, ?Carbon $since): ?string
    {
        $newest = null;

        foreach ($page->items() as $product) {
            $at = $product->updated_at;

            if ($at !== null && ($newest === null || $at->greaterThan($newest))) {
                $newest = $at;
            }
        }

        return $newest?->toIso8601ZuluString() ?? $since?->toIso8601ZuluString();
    }
}
