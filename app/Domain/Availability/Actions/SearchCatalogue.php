<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Data\Catalog\SearchCriteriaData;
use App\Data\Catalog\SearchResultData;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\LocalDay;
use App\Domain\Availability\Support\Window;
use App\Domain\Availability\VesselCalendar;
use App\Domain\Catalog\Queries\PublicProductQuery;
use App\Domain\Pricing\Support\PaxLineBuilder;
use App\Domain\Pricing\Support\RatePlanResolver;
use App\Enums\BookingMode;
use App\Enums\VesselStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "What can I do on Saturday, for four people, leaving from Piraeus?" (#105).
 *
 * ## A different question from `GET /availability`, not a loop over it
 *
 * {@see CheckSeatAvailability} answers **one** product across a range in five
 * queries, which is the right shape for a product page and the wrong one for a
 * catalogue: a fleet of twenty trips would be a hundred queries and a page an
 * operator complains about. This answers **one date across the catalogue** in a
 * fixed set of queries that does not grow with the number of products —
 * `SearchQueryCountTest` fixes the ceiling, the way `AvailabilityQueryCountTest`
 * does for the endpoint it is modelled on.
 *
 * The bound comes from loading each ingredient once and deciding in PHP:
 *
 * 1. the products the filters allow, with their vessel, bands and meeting point;
 * 2. their active rate plans, with the band prices;
 * 3. the tenant's seasons and date ranges;
 * 4. every sellable departure of those products on that day;
 * 5. vessel occupancy for the charters, through {@see VesselCalendar}'s bulk
 *    read rather than around it (ADR-0023).
 *
 * ## This is a shortlist, and the product page is the authority
 *
 * ADR-0006 already makes availability advisory — the locked write path is what
 * actually refuses a booking. This narrows further on purpose: it applies the
 * conditions a guest is choosing between (is it published, does it sail that
 * day, can it seat us, is it within the lead time, is the boat free) and leaves
 * the full AVL-22 ladder to the endpoint the guest reaches next. A search that
 * re-implemented all seven conditions would be a second copy of the engine, and
 * the second copy is the one that drifts.
 *
 * What that costs is stated rather than hidden: a trip can appear here and be
 * refused a minute later at checkout, which is true of every availability read
 * in the product.
 *
 * ## The price is for the party, and that is the whole feature
 *
 * PRC-1 still holds — the price is computed server-side from the resolved plan,
 * never accepted from a request — and it is computed **for the party asked
 * about** rather than read from `price_from_cents`. The from-price is what makes
 * a guest telephone: they see €65 and are charged €162.50.
 */
class SearchCatalogue
{
    /**
     * The trips that can take this party on this date, cheapest first.
     *
     * @return list<SearchResultData>
     */
    public function __invoke(SearchCriteriaData $criteria): array
    {
        $timezone = LocalDateTimeResolver::timezone();
        $day = LocalDay::of($criteria->date, $timezone);

        $products = $this->products($criteria);

        if ($products->isEmpty()) {
            return [];
        }

        $plans = $this->plans($products);
        $seasons = $this->seasons();
        $departures = $this->departures($products, $day);
        $occupied = $this->occupiedVessels($products, $day);

        $results = [];

        foreach ($products as $product) {
            $result = $this->resultFor(
                $product,
                $criteria,
                $day,
                $plans->get($product->getKey()) ?? collect(),
                $seasons,
                $departures->get($product->getKey()) ?? collect(),
                $occupied,
            );

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $this->ordered($results);
    }

    /**
     * The catalogue, narrowed by the filters that survived the operator's
     * settings.
     *
     * `sellable()` rather than a status check afterwards, so a draft or archived
     * trip is not in the set at all — the same rule
     * {@see PublicProductQuery} applies, for the same
     * reason: a branch that can be forgotten eventually is.
     *
     * @return EloquentCollection<int, Product>
     */
    private function products(SearchCriteriaData $criteria): EloquentCollection
    {
        return Product::query()
            ->sellable()
            ->with(['vessel', 'ageBands', 'meetingPoint'])
            ->when($criteria->category !== null, fn ($query) => $query->where('category', $criteria->category))
            ->when($criteria->durationMaxMinutes !== null, fn ($query) => $query->where('duration_minutes', '<=', $criteria->durationMaxMinutes))
            ->when(
                $criteria->portUuid !== null,
                // A uuid from another tenant matches nothing rather than
                // erroring: this is a filter, and one that leaked whether a port
                // exists elsewhere would be a cross-tenant probe (SEC-2).
                fn ($query) => $query->whereHas('meetingPoint', fn ($q) => $q->where('uuid', $criteria->portUuid)),
            )
            ->when(
                $criteria->vesselUuid !== null,
                fn ($query) => $query->whereHas('vessel', fn ($q) => $q->where('uuid', $criteria->vesselUuid)),
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every active plan of every candidate product, with its band prices.
     *
     * The prices are eager-loaded because {@see PaxLineBuilder} reads them
     * through the relation, and a lazy read there would be one query per
     * product — the N+1 this whole class is shaped to avoid.
     *
     * @param  EloquentCollection<int, Product>  $products
     *                                                      `groupBy()` hands back a plain collection of Eloquent ones, which is why
     *                                                      the outer type is not the inner type.
     * @return Collection<array-key, EloquentCollection<int, RatePlan>>
     */
    private function plans(EloquentCollection $products): Collection
    {
        return RatePlan::query()
            ->whereIn('product_id', $products->modelKeys())
            ->active()
            ->with('prices')
            ->get()
            ->groupBy('product_id');
    }

    /**
     * The tenant's seasons, loaded whole.
     *
     * The same reasoning {@see RatePlanResolver::load()} gives: an operator's
     * whole calendar is a handful of rows, and filtering by date in SQL would
     * cost a query per product for no saving.
     *
     * @return EloquentCollection<int, Season>
     */
    private function seasons(): EloquentCollection
    {
        return Season::query()->active()->with('dateRanges')->get();
    }

    /**
     * Every sellable departure of these products on that local day.
     *
     * Compared on `starts_at_utc` rather than on `local_date`, per AVL-13:
     * interval logic uses the UTC columns, and a 23-hour day is exactly where
     * the two would disagree.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @return Collection<array-key, EloquentCollection<int, Departure>>
     */
    private function departures(EloquentCollection $products, LocalDay $day): Collection
    {
        return Departure::query()
            ->whereIn('product_id', $products->modelKeys())
            ->sellable()
            ->where('is_blocked', false)
            ->onLocalDay($day)
            ->orderBy('starts_at_utc')
            ->get()
            ->groupBy('product_id');
    }

    /**
     * The charter boats that are busy that day.
     *
     * Only asked when the catalogue actually contains a `per_vessel` product,
     * because for a fleet of shared trips it is two queries answering a question
     * nobody put.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @return list<int>
     */
    private function occupiedVessels(EloquentCollection $products, LocalDay $day): array
    {
        /** @var Collection<int, Vessel> $vessels */
        $vessels = collect($products->all())
            ->filter(static fn (Product $product): bool => $product->mode === BookingMode::PerVessel)
            ->map(static fn (Product $product): ?Vessel => $product->vessel)
            ->filter()
            ->unique(static fn (Vessel $vessel): int => (int) $vessel->getKey())
            ->values();

        return $vessels->isEmpty()
            ? []
            : VesselCalendar::occupiedVesselIds($vessels, Window::of($day->startUtc, $day->endUtcExclusive));
    }

    /**
     * One product's answer, or null when it is not one of the results.
     *
     * @param  Collection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     * @param  Collection<int, Departure>  $departures
     * @param  list<int>  $occupiedVessels
     */
    private function resultFor(
        Product $product,
        SearchCriteriaData $criteria,
        LocalDay $day,
        Collection $plans,
        Collection $seasons,
        Collection $departures,
        array $occupiedVessels,
    ): ?SearchResultData {
        // BKG-24: a quote product is sold by the operator answering. It is
        // listed — half of these operators sell charters that way and hiding
        // them would empty the page — with no price and no departure.
        if ($product->mode === BookingMode::Quote) {
            return $this->fitsParty($product, $criteria->pax)
                ? new SearchResultData($product, SearchResultData::ON_REQUEST, null, null)
                : null;
        }

        if (! $this->fitsParty($product, $criteria->pax)) {
            return null;
        }

        $resolved = RatePlanResolver::resolve($plans, $seasons, $criteria->date);

        // PRC-5: no resolvable plan is not a price of zero, it is not for sale
        // that day.
        if (! $resolved->isSellable()) {
            return null;
        }

        $plan = $resolved->planOrFail();

        if (! $this->withinBookingWindow($plan, $day)) {
            return null;
        }

        [$availability, $departure] = $product->mode === BookingMode::PerSeat
            ? [SearchResultData::AVAILABLE, $this->firstBookableDeparture($departures, $plan, $criteria->pax)]
            : [SearchResultData::AVAILABLE, null];

        if ($product->mode === BookingMode::PerSeat && $departure === null) {
            return null;
        }

        if ($product->mode === BookingMode::PerVessel && $this->vesselIsBusy($product, $occupiedVessels)) {
            return null;
        }

        $price = $this->partyPriceCents($product, $plan, $criteria->pax);

        if ($price === null) {
            return null;
        }

        // The ceiling is compared against the **party** price, which is the
        // number on the card. A ceiling compared against a per-person figure
        // would quietly show a €600 charter to somebody who asked for trips
        // under €200.
        if ($criteria->priceMaxCents !== null && $price > $criteria->priceMaxCents) {
            return null;
        }

        return new SearchResultData($product, $availability, $price, $departure);
    }

    /**
     * CAT-5's party bounds: too many for the boat, or fewer than the operator
     * will sail for.
     */
    private function fitsParty(Product $product, int $pax): bool
    {
        if ($product->max_pax > 0 && $pax > $product->max_pax) {
            return false;
        }

        return $pax >= max(1, $product->min_booking_pax);
    }

    /**
     * AVL-19 and AVL-20, at day granularity.
     *
     * The lead time is checked per departure below, where the instant is known;
     * this is the coarser advance-window check, which is a property of the day.
     */
    private function withinBookingWindow(RatePlan $plan, LocalDay $day): bool
    {
        if ($plan->max_advance_days === null) {
            return true;
        }

        return $day->startUtc->lessThanOrEqualTo(Carbon::now()->addDays($plan->max_advance_days)->endOfDay());
    }

    /**
     * The soonest departure this party can still take.
     *
     * Seats are `capacity − seats_sold − seats_held`, disjoint and additive, and
     * the lead time is measured from now to the departure — a sailing in two
     * hours is gone for a trip that wants twelve.
     *
     * @param  Collection<int, Departure>  $departures
     */
    private function firstBookableDeparture(Collection $departures, RatePlan $plan, int $pax): ?Departure
    {
        $cutoff = Carbon::now()->addHours(max(0, $plan->min_lead_time_hours));

        return $departures
            ->first(static fn (Departure $departure): bool => $departure->seatsAvailable() >= $pax
                && $departure->starts_at_utc->greaterThanOrEqualTo($cutoff));
    }

    /** @param list<int> $occupiedVessels */
    private function vesselIsBusy(Product $product, array $occupiedVessels): bool
    {
        $vessel = $product->vessel;

        if (! $vessel instanceof Vessel) {
            // A charter with no boat cannot sail. §2.3 permits the null only for
            // `quote`, which never reaches here.
            return true;
        }

        return $vessel->status !== VesselStatus::Active
            || in_array((int) $vessel->getKey(), $occupiedVessels, true);
    }

    /**
     * What this party pays, from the plan already resolved.
     *
     * Per seat: `pax` guests in the **base** age band, through
     * {@see PaxLineBuilder} so PRC-6's rounding order — per unit, then multiply
     * — is the same arithmetic the checkout will do. A party with children is
     * priced exactly by `POST /price-quote`; a search box that asked for ages
     * would be a booking form.
     *
     * Per vessel: the whole boat, which is one number on the plan (PRC-8).
     */
    private function partyPriceCents(Product $product, RatePlan $plan, int $pax): ?int
    {
        if ($product->mode === BookingMode::PerVessel) {
            return $plan->vessel_price_cents;
        }

        $base = $product->ageBands->first(static fn (AgeBand $band): bool => (bool) $band->is_base)
            ?? $product->ageBands->first();

        if (! $base instanceof AgeBand) {
            return null;
        }

        $lines = PaxLineBuilder::build($plan, $product->ageBands, [$base->code => $pax]);

        if ($lines === []) {
            return null;
        }

        return array_sum(array_map(static fn ($line): int => $line->totalCents, $lines));
    }

    /**
     * Cheapest party price first, on-request trips last.
     *
     * A guest searching with a party size is comparing what they will pay, so
     * that is the order. The priceless ones go to the bottom rather than the top
     * because "ask us" is not an answer to "how much" — but they stay on the
     * page, because for half these operators the charter *is* the business.
     *
     * @param  list<SearchResultData>  $results
     * @return list<SearchResultData>
     */
    private function ordered(array $results): array
    {
        usort($results, static function (SearchResultData $a, SearchResultData $b): int {
            if ($a->isOnRequest() !== $b->isOnRequest()) {
                return $a->isOnRequest() ? 1 : -1;
            }

            return ($a->partyPriceCents ?? PHP_INT_MAX) <=> ($b->partyPriceCents ?? PHP_INT_MAX);
        });

        return $results;
    }
}
