<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Data\OfferedExtra;
use App\Models\Extra;
use App\Models\Product;
use App\Models\ProductExtra;
use Illuminate\Support\Collection;

/**
 * Which extras a product offers, with overrides applied (spec CAT-12, PRC-10).
 *
 * ## Two ways to be offered, and one of them is silent
 *
 * An extra reaches a product either because it is **tenant-wide** — offered on
 * everything unless a pivot says otherwise — or because a **pivot row** names
 * this product. A tenant-wide extra with pivot rows elsewhere still applies
 * here; what the pivot adds is the *terms*, not the membership.
 *
 * That means the answer cannot be one query on the pivot. It is the union of
 * "tenant-wide and active" and "named by a pivot row for this product", with
 * the pivot supplying overrides wherever it exists.
 *
 * ## Two queries, whatever the size of the catalogue
 *
 * NFR-6. This runs on every availability response and every checkout render, so
 * it fetches the candidate extras and the product's pivot rows once each and
 * does the joining in PHP.
 */
final class OfferedExtrasResolver
{
    /**
     * @return Collection<int, OfferedExtra>
     */
    public static function forProduct(Product $product): Collection
    {
        /** @var Collection<int, ProductExtra> $pivots */
        $pivots = ProductExtra::query()
            ->where('product_id', $product->getKey())
            ->get()
            ->keyBy('extra_id');

        /** @var Collection<int, Extra> $extras */
        $extras = Extra::query()
            ->active()
            ->where(function ($query) use ($pivots): void {
                $query->where('is_tenant_wide', true);

                if ($pivots->isNotEmpty()) {
                    $query->orWhereIn('id', $pivots->keys()->all());
                }
            })
            ->get();

        return $extras
            ->map(fn (Extra $extra): OfferedExtra => OfferedExtra::resolve($extra, $pivots->get($extra->getKey())))
            // Sorted by the resolved order, so a product that reorders a
            // tenant-wide extra through its pivot is honoured — then by name,
            // so the list is stable when an operator has not ordered anything.
            ->sortBy([['sortOrder', 'asc'], ['name', 'asc']])
            ->values();
    }

    /**
     * One extra as this product offers it, or null when it does not.
     */
    public static function one(Product $product, Extra $extra): ?OfferedExtra
    {
        return self::forProduct($product)->firstWhere('extraId', $extra->getKey());
    }
}
