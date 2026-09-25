<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Availability\Contracts\BulkDeparturePersonsAboard;
use App\Domain\Availability\Contracts\DeparturePersonsAboard;
use App\Enums\AvailabilityRejection;
use App\Enums\BookingMode;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Collection;

/**
 * Can *this party* board *this departure* (spec AVL-23, AVL-25, AVL-26)?
 *
 * The three rules that depend on who is travelling, as opposed to the ones that
 * depend on the date. Extracted from {@see CheckSeatAvailability} in #37 because
 * `POST /price-quote` has to ask exactly the same question and there must not be
 * two answers: a party the calendar accepted and the quote refused — or worse,
 * the reverse — is a guest watching the site contradict itself.
 *
 * The date rules (lead time, advance window, vessel conflicts) stay in the
 * availability action. They need the rate plans, the seasons and the whole
 * occupation set, and a quote for a departure the engine has already offered
 * does not need to re-litigate them.
 *
 * ## Order is significance
 *
 * Same principle as {@see AvailabilityRejection} itself: a party can fail more
 * than one of these at once, and the reported reason is the first that applies
 * because that is the one the guest can act on.
 *
 * 1. **No counted pax** (AVL-26) — add an adult. Nothing else will help.
 * 2. **Needs an adult** (AVL-26b) — the same remedy, for a party that does take
 *    seats: children whose band says they travel with one.
 * 3. **Too few / too many for one booking** (CAT-5) — the trip's own bounds.
 * 4. **Not enough seats** (AVL-23) — pick another date, or fewer people.
 * 5. **Legal capacity** (AVL-25) — fewer people, and only fewer *people*.
 *
 * ## Why 2 and 3 are separate, restated where it is easy to merge them
 *
 * AVL-25 is marked RESOLVED with its reasoning attached: *"conflating them
 * would let a boat sail illegally full of infants."* Two adults and two infants
 * is **two seats and four people**. The commercial check compares counted seats
 * against what is left to sell; the legal one compares every body aboard
 * against the boat's certificate. A party can pass the first and fail the
 * second, and telling that family "the boat is full" would be both false and
 * unactionable.
 */
final class PartyGuard
{
    /**
     * The sources arrive through the tag, not by default (2026-09-25). Until
     * then the container built this with its default `[]` — nobody bound the
     * tag to the constructor — so every legal check in production counted the
     * party alone and nobody already aboard.
     *
     * @param  iterable<DeparturePersonsAboard>  $personsAboard
     */
    public function __construct(
        #[Tag(CheckSeatAvailability::TAG)]
        private readonly iterable $personsAboard = [],
    ) {}

    /** @var array<int, int> departure id => persons, from {@see self::preloadPersonsAboard()} */
    private array $knownAboard = [];

    /**
     * The rules that hold before any date is looked at (AVL-26, AVL-26b).
     *
     * Both are about who is in the party and nothing else, so they answer the
     * same whichever sailing is asked about — which is what makes them safe to
     * apply to a whole range of dates at once.
     *
     * Deliberately **not** the whole set. Legal capacity depends on who is
     * already aboard a specific departure, so evaluating it here would refuse
     * every date in a range on the strength of a boat that has not been chosen
     * yet — and would report `legal_capacity_exceeded` where the honest answer
     * is `not_enough_seats` on the one sailing the guest actually asked about.
     *
     * That is not hypothetical: folding it in is exactly what broke
     * `LegalCapacityTest`'s "reports the seat shortage first" case, which
     * exists because the two codes send a guest to different remedies.
     *
     * The trip's per-booking bounds (`min_booking_pax`, `max_pax`) are asked
     * too when the trip is given (2026-09-25): like the two rules above they
     * depend on the party alone, and the calendar already greyed such a party
     * out (`SearchCatalogue::fitsParty`) while the quote and the booking let it
     * through.
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax  band code => how many
     */
    public function blanket(Collection $bands, array $pax, ?Product $product = null): ?AvailabilityRejection
    {
        // An empty party is a calendar asking what exists, not a family asking
        // for four seats — so there is nothing to judge.
        if ($pax === []) {
            return null;
        }

        if (! CountedSeats::hasCountedPax($bands, $pax)) {
            return AvailabilityRejection::NoCountedPax;
        }

        // AVL-26b, and it has to be *after* the rule above rather than merged
        // with it: a party of infants alone fails both, and `no_counted_pax`
        // is the more precise of the two sentences. Two children with no adult
        // only reaches here — they take seats, so the infants rule lets them
        // through, and until this line nothing else looked.
        if (CountedSeats::escortMissing($bands, $pax)) {
            return AvailabilityRejection::NeedsAdult;
        }

        return $product instanceof Product ? $this->bounds($bands, $pax, $product) : null;
    }

    /**
     * The trip's per-booking bounds (CAT-5): at least `min_booking_pax`, at
     * most `max_pax`, both counted in seats — the unit the trip form names
     * them in. Infants are the certificate's business, below.
     *
     * `$liftMax` is BKG-32's override. `max_pax` is a commercial number the
     * operator chose, like a departure's capacity, so the explicit, logged
     * override lifts it too; the minimum is the operator's own rule and stays.
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax
     */
    public function bounds(Collection $bands, array $pax, Product $product, bool $liftMax = false): ?AvailabilityRejection
    {
        $seats = CountedSeats::counted($bands, $pax);

        if ($seats < max(1, (int) $product->min_booking_pax)) {
            return AvailabilityRejection::TooFewPax;
        }

        if (! $liftMax && $product->max_pax > 0 && $seats > $product->max_pax) {
            return AvailabilityRejection::TooManyPax;
        }

        return null;
    }

    /**
     * May this party be written as a booking (2026-09-25)?
     *
     * The door every booking write goes through — `CreateBookingDraft`, so the
     * API, the panel and the quay alike. The party rules, the bounds, and for a
     * charter the certificate: a whole-boat booking has nobody else aboard, so
     * the party alone is the headcount. A per-seat booking's certificate needs
     * the people already on that sailing, counted under the departure's lock,
     * so it is {@see HoldSeats}' question — asked of this same class.
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax
     * @param  bool  $commercialOverride  BKG-32: lifts `max_pax`, never the certificate
     */
    public function admit(Collection $bands, array $pax, Product $product, bool $commercialOverride = false): ?AvailabilityRejection
    {
        if ($pax === []) {
            return null;
        }

        $rejection = $this->blanket($bands, $pax) ?? $this->bounds($bands, $pax, $product, liftMax: $commercialOverride);

        if ($rejection !== null) {
            return $rejection;
        }

        if ($product->mode !== BookingMode::PerSeat
            && $this->exceedsCertificate($product->vessel?->capacity_max, CountedSeats::totalPersons($pax))) {
            return AvailabilityRejection::LegalCapacityExceeded;
        }

        return null;
    }

    /**
     * AVL-25, the one sum: everyone already aboard plus `$persons` against the
     * certificate. Infants and every band that takes no seat are people here.
     *
     * `$alreadyCounted` is the part of the aboard figure that is the very
     * booking being judged — a re-held draft or a lapsed hold being taken back
     * — so it is not counted twice. Null `$ceiling` is a boat with no
     * certificate on file, which nothing can exceed.
     */
    public function exceedsCertificate(?int $ceiling, int $persons, ?Departure $departure = null, int $alreadyCounted = 0): bool
    {
        if ($ceiling === null) {
            return false;
        }

        $aboard = $departure instanceof Departure ? max(0, $this->personsAboard($departure) - $alreadyCounted) : 0;

        return $aboard + $persons > $ceiling;
    }

    /**
     * Read the headcount of a whole range in one query, for the calendar
     * (NFR-7). Only sailings with something sold or held can have anyone
     * aboard, so a range with none costs nothing.
     *
     * @param  iterable<Departure>  $departures
     */
    public function preloadPersonsAboard(iterable $departures): void
    {
        $this->knownAboard = [];

        $busy = [];

        foreach ($departures as $departure) {
            if ($departure->seats_sold > 0 || $departure->seats_held > 0) {
                $busy[] = $departure;
            }
        }

        if ($busy === []) {
            return;
        }

        $known = array_fill_keys(array_map(static fn (Departure $d): int => (int) $d->getKey(), $busy), 0);

        foreach ($this->personsAboard as $source) {
            if (! $source instanceof BulkDeparturePersonsAboard) {
                // A source that can only answer one at a time is asked one at
                // a time, below; nothing is memoised for it.
                $this->knownAboard = [];

                return;
            }

            foreach ($source->personsAboardMany($busy) as $id => $persons) {
                $known[$id] = max($known[$id] ?? 0, $persons);
            }
        }

        $this->knownAboard = $known;
    }

    /** Everyone already aboard this sailing, counted and non-counted alike. */
    public function personsAboard(Departure $departure): int
    {
        if (array_key_exists((int) $departure->getKey(), $this->knownAboard)) {
            return $this->knownAboard[(int) $departure->getKey()];
        }

        $aboard = 0;

        foreach ($this->personsAboard as $source) {
            $aboard = max($aboard, $source->personsAboard($departure));
        }

        return $aboard;
    }

    /**
     * The first rule this party fails, or null.
     *
     * `$departure` is null for a `per_vessel` charter, where there is no
     * sailing to count seats against — the boat is taken whole, so only the
     * certificate applies.
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax  band code => how many
     */
    public function check(Collection $bands, array $pax, Product $product, ?Departure $departure = null): ?AvailabilityRejection
    {
        if ($pax === []) {
            return null;
        }

        if (($blanket = $this->blanket($bands, $pax, $product)) !== null) {
            return $blanket;
        }

        if ($departure instanceof Departure
            && CountedSeats::counted($bands, $pax) > $departure->seatsAvailable()) {
            return AvailabilityRejection::NotEnoughSeats;
        }

        if ($this->wouldExceedLegalCapacity($product, $pax, $departure)) {
            return AvailabilityRejection::LegalCapacityExceeded;
        }

        return null;
    }

    /**
     * AVL-25: everyone aboard, infants included, against the certificate.
     *
     * With no departure — a charter quote — there is nobody already aboard to
     * count, so the party alone is measured against the boat. That is the right
     * reading: a `per_vessel` booking takes the whole vessel, so anyone else
     * being aboard is the conflict `CheckVesselAvailability` refuses, not a
     * capacity sum.
     *
     * @param  array<string, int>  $pax
     */
    private function wouldExceedLegalCapacity(Product $product, array $pax, ?Departure $departure): bool
    {
        return $this->exceedsCertificate($product->vessel?->capacity_max, CountedSeats::totalPersons($pax), $departure);
    }
}
