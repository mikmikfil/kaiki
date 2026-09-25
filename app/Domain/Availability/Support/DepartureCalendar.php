<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Data\Availability\DepartureAvailabilityData;
use App\Data\Availability\DepartureCalendarCriteria;
use App\Data\Availability\DepartureCalendarDay;
use App\Data\Availability\DepartureCalendarResult;
use App\Data\Availability\DepartureCalendarRow;
use App\Data\Availability\VesselWindowData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Actions\CheckVesselAvailability;
use App\Domain\Availability\Actions\SearchCatalogue;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Pricing\Support\PaxLineBuilder;
use App\Domain\Pricing\Support\RatePlanResolver;
use App\Domain\Pricing\Support\SeasonCandidateResolver;
use App\Enums\AvailabilityRejection;
use App\Enums\BookingMode;
use App\Enums\DepartureStatus;
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
 * Every scheduled departure of every trip, day by day (2026-09-25).
 *
 * The «Ημερολόγιο» page of an operator's site (direction Β of
 * `docs/mockups/departures-calendar.html`): fourteen days, each sailing a line
 * with its time, its trip, its seats in words, its price and a way to book.
 *
 * ## The engine's verdicts, not a second engine
 *
 * `GET /availability` answers one trip across a range in five queries, and a
 * loop over the catalogue would be five per trip. So this loads each
 * ingredient **once for the whole catalogue** and hands every trip's share to
 * the same code that endpoint runs:
 *
 * - seats, lead time, advance window and a busy boat —
 *   {@see CheckSeatAvailability::evaluate()}, over
 *   {@see OccupationCollector::forVessels()};
 * - a charter's day — {@see CheckVesselAvailability::forDates()};
 * - the party's bounds — {@see SearchCatalogue::fitsParty()}.
 *
 * What is decided here is only the *word*: a departure with three seats is
 * «Τελευταίες 3 θέσεις», one with a seat for a party of two is «Μόνο 1 θέση».
 * The engine is asked with no party at all — the question a calendar asks —
 * so AVL-25's head-count, which is a query per departure, is left to the
 * booking walk the «Κράτηση» button leads to. Availability is advisory anyway
 * (ADR-0006); the locked write path is what refuses a seat.
 *
 * ## A fixed number of queries, whatever the catalogue
 *
 * Products with their boats and bands; plans with their prices; seasons with
 * their ranges; the fleet's departures, blocks and holds; the expired holds;
 * and the cancelled and on-request sailings. `DepartureCalendarTest` fixes the
 * ceiling by comparing two trips with ten.
 */
class DepartureCalendar
{
    /** One page of the calendar. */
    public const DAYS = 14;

    public function __construct(
        private readonly CheckSeatAvailability $seats,
        private readonly CheckVesselAvailability $vessels,
    ) {}

    public function __invoke(DepartureCalendarCriteria $criteria): DepartureCalendarResult
    {
        $timezone = LocalDateTimeResolver::timezone();
        $dates = $this->dates($criteria);
        $range = Window::of(
            LocalDay::of($dates[0], $timezone)->startUtc,
            LocalDay::of($dates[count($dates) - 1], $timezone)->endUtcExclusive,
        );

        $products = $this->products();

        /** @var array<string, list<DepartureCalendarRow>> $byDate */
        $byDate = array_fill_keys(array_map(static fn (Carbon $date): string => $date->toDateString(), $dates), []);

        if ($products->isNotEmpty()) {
            foreach ($this->rows($products, $dates, $range, $criteria->pax) as $row) {
                if (array_key_exists($row->localDate, $byDate)) {
                    $byDate[$row->localDate][] = $row;
                }
            }
        }

        $wanted = $this->wantedProductIds($products, $criteria->tripSlugs);
        $days = [];
        $inRange = [];

        foreach ($byDate as $date => $rows) {
            usort($rows, static fn (DepartureCalendarRow $a, DepartureCalendarRow $b): int => [$a->kind === DepartureCalendarRow::KIND_CHARTER, $a->localTime, $a->product->sort_order]
                <=> [$b->kind === DepartureCalendarRow::KIND_CHARTER, $b->localTime, $b->product->sort_order]);

            foreach ($rows as $row) {
                $inRange[(int) $row->product->getKey()] = true;
            }

            $days[] = new DepartureCalendarDay(
                $date,
                array_values(array_filter($rows, fn (DepartureCalendarRow $row): bool => $this->keeps($row, $criteria, $wanted))),
                count($rows),
            );
        }

        // The «Εκδρομή» filter lists the trips that sail in these days: a
        // trip with nothing on the page is a box that changes nothing.
        $listed = $products->filter(static fn (Product $product): bool => isset($inRange[(int) $product->getKey()]))->values();
        $result = new DepartureCalendarResult($days, $products, null, $listed);

        // «Επόμενη: …» — asked only for an empty page, which is the one place
        // it is said, so an ordinary page does not pay a query for it.
        if ($result->unfilteredCount() === 0 && $products->isNotEmpty()) {
            return new DepartureCalendarResult($days, $products, $this->nextDate($products, $range->endUtc, $timezone), $listed);
        }

        return $result;
    }

    /**
     * Which days of a month have something on them, for the «Μήνας» grid.
     *
     * One query. `open` for a day with a sailing that is not cancelled,
     * `weather` for one whose every sailing the weather took, `none` for a day
     * that had sailings and lost them another way; a day absent from the
     * answer has nothing scheduled at all.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @return array<string, string> `Y-m-d` => mark
     */
    public function month(EloquentCollection $products, Carbon $month): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $rows = Departure::query()
            ->whereIn('product_id', $products->modelKeys())
            ->where('is_blocked', false)
            ->whereBetween('local_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get(['local_date', 'status', 'cancel_reason']);

        $marks = [];

        foreach ($rows->groupBy(static fn (Departure $d): string => $d->local_date->toDateString()) as $date => $departures) {
            $live = $departures->contains(static fn (Departure $d): bool => $d->status !== DepartureStatus::Cancelled);
            $weather = $departures->contains(static fn (Departure $d): bool => $d->cancel_reason?->value === 'weather');

            $marks[(string) $date] = $live ? 'open' : ($weather ? 'weather' : 'none');
        }

        return $marks;
    }

    /** @return list<Carbon> */
    private function dates(DepartureCalendarCriteria $criteria): array
    {
        $first = Carbon::parse($criteria->from)->startOfDay();
        $dates = [];

        for ($i = 0; $i < max(1, $criteria->days); $i++) {
            $dates[] = $first->copy()->addDays($i);
        }

        return $dates;
    }

    /**
     * The trips the calendar lists: on sale, in the catalogue's order.
     *
     * The same `sellable()` the rest of the hosted site uses, so a draft or an
     * archived trip is not in the set at all. A trip on a boat that is out of
     * service is left out too — the engine would refuse every one of its
     * sailings, and a column of «Πλήρης» for a boat in the yard is not true.
     *
     * @return EloquentCollection<int, Product>
     */
    private function products(): EloquentCollection
    {
        return Product::query()
            ->sellable()
            ->with(['vessel', 'ageBands'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Product $product): bool => $product->mode === BookingMode::Quote
                || ($product->vessel instanceof Vessel && $product->vessel->status === VesselStatus::Active))
            ->values();
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @param  list<Carbon>  $dates
     * @return list<DepartureCalendarRow>
     */
    private function rows(EloquentCollection $products, array $dates, Window $range, int $pax): array
    {
        $plans = RatePlan::query()
            ->whereIn('product_id', $products->modelKeys())
            ->active()
            ->with('prices')
            ->get()
            ->groupBy('product_id');

        $seasons = SeasonCandidateResolver::allWithRanges();

        /** @var Collection<int, Vessel> $fleet */
        $fleet = collect($products->all())
            ->filter(static fn (Product $product): bool => $product->mode !== BookingMode::Quote)
            ->map(static fn (Product $product): ?Vessel => $product->vessel)
            ->filter()
            ->unique(static fn (Vessel $vessel): int => (int) $vessel->getKey())
            ->values();

        $occupations = OccupationCollector::forVessels($fleet, $range);

        // Every sailing the engine will be asked about, hydrated in one batch
        // (AVL-38) before any of them is.
        $seatDepartures = [];

        foreach ($products as $product) {
            if ($product->mode === BookingMode::PerSeat && isset($occupations[(int) $product->vessel_id])) {
                $seatDepartures[$product->getKey()] = $occupations[(int) $product->vessel_id]->departuresFor($product, $range);
            }
        }

        CheckSeatAvailability::hydrateExpiredHolds(collect($seatDepartures)->flatten(1));

        $rows = [];

        foreach ($products as $product) {
            /** @var EloquentCollection<int, RatePlan> $own */
            $own = $plans->get($product->getKey()) ?? new EloquentCollection;

            $new = match ($product->mode) {
                BookingMode::PerSeat => isset($seatDepartures[$product->getKey()])
                    ? $this->seatRows($product, $seatDepartures[$product->getKey()], $occupations[(int) $product->vessel_id], $own, $seasons, $pax)
                    : [],
                BookingMode::PerVessel => isset($occupations[(int) $product->vessel_id])
                    ? $this->charterRows($product, $dates, $occupations[(int) $product->vessel_id], $own, $seasons, $pax)
                    : [],
                BookingMode::Quote => [],
            };

            array_push($rows, ...$new);
        }

        array_push($rows, ...$this->cancelledAndOnRequest($products, $range));

        return $rows;
    }

    /**
     * @param  Collection<int, Departure>  $departures
     * @param  EloquentCollection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     * @return list<DepartureCalendarRow>
     */
    private function seatRows(
        Product $product,
        Collection $departures,
        OccupationCollector $occupations,
        EloquentCollection $plans,
        Collection $seasons,
        int $pax,
    ): array {
        $lines = collect($this->seats->evaluate($product, $departures, $occupations, $plans, $seasons))
            ->keyBy(static fn (DepartureAvailabilityData $line): string => $line->uuid);

        $now = Carbon::now();
        $rows = [];

        foreach ($departures as $departure) {
            $line = $lines->get($departure->uuid);

            // A sailing on a boat under maintenance is not happening, and one
            // that is the empty alternative to another sailing already selling
            // seats on the same boat at the same hour (AVL-10, AVL-11) is not
            // one a guest can choose. Neither is «full»; neither is shown.
            if ($departure->is_blocked || ! $line instanceof DepartureAvailabilityData) {
                continue;
            }

            if (in_array($line->rejection, [AvailabilityRejection::VesselBusy, AvailabilityRejection::VesselHeld], true)
                && $departure->seats_sold === 0 && $departure->seats_held === 0) {
                continue;
            }

            [$status, $limit] = $this->seatStatus($product, $departure, $line, $pax, $now);

            $rows[] = new DepartureCalendarRow(
                kind: DepartureCalendarRow::KIND_DEPARTURE,
                product: $product,
                localDate: $departure->local_date->toDateString(),
                localTime: substr((string) $departure->local_time, 0, 5),
                departureUuid: $departure->uuid,
                status: $status,
                seatsAvailable: max(0, $line->seatsRemaining),
                priceCents: $this->perPersonCents($product, $plans, $seasons, $departure->local_date),
                vesselName: $product->vessel?->name,
                isGuaranteed: $line->isGuaranteed,
                limit: $limit,
            );
        }

        return $rows;
    }

    /**
     * The word for one sailing, from the engine's verdict and the party.
     *
     * @return array{0: string, 1: int|null}
     */
    private function seatStatus(Product $product, Departure $departure, DepartureAvailabilityData $line, int $pax, Carbon $now): array
    {
        if ($departure->starts_at_utc->lessThanOrEqualTo($now) || $departure->status === DepartureStatus::Completed) {
            return [DepartureCalendarRow::PAST, null];
        }

        if (! $line->available) {
            $word = match ($line->rejection) {
                AvailabilityRejection::LeadTimeTooShort => DepartureCalendarRow::CLOSED,
                AvailabilityRejection::TooFarAhead => DepartureCalendarRow::LATER,
                // A lapsed subscription is said once, on the trip page the
                // button leads to (HOS-10) — not as «full» on every line here.
                AvailabilityRejection::TenantReadOnly => null,
                default => DepartureCalendarRow::FULL,
            };

            if ($word !== null) {
                return [$word, null];
            }
        }

        $seats = max(0, $line->seatsRemaining);

        if ($seats === 0) {
            return [DepartureCalendarRow::FULL, null];
        }

        if (! SearchCatalogue::fitsParty($product, $pax)) {
            return $product->max_pax > 0 && $pax > $product->max_pax
                ? [DepartureCalendarRow::TOO_MANY, $product->max_pax]
                : [DepartureCalendarRow::TOO_FEW, max(1, $product->min_booking_pax)];
        }

        if ($seats < $pax) {
            return [DepartureCalendarRow::NO_FIT, null];
        }

        return [$seats <= self::fewSeats() ? DepartureCalendarRow::FEW : DepartureCalendarRow::AVAILABLE, null];
    }

    /**
     * One line per day for a whole-boat trip, by the charter calendar's rules.
     *
     * A day with no plan to price it is not a day the boat is offered (PRC-5),
     * and a day whose window has already begun is gone: neither gets a line.
     *
     * @param  list<Carbon>  $dates
     * @param  EloquentCollection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     * @return list<DepartureCalendarRow>
     */
    private function charterRows(
        Product $product,
        array $dates,
        OccupationCollector $occupations,
        EloquentCollection $plans,
        Collection $seasons,
        int $pax,
    ): array {
        $now = Carbon::now();
        $rows = [];

        foreach ($this->vessels->forDates($product, $dates, $occupations, $plans, $seasons) as $window) {
            if (! $window->startsAtUtc instanceof Carbon || $window->startsAtUtc->lessThanOrEqualTo($now)) {
                continue;
            }

            $resolved = RatePlanResolver::resolve($plans, $seasons, Carbon::parse($window->localDate));

            if (! $resolved->isSellable()) {
                continue;
            }

            $status = $this->charterStatus($product, $window, $pax);

            $rows[] = new DepartureCalendarRow(
                kind: DepartureCalendarRow::KIND_CHARTER,
                product: $product,
                localDate: $window->localDate,
                localTime: $window->startsAtLocal !== null ? substr($window->startsAtLocal, 0, 5) : null,
                departureUuid: null,
                status: $status,
                priceCents: $resolved->planOrFail()->vessel_price_cents ?? $product->price_from_cents,
                vesselName: $product->vessel?->name,
                limit: $status === DepartureCalendarRow::TOO_MANY ? $product->max_pax : null,
            );
        }

        return $rows;
    }

    private function charterStatus(Product $product, VesselWindowData $window, int $pax): string
    {
        if (! $window->available) {
            return match ($window->rejection) {
                AvailabilityRejection::LeadTimeTooShort => DepartureCalendarRow::CLOSED,
                AvailabilityRejection::TooFarAhead => DepartureCalendarRow::LATER,
                default => DepartureCalendarRow::BOOKED,
            };
        }

        return $product->max_pax > 0 && $pax > $product->max_pax
            ? DepartureCalendarRow::TOO_MANY
            : DepartureCalendarRow::AVAILABLE;
    }

    /**
     * The sailings the engine never returns, in one query: the cancelled ones
     * (AVL-28 removes them from availability, and a weather day would read as
     * an empty one), and those of trips sold by quote (BKG-24), which have
     * times but no seats to count.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @return list<DepartureCalendarRow>
     */
    private function cancelledAndOnRequest(EloquentCollection $products, Window $range): array
    {
        $byId = $products->keyBy(static fn (Product $product): int => (int) $product->getKey());
        $quote = $products->filter(static fn (Product $product): bool => $product->mode === BookingMode::Quote)->modelKeys();
        $timed = $products->filter(static fn (Product $product): bool => $product->mode !== BookingMode::PerVessel)->modelKeys();

        if ($timed === []) {
            return [];
        }

        $departures = Departure::query()
            ->whereIn('product_id', $timed)
            ->where('is_blocked', false)
            ->where('starts_at_utc', '>=', $range->startUtc)
            ->where('starts_at_utc', '<', $range->endUtc)
            ->where(static fn ($query) => $query
                ->where('status', DepartureStatus::Cancelled)
                ->when($quote !== [], static fn ($q) => $q->orWhere(static fn ($q2) => $q2
                    ->whereIn('product_id', $quote)
                    ->whereIn('status', [DepartureStatus::Scheduled, DepartureStatus::Guaranteed]))))
            ->orderBy('starts_at_utc')
            ->get();

        $now = Carbon::now();
        $rows = [];

        foreach ($departures as $departure) {
            $product = $byId->get((int) $departure->product_id);

            if (! $product instanceof Product) {
                continue;
            }

            $cancelled = $departure->status === DepartureStatus::Cancelled;

            $rows[] = new DepartureCalendarRow(
                kind: DepartureCalendarRow::KIND_DEPARTURE,
                product: $product,
                localDate: $departure->local_date->toDateString(),
                localTime: substr((string) $departure->local_time, 0, 5),
                departureUuid: $departure->uuid,
                status: match (true) {
                    $cancelled => DepartureCalendarRow::CANCELLED,
                    $departure->starts_at_utc->lessThanOrEqualTo($now) => DepartureCalendarRow::PAST,
                    default => DepartureCalendarRow::ON_REQUEST,
                },
                cancelReason: $cancelled ? $departure->cancel_reason : null,
                vesselName: $product->vessel?->name,
            );
        }

        return $rows;
    }

    /**
     * What one adult pays on that day: the base band's price on the plan that
     * applies to the date, rounded the way the checkout rounds it (PRC-6).
     *
     * The trip's «από» figure when no plan resolves, which is the same number
     * the availability endpoint reports for a departure.
     *
     * @param  EloquentCollection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     */
    private function perPersonCents(Product $product, EloquentCollection $plans, Collection $seasons, Carbon $date): ?int
    {
        $resolved = RatePlanResolver::resolve($plans, $seasons, Carbon::parse($date->toDateString()));
        $base = $product->ageBands->first(static fn (AgeBand $band): bool => (bool) $band->is_base)
            ?? $product->ageBands->first();

        if (! $resolved->isSellable() || ! $base instanceof AgeBand) {
            return $product->price_from_cents;
        }

        $lines = PaxLineBuilder::build($resolved->planOrFail(), $product->ageBands, [$base->code => 1]);

        return $lines === [] ? $product->price_from_cents : $lines[0]->unitPriceCents;
    }

    /**
     * The trips a `trip[]` filter names, as ids; null for «every trip».
     *
     * A slug the operator has since renamed matches nothing and is dropped, and
     * a filter left with nothing in it is no filter: a stale shared link should
     * open the calendar, not an empty page.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @param  list<string>  $slugs
     * @return list<int>|null
     */
    private function wantedProductIds(EloquentCollection $products, array $slugs): ?array
    {
        if ($slugs === []) {
            return null;
        }

        $ids = $products
            ->filter(static fn (Product $product): bool => in_array($product->slug, $slugs, true))
            ->map(static fn (Product $product): int => (int) $product->getKey())
            ->values()
            ->all();

        return $ids === [] ? null : $ids;
    }

    /** @param list<int>|null $wanted */
    private function keeps(DepartureCalendarRow $row, DepartureCalendarCriteria $criteria, ?array $wanted): bool
    {
        if ($wanted !== null && ! in_array((int) $row->product->getKey(), $wanted, true)) {
            return false;
        }

        // A whole-boat day covers every part of it.
        if ($row->kind === DepartureCalendarRow::KIND_DEPARTURE && $row->localTime !== null && ! $criteria->matchesPart($row->localTime)) {
            return false;
        }

        return ! $criteria->onlyFree || $row->isBookable();
    }

    /**
     * The first day after the range with a sailing still on sale.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function nextDate(EloquentCollection $products, Carbon $after, string $timezone): ?string
    {
        $next = Departure::query()
            ->whereIn('product_id', $products->modelKeys())
            ->sellable()
            ->where('is_blocked', false)
            ->where('starts_at_utc', '>=', $after)
            ->orderBy('starts_at_utc')
            ->first(['starts_at_utc']);

        return $next instanceof Departure
            ? LocalDateTimeResolver::localDate($next->starts_at_utc, $timezone)
            : null;
    }

    /** «Τελευταίες N θέσεις» from this many down (the mockup's «λίγες»). */
    public static function fewSeats(): int
    {
        return max(1, (int) config('kaiki.hosted.calendar.few_seats', 5));
    }
}
