<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Actions;

use App\Models\Faq;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The FAQ entries one guest surface shows, in the order it shows them.
 *
 * ## Two surfaces, two answers, one place that decides
 *
 * A **product page** shows the entries for that trip *plus* the tenant-wide
 * ones: a guest reading about the sunset cruise wants both "does it stop for a
 * swim" and "where do we meet". The **home page** shows the tenant-wide ones
 * only — a product-specific answer with no product beside it reads as a
 * contradiction, because "yes, we stop for a swim" is true of one trip and
 * false of the next.
 *
 * Both rules live here rather than in the two templates that need them, so the
 * product page and the home-page block cannot come to disagree about what
 * "published" means or which order the answers are in. #104 renders the product
 * page and calls this; it does not re-derive it.
 *
 * ## Product-specific entries come first
 *
 * Within a product page, the trip's own answers are the ones the guest is on
 * that page for. The operator's ordering is honoured inside each group rather
 * than across them, which is the only reading of `sort_order` that survives an
 * operator numbering their general answers 1–8 and then adding a trip-specific
 * one.
 */
class BuildFaqList
{
    /**
     * The published entries for a surface: a product's page, or the home page.
     *
     * Passing null means the home page, and is the reason this takes a nullable
     * product rather than having two methods — "which entries does this surface
     * show" is one question with one answer, and two methods would be two
     * places to forget `published()`.
     *
     * @return Collection<int, Faq>
     */
    public function __invoke(?Product $product = null): Collection
    {
        $query = Faq::query()->published()->inOperatorOrder();

        if ($product === null) {
            return $query->tenantWide()->get();
        }

        $entries = $query
            ->where(static function (Builder $builder) use ($product): void {
                $builder->whereNull('product_id')->orWhere('product_id', $product->getKey());
            })
            ->get();

        // The grouping is done here rather than in SQL: `ORDER BY product_id IS
        // NULL` is portable but reads as a trick, and the list is eight rows on
        // a busy operator. Splitting a collection of eight in PHP costs nothing
        // and says what it means.
        $specific = $entries->reject(static fn (Faq $faq): bool => $faq->isTenantWide());
        $wide = $entries->filter(static fn (Faq $faq): bool => $faq->isTenantWide());

        return $specific->concat($wide)->values();
    }
}
