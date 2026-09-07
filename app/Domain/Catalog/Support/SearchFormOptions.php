<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Enums\ProductCategory;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\Vessel;
use Illuminate\Support\Collection;

/**
 * Everything the search form needs to draw itself.
 *
 * ## Why it left the controller
 *
 * The form appears twice now: on the search page, and on the operator's home
 * page under the masthead, where a visitor with a date and a party in mind
 * should not have to find a navigation link first. Two controllers assembling
 * the same four values is two places to forget one — and the failure is a
 * silently missing filter on one of the two pages, which nobody notices because
 * each page looks complete on its own.
 *
 * ## The queries are here rather than in the template
 *
 * A template that queries is a template that queries on every render, and these
 * are the whole of what the controls offer. They are only run for the filters
 * the operator actually switched on: an operator who does not filter by vessel
 * should not pay for a vessel query on every page view.
 */
final class SearchFormOptions
{
    /**
     * @return array{
     *     filters: array<string, bool>,
     *     ports: Collection<int, Port>,
     *     vessels: Collection<int, Vessel>,
     *     categories: array<string, string>
     * }
     */
    public static function for(Tenant $tenant): array
    {
        $filters = SearchFilters::for($tenant);

        return [
            'filters' => $filters,
            'ports' => $filters[SearchFilters::PORT] ? self::ports() : collect(),
            'vessels' => $filters[SearchFilters::VESSEL] ? self::vessels() : collect(),
            'categories' => ProductCategory::options(),
        ];
    }

    /** @return Collection<int, Port> */
    private static function ports(): Collection
    {
        return Port::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return Collection<int, Vessel> */
    private static function vessels(): Collection
    {
        return Vessel::query()->orderBy('sort_order')->orderBy('id')->get();
    }
}
