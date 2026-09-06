<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Data\Availability\AvailabilityDayData;
use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\DepartureAvailabilityData;
use App\Domain\Availability\Contracts\DepartureExpiredHolds;
use App\Domain\Availability\Contracts\DeparturePersonsAboard;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\CountedSeats;
use App\Domain\Availability\Support\LocalDay;
use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\PartyGuard;
use App\Domain\Availability\Support\Window;
use App\Domain\Pricing\Support\RatePlanResolver;
use App\Domain\Pricing\Support\SeasonCandidateResolver;
use App\Enums\AvailabilityRejection;
use App\Enums\ProductStatus;
use App\Enums\VesselStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Can this party book this trip on these dates (spec AVL-22 to AVL-29)?
 *
 * ## All seven AVL-22 conditions, reported in the order a guest can act on
 *
 * A departure usually fails several at once — cancelled *and* past its lead
 * time *and* full — so the reported reason is the **first** that applies, and
 * the order is chosen for usefulness rather than tidiness: "sold out" sends a
 * guest to another date, "the trip is not published" sends them nowhere, and
 * both would be true.
 *
 * ## The legal check is separate, and always runs
 *
 * AVL-25, marked RESOLVED with its reasoning attached: *"conflating them would
 * let a boat sail illegally full of infants."* A party of two adults and two
 * infants is **two seats and four people**. The commercial check compares
 * counted seats against remaining capacity; the legal check compares every
 * person aboard against the boat's certificate. A party can pass the first and
 * fail the second, which is why they carry different codes — telling that
 * family "the boat is full" would be false and unactionable.
 *
 * How many people are already aboard comes from
 * {@see DeparturePersonsAboard}, an interface with **no implementation
 * registered**: it reports zero, because `bookings` does not exist until M2.
 * The rule, its code and its tests ship now so that M2 adds a class rather than
 * a check written under time pressure.
 *
 * ## Five queries, whatever the range (NFR-7)
 *
 * 1. every non-cancelled departure on the **vessel** across the range — the
 *    product's own are filtered out of that set in PHP, because they are a
 *    subset and a second query would be a fifth of the budget;
 * 2. the vessel's blocks across the same range;
 * 3. the product's active rate plans, for AVL-19 and AVL-20;
 * 4 and 5. seasons and their ranges, so the plan applying to each date is
 *    decided in PHP.
 *
 * The price-from figure is the derived `products.price_from_cents` (§1.9)
 * rather than a per-date price resolution, which would be three more queries
 * and is #33's job anyway.
 *
 * The product is expected to arrive with `vessel` and `ageBands` loaded — the
 * caller has just fetched it — and they are loaded here if not, which costs two
 * queries the budget does not count and a caller should not spend.
 */
final class CheckSeatAvailability
{
    public const TAG = 'availability.persons-aboard';

    /** {@see DepartureExpiredHolds} — AVL-38's read-side correction. */
    public const EXPIRED_HOLDS_TAG = 'availability.expired-holds';

    private readonly PartyGuard $party;

    /** @param iterable<DeparturePersonsAboard> $personsAboard */
    public function __construct(private readonly iterable $personsAboard = [])
    {
        $this->party = new PartyGuard($personsAboard);
    }

    /** @return list<AvailabilityDayData> one entry per requested date, always */
    public function __invoke(Product $product, AvailabilityRequestData $request): array
    {
        $timezone = LocalDateTimeResolver::timezone();
        $range = $this->rangeWindow($request, $timezone);

        $bands = $product->ageBands;
        $pax = CountedSeats::sanitise($bands, $request->paxByCode);
        $vessel = $product->vessel;

        // Evaluated once for the whole request: none of these depends on the
        // date, and re-deciding "is this product active" sixty times is sixty
        // chances to decide it differently.
        $blanket = $this->blanketRejection($product, $vessel, $bands, $pax);

        if (! $vessel instanceof Vessel) {
            // No boat, no departures — and `blanketRejection` has already said
            // why. Returning empty days is the honest answer rather than an
            // exception a widget would have to interpret.
            return $this->emptyDays($request);
        }

        $occupations = OccupationCollector::forRange($vessel, $range);
        $departures = $occupations->departuresFor($product, $range);

        // AVL-38's read side, in one query for the whole range. Without it a
        // hold that lapsed a minute ago still keeps its seats off sale until
        // the sweeper catches up, so a backlogged queue quietly costs bookings
        // — and the requirement is explicit that correctness must not depend on
        // the scheduler having run.
        self::hydrateExpiredHolds($departures);

        $plans = RatePlan::query()
            ->where('product_id', $product->getKey())
            ->active()
            ->get();

        $seasons = SeasonCandidateResolver::allWithRanges();

        $byDate = $departures->groupBy(
            static fn (Departure $departure): string => $departure->local_date->toDateString(),
        );

        $days = [];

        foreach ($request->dates() as $date) {
            $key = $date->toDateString();

            $days[] = new AvailabilityDayData(
                localDate: $key,
                departures: $this->evaluateDay(
                    $byDate->get($key, collect()),
                    $product,
                    $bands,
                    $pax,
                    $occupations,
                    $plans,
                    $seasons,
                    $blanket,
                    $timezone,
                ),
            );
        }

        return $days;
    }

    /**
     * Tell each departure how many of its held seats have already lapsed.
     *
     * Batched through {@see DepartureExpiredHolds}, whose implementation lives
     * in `app/Domain/Booking` — the availability engine asks the question and
     * does not know that `bookings` is where the answer comes from. With no
     * implementation registered the correction is zero and the stored counter
     * is taken at face value, which errs towards refusing a free seat rather
     * than selling one twice.
     *
     * @param  Collection<int, Departure>  $departures
     */
    private static function hydrateExpiredHolds(Collection $departures): void
    {
        if ($departures->isEmpty()) {
            return;
        }

        // **Nothing held, nothing to correct, no query.** NFR-7 budgets five
        // queries for this read whatever the range, and the correction would
        // have made it six on every request — including the overwhelming
        // majority where no seat on any date in the range is held by anybody.
        //
        // This is not an optimisation that trades correctness for speed: an
        // expired hold is a subtrahend, and a departure with `seats_held = 0`
        // has nothing to subtract from. The sixth query is paid only when there
        // is something for it to find.
        if ($departures->every(static fn (Departure $departure): bool => $departure->seats_held === 0)) {
            return;
        }

        /** @var iterable<DepartureExpiredHolds> $sources */
        $sources = app()->tagged(self::EXPIRED_HOLDS_TAG);

        foreach ($sources as $source) {
            foreach ($source->expiredHeldSeats($departures) as $departureId => $seats) {
                $departures->firstWhere('id', $departureId)?->withExpiredHeldSeats($seats);
            }
        }
    }

    /**
     * @param  Collection<int, Departure>  $departures
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax
     * @param  Collection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     * @return list<DepartureAvailabilityData>
     */
    private function evaluateDay(
        Collection $departures,
        Product $product,
        Collection $bands,
        array $pax,
        OccupationCollector $occupations,
        Collection $plans,
        Collection $seasons,
        ?AvailabilityRejection $blanket,
        string $timezone,
    ): array {
        $lines = [];

        foreach ($departures as $departure) {
            $rejection = $blanket ?? $this->rejectionFor(
                $departure,
                $product,
                $bands,
                $pax,
                $occupations,
                $plans,
                $seasons,
                $timezone,
            );

            $remaining = $departure->seatsAvailable();

            $lines[] = $rejection === null
                ? DepartureAvailabilityData::available($departure, $remaining, $product->price_from_cents)
                : DepartureAvailabilityData::unavailable($departure, $rejection, $remaining);
        }

        return $lines;
    }

    /**
     * The conditions that hold for every date (AVL-22.6, AVL-22.7, AVL-26).
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax
     */
    private function blanketRejection(Product $product, ?Vessel $vessel, Collection $bands, array $pax): ?AvailabilityRejection
    {
        // TEN-9 and AVL-22.7: a lapsed subscription stops **new** bookings.
        // Existing ones and tokenised guest pages keep working, which is why
        // this is a condition here rather than middleware on the endpoint.
        if (Tenancy::current()?->allowsWrites() === false) {
            return AvailabilityRejection::TenantReadOnly;
        }

        if ($product->status !== ProductStatus::Active) {
            return AvailabilityRejection::ProductNotActive;
        }

        if (! $vessel instanceof Vessel || $vessel->status !== VesselStatus::Active) {
            return AvailabilityRejection::VesselNotActive;
        }

        // AVL-26, and only when a party was given: an empty request is a
        // calendar asking what exists, not a family asking for four seats.
        //
        // `blanket()` rather than the full check, because only this rule is
        // date-independent — see {@see PartyGuard::blanket()} for what folding
        // legal capacity in here would do to the reported reason.
        return $this->party->blanket($bands, $pax);
    }

    /**
     * The per-departure conditions (AVL-22.1 to .5, AVL-23 to AVL-25).
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax
     * @param  Collection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     */
    private function rejectionFor(
        Departure $departure,
        Product $product,
        Collection $bands,
        array $pax,
        OccupationCollector $occupations,
        Collection $plans,
        Collection $seasons,
        string $timezone,
    ): ?AvailabilityRejection {
        $plan = RatePlanResolver::resolve($plans, $seasons, $departure->local_date)->plan;

        // AVL-19: **absolute** hours from now. An hour is an hour whatever the
        // clocks did last night, so this is instant arithmetic and not calendar
        // arithmetic — unlike the check immediately below it.
        $leadHours = $plan === null ? 0 : $plan->min_lead_time_hours;

        if ($departure->starts_at_utc->lessThan(Carbon::now()->addHours($leadHours))) {
            return AvailabilityRejection::LeadTimeTooShort;
        }

        // AVL-20: **calendar** days in the tenant's timezone, deliberately
        // unlike the lead time. "Ninety days ahead" is a date an operator can
        // point at on a calendar, not 2160 hours.
        if ($this->isTooFarAhead($departure, $plan === null ? null : $plan->max_advance_days, $timezone)) {
            return AvailabilityRejection::TooFarAhead;
        }

        // AVL-22.1, with AVL-7's buffer and AVL-9's self-exclusion.
        if (! $occupations->isFree(Window::of($departure->starts_at_utc, $departure->ends_at_utc), $departure)) {
            return AvailabilityRejection::VesselBusy;
        }

        // AVL-22.3 with AVL-23 and AVL-25. Both live in {@see PartyGuard},
        // which `POST /price-quote` calls with the same arguments — a party the
        // calendar accepted and the quote refused is the site contradicting
        // itself in front of a guest.
        //
        // `seatsAvailable()` is `capacity − seats_sold − seats_held` (§2.4,
        // AVL-24), with expired holds already treated as released (ADR-0005).
        return $this->party->check($bands, $pax, $product, $departure);
    }

    /** AVL-20, in tenant-local calendar days from today. */
    private function isTooFarAhead(Departure $departure, ?int $maxAdvanceDays, string $timezone): bool
    {
        if ($maxAdvanceDays === null) {
            return false;
        }

        $limit = Carbon::parse(LocalDay::today($timezone)->localDate)->addDays($maxAdvanceDays);

        return Carbon::parse($departure->local_date->toDateString())->greaterThan($limit);
    }

    /** The UTC window covering every requested local date. */
    private function rangeWindow(AvailabilityRequestData $request, string $timezone): Window
    {
        return Window::of(
            LocalDay::of($request->from, $timezone)->startUtc,
            LocalDay::of($request->to, $timezone)->endUtcExclusive,
        );
    }

    /** @return list<AvailabilityDayData> */
    private function emptyDays(AvailabilityRequestData $request): array
    {
        return array_map(
            static fn (Carbon $date): AvailabilityDayData => new AvailabilityDayData($date->toDateString()),
            $request->dates(),
        );
    }
}
