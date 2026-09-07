<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Models\Product;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The catalogue as a **server** may read it (spec WPP-6; `docs/api.md`
 * §5 `GET /api/v1/sync/products`).
 *
 * The deliberate opposite of {@see PublicProductQuery}. That one answers "what
 * may a guest see"; this one answers "what does a mirror of this catalogue need
 * to stay correct", and the difference is everything a guest must never be
 * shown: `draft`, `inactive`, `archived` and soft-deleted rows.
 *
 * A separate class rather than a flag on the public query, because the two
 * would then share a code path where the only thing standing between a
 * publishable key and the whole catalogue is a boolean. The endpoint requires
 * an `sk_` for exactly this reason (ADR-0013 Option A, §2.2), and the query
 * that ignores `sellable()` should be reachable from one route.
 *
 * ## Order is the contract, and the tiebreaker is why it works
 *
 * `updated_at ASC, uuid ASC` (§3.5). A client stores `meta.sync_cursor` and
 * passes it back as `updated_since` on the next run, so the order has to be the
 * one the cursor is expressed in. `uuid` is the tiebreaker because a bulk
 * update stamps a hundred rows with the same `updated_at` to the second — and a
 * cursor over a non-unique key either loops or skips, which for a sync means a
 * silently half-mirrored catalogue that reports a clean run.
 *
 * ## `updated_since` is inclusive
 *
 * `>=`, not `>`. Excluding the boundary would drop every row sharing the last
 * page's final timestamp, which is precisely the bulk-update case above. The
 * cost is that the last page's rows are re-sent on the next run; the plugin
 * skips them by `content_hash` without writing anything, and re-sending a row
 * is cheap in a way that losing one is not.
 */
final class CatalogueSyncQuery
{
    /**
     * Everything the sync payload renders, in one load.
     *
     * NFR-6: a hundred products per page is a hundred vessels and a hundred
     * meeting points, and a resource reaching for `$product->vessel` without
     * them is two hundred extra queries on a job nobody watches.
     *
     * @var list<string>
     */
    private const RELATIONS = ['vessel', 'meetingPoint', 'ageBands', 'cancellationPolicy.tiers'];

    /**
     * @return CursorPaginator<int, Product>
     */
    public static function page(int $perPage, ?Carbon $since = null, bool $includeInactive = true): CursorPaginator
    {
        // Soft-deleted rows are the whole reason a tombstone exists: a product
        // that simply vanishes from the feed leaves the mirror's page published
        // and indexed for ever, and the client has no way to tell "deleted"
        // from "not on this page".
        $query = Product::query()
            ->withTrashed()
            ->with(self::RELATIONS);

        if (! $includeInactive) {
            // A tombstone still travels: unpublishing is not something a client
            // can opt out of and stay correct.
            $query->where(fn (Builder $q): Builder => $q
                ->where('status', 'active')
                ->orWhereNotNull('deleted_at'));
        }

        if ($since !== null) {
            $query->where('updated_at', '>=', $since);
        }

        return $query
            ->orderBy('updated_at')
            ->orderBy('uuid')
            ->cursorPaginate($perPage);
    }
}
