<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Data\Catalog\SearchCriteriaData;
use App\Domain\Availability\Actions\SearchCatalogue;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Catalog\Support\SearchFilters;
use App\Domain\Catalog\Support\SearchFormOptions;
use App\Enums\ProductCategory;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\Vessel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * `book.{platform-domain}/{operator-slug}/search` — the catalogue search (#105).
 *
 * ## A plain `GET` form, which is what it is anyway
 *
 * HOS-4 requires the page to work with JavaScript off, and a search form is the
 * one interaction where that costs nothing: the filters are a `<form method=get>`
 * whose submission is a URL with a query string, and the results are rendered by
 * the server. There is no widget mount here and nothing to hydrate — the widget's
 * job is the booking, not the browsing.
 *
 * The URL carrying the query is the point rather than a consequence: a guest can
 * send "Saturday, four of us, from Piraeus" to the person they are travelling
 * with, and an operator can put that link in an email.
 *
 * ## The same Action the API calls, with the same filter enforcement
 *
 * {@see SearchCatalogue} answers both surfaces, and the operator's disabled
 * filters are dropped here exactly as {@see SearchRequest}
 * drops them — by asking {@see SearchFilters} rather than by not drawing the
 * control. A hidden filter that still worked from a query string would be a
 * setting that only appears to exist.
 */
class SearchPageController extends HostedController
{
    public function __construct(
        GetBrandPayload $brand,
        private readonly SearchCatalogue $search,
    ) {
        parent::__construct($brand);
    }

    public function show(Request $request, string $operator): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        $filters = SearchFilters::for($tenant);
        $criteria = $this->criteria($request, $tenant, $filters);

        return $this->render($request, $tenant, 'hosted.search', fn (): array => [
            'criteria' => $criteria,
            'results' => ($this->search)($criteria),
            // The form's own options, resolved once and shared with the home
            // page's copy of the same form (`SearchFormOptions`).
            ...SearchFormOptions::for($tenant),
            'metaDescription' => __('hosted.search.meta_description', ['operator' => $tenant->name]),
        ], $locale);
    }

    /**
     * The query string, with the operator's settings applied.
     *
     * A missing or malformed date is **today** rather than a refusal: this is a
     * page a guest lands on from a link, not an API call, and an empty search
     * page showing today's sailings is a better first screen than a validation
     * error about a parameter they never typed.
     *
     * @param  array<string, bool>  $filters
     */
    protected function criteria(Request $request, Tenant $tenant, array $filters): SearchCriteriaData
    {
        $timezone = LocalDateTimeResolver::timezone();
        $date = $this->date($request, $timezone);
        $pax = max(1, (int) $request->query('pax', '2'));

        $value = static fn (string $filter, string $key): ?string => $filters[$filter]
            && is_string($raw = $request->query($key)) && $raw !== ''
                ? $raw
                : null;

        $port = $value(SearchFilters::PORT, 'port');
        $category = $value(SearchFilters::TYPE, 'type');
        $duration = $value(SearchFilters::DURATION, 'duration_max');
        $price = $value(SearchFilters::PRICE, 'price_max');
        $vessel = $value(SearchFilters::VESSEL, 'vessel');

        return new SearchCriteriaData(
            date: $date,
            pax: $pax,
            portUuid: $port,
            category: $category !== null ? ProductCategory::tryFrom($category)?->value : null,
            durationMaxMinutes: is_numeric($duration) ? (int) $duration : null,
            // The form asks for euros because that is what a guest types; the
            // engine works in cents, like everything else that touches money.
            priceMaxCents: is_numeric($price) ? (int) round((float) $price * 100) : null,
            vesselUuid: $vessel,
            filtersEnabled: SearchFilters::enabled($tenant),
            applied: array_filter([
                'date' => $date->toDateString(),
                'pax' => $pax,
                'port' => $port,
                'type' => $category,
                'duration_max' => $duration,
                'price_max' => $price,
                'vessel' => $vessel,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    /** The searched day in the tenant's timezone, defaulting to today. */
    protected function date(Request $request, string $timezone): Carbon
    {
        $raw = $request->query('date');

        // The shape is checked before parsing rather than after: `Carbon`
        // throws on a malformed string rather than returning false, so a guard
        // on the return value would be a branch that can never run.
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return Carbon::createFromFormat('Y-m-d', $raw, $timezone)->startOfDay();
        }

        return Carbon::now($timezone)->startOfDay();
    }

    /** @return Collection<int, Port> */
    protected function ports()
    {
        return Port::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return Collection<int, Vessel> */
    protected function vessels()
    {
        return Vessel::query()->orderBy('sort_order')->orderBy('id')->get();
    }
}
