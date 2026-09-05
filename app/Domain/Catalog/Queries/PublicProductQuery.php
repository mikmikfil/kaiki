<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Catalog\Support\OfferedExtrasResolver;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Models\Product;
use App\Models\RatePlan;
use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The catalogue as a guest may read it (spec ARC-5, SEC-2, NFR-6;
 * `docs/api.md` §5).
 *
 * A query object rather than scopes on the model, because "what the public API
 * may see" is a **policy** with several parts that have to hold together — only
 * `active`, only this tenant, a fixed order, and a fixed set of relations — and
 * a caller assembling those from four scopes is a caller who can leave one out.
 * `GET /products`, `GET /products/{uuid}`, the hosted page in M3 and the widget
 * all come through here.
 *
 * ## `sellable()` is not a synonym for "not draft"
 *
 * `ProductStatus` has four cases and only `Active` is public. `inactive` is an
 * operator's "off for now" and `archived` is gone; both must be as invisible to
 * a guest as a `draft`. The scope is on the model ({@see Product::scopeSellable})
 * so the panel and the importer agree with the API about the word.
 *
 * ## Ordering is part of the contract, not a preference
 *
 * §3.5 fixes it as `is_featured DESC, sort_order ASC, uuid ASC` and pins the
 * tiebreaker for a reason: cursor pagination encodes the sort key, and two rows
 * with the same `sort_order` and no tiebreaker make a cursor that can loop or
 * skip. `uuid` is unique per tenant, so the order is total.
 *
 * `products_tenant_status_sort_idx` is `(tenant_id, status, sort_order)` and
 * `products_tenant_cat_status_idx` is `(tenant_id, category, status)`
 * (`docs/data-model.md` §7.5). The category filter is applied so the second one
 * is usable — leading with `tenant_id`, which the `BelongsToTenant` global
 * scope appends to every query.
 *
 * ## The eager loads are here and nowhere else
 *
 * NFR-6. A list of forty products renders forty vessels and forty meeting
 * points; a resource reaching for `$product->vessel` without them is eighty
 * extra queries, and it is invisible until an operator with a real catalogue
 * complains the widget is slow. `ProductQueryCountTest` asserts the bound.
 */
final class PublicProductQuery
{
    /**
     * Everything a `ProductSummary` needs. `vessel` and `meetingPoint` are
     * both nullable relations — a `quote` product may have no boat (§2.3), and
     * a null meeting point renders as the vessel's home port.
     *
     * @var list<string>
     */
    private const LIST_RELATIONS = ['vessel', 'meetingPoint'];

    /**
     * The detail payload adds the three the summary does not carry. Extras are
     * deliberately absent: a tenant-wide extra reaches a product with no pivot
     * row at all, so there is no relation to eager-load and
     * {@see OfferedExtrasResolver} answers it in two
     * queries instead.
     *
     * `ratePlans` is narrowed to the active ones because `booking_window`
     * projects an extreme across them (`docs/api.md`, `BookingWindow`), and an
     * inactive plan's lead time must not tighten a window the operator has
     * switched off.
     *
     * @return array<int|string, Closure|string>
     */
    private static function detailRelations(): array
    {
        return [
            'vessel',
            'meetingPoint',
            'ageBands',
            'cancellationPolicy.tiers',
            'ratePlans' => self::activePlans(...),
        ];
    }

    /**
     * The public list, filtered and ordered.
     *
     * The three filters take **raw strings**, not enums. WGT-6 requires an
     * unknown `category` to return an empty list rather than an error, and the
     * way to honour that is to let the value reach the `where` and match
     * nothing. Mapping onto the enum first and dropping what does not fit would
     * widen the result to the whole catalogue — the one answer that is
     * certainly wrong. {@see ProductIndexRequest}
     * carries the same reasoning from the other side.
     *
     * @param  list<string>  $categories
     * @return Builder<Product>
     */
    public static function list(array $categories = [], ?string $mode = null, ?string $vesselUuid = null): Builder
    {
        $query = Product::query()
            ->sellable()
            ->with(self::LIST_RELATIONS);

        if ($categories !== []) {
            $query->whereIn('category', $categories);
        }

        if ($mode !== null) {
            $query->where('mode', $mode);
        }

        if ($vesselUuid !== null) {
            // A uuid from another tenant matches nothing rather than 404ing:
            // this is a filter, and a filter that leaks whether a boat exists
            // elsewhere is a cross-tenant probe (SEC-2). `whereHas` on a
            // tenant-scoped relation is already narrowed by the global scope.
            $query->whereHas('vessel', fn (Builder $q): Builder => $q->where('uuid', $vesselUuid));
        }

        return self::ordered($query);
    }

    /**
     * @param  list<string>  $categories
     * @return CursorPaginator<int, Product>
     */
    public static function page(
        int $perPage,
        array $categories = [],
        ?string $mode = null,
        ?string $vesselUuid = null,
    ): CursorPaginator {
        return self::list($categories, $mode, $vesselUuid)->cursorPaginate($perPage);
    }

    /**
     * One product by uuid **or slug** (`docs/api.md`, `ProductIdentifierPath`).
     *
     * The hosted page and the WordPress permalink both resolve with the slug;
     * the widget embed carries the uuid. One lookup rather than two endpoints,
     * because they are the same resource.
     *
     * Null rather than an exception, and the caller answers 404 — for a
     * cross-tenant uuid as much as for one that does not exist. SEC-1 and SEC-2
     * are explicit that the two must be indistinguishable: a 403 would confirm
     * the product is real and belongs to somebody else.
     */
    public static function find(string $identifier): ?Product
    {
        /** @var Product|null $product */
        $product = Product::query()
            ->sellable()
            ->with(self::detailRelations())
            ->where(fn (Builder $q): Builder => $q
                ->where('uuid', $identifier)
                ->orWhere('slug', $identifier))
            ->first();

        return $product;
    }

    /**
     * A named method rather than an inline closure so the relation's generics
     * are declared: an `array` element's docblock is not read, and without the
     * types `active()` is an undefined method on an unparameterised builder.
     *
     * @param  HasMany<RatePlan, Product>  $query
     * @return Builder<RatePlan>
     */
    private static function activePlans(HasMany $query): Builder
    {
        return $query->getQuery()->active();
    }

    /**
     * One product, loaded for the availability engine rather than for display.
     *
     * A narrower load than {@see self::find()} on purpose. NFR-7 budgets five
     * queries for a 62-day availability answer and the engine spends all five;
     * anything the controller loads comes out of the same request. The detail
     * payload's cancellation policy and its tiers are two more queries that
     * `GET /availability` never reads.
     *
     * `vessel` and `ageBands` are not optional: {@see CheckSeatAvailability}
     * says in its own docblock that it loads them itself if they are missing,
     * *"which costs two queries the budget does not count and a caller should
     * not spend"*.
     */
    public static function findForAvailability(string $identifier): ?Product
    {
        /** @var Product|null $product */
        $product = Product::query()
            ->sellable()
            ->with(['vessel', 'ageBands', 'ratePlans' => self::activePlans(...)])
            ->where(fn (Builder $q): Builder => $q
                ->where('uuid', $identifier)
                ->orWhere('slug', $identifier))
            ->first();

        return $product;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private static function ordered(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('uuid');
    }
}
