<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Contracts\DeparturePersonsAboard;
use App\Enums\AvailabilityRejection;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
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
 * 2. **Not enough seats** (AVL-23) — pick another date, or fewer people.
 * 3. **Legal capacity** (AVL-25) — fewer people, and only fewer *people*.
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
    /** @param iterable<DeparturePersonsAboard> $personsAboard */
    public function __construct(private readonly iterable $personsAboard = []) {}

    /**
     * The one rule that holds before any date is looked at (AVL-26).
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
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<string, int>  $pax  band code => how many
     */
    public function blanket(Collection $bands, array $pax): ?AvailabilityRejection
    {
        // An empty party is a calendar asking what exists, not a family asking
        // for four seats — so there is nothing to judge.
        if ($pax === [] || CountedSeats::hasCountedPax($bands, $pax)) {
            return null;
        }

        return AvailabilityRejection::NoCountedPax;
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

        if (($blanket = $this->blanket($bands, $pax)) !== null) {
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
        $ceiling = $product->vessel?->capacity_max;

        if ($ceiling === null) {
            return false;
        }

        $aboard = 0;

        if ($departure instanceof Departure) {
            foreach ($this->personsAboard as $source) {
                $aboard = max($aboard, $source->personsAboard($departure));
            }
        }

        return $aboard + CountedSeats::totalPersons($pax) > $ceiling;
    }
}
