<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\VesselWindowData;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\BookingCutoff;
use App\Domain\Availability\Support\LocalDay;
use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\ProposedWindowBuilder;
use App\Domain\Availability\Support\Window;
use App\Domain\Pricing\Support\SeasonCandidateResolver;
use App\Enums\AvailabilityRejection;
use App\Enums\BookingMode;
use App\Enums\ProductStatus;
use App\Enums\VesselStatus;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Can a guest take the whole boat on these dates (spec AVL-30 to AVL-34)?
 *
 * ## A charter asks a different question from a seat
 *
 * {@see CheckSeatAvailability} asks "are there seats left on this sailing".
 * This asks "is the boat free at all", and the two disagree in a way that is
 * the heart of AVL-32: **a scheduled departure with no seats sold does not
 * block a private window.** An operator who lists a shared cruise every Tuesday
 * and is offered a charter for one of them takes the charter; the empty
 * departure was a hope, not a commitment.
 *
 * One seat sold changes that completely, and AVL-34 is blunt about the
 * consequence: *"There is no override in the guest flow; the operator may
 * resolve it manually in the panel."* A guest cannot buy their way past
 * somebody who already booked.
 *
 * ## What this issue deliberately does not do
 *
 * AVL-32's second half — auto-cancelling the taken-over departure with
 * `vessel_booked_privately` — happens on **confirmation**, inside the booking
 * transaction, in M2. Doing it here, or at hold time, would let an abandoned
 * cart destroy a departure. The ADR says so explicitly and this class is the
 * place someone would be tempted.
 *
 * ## Bounded queries (NFR-6)
 *
 * Two: the vessel's departures across the whole range and its blocks, loaded
 * once by {@see OccupationCollector}. Every date is then decided in PHP, so a
 * 62-day charter calendar costs what one day costs.
 */
final class CheckVesselAvailability
{
    /**
     * @param  string|null  $startTime  a guest proposal, when `flexible_start`
     * @return list<VesselWindowData> one entry per requested date, always
     */
    public function __invoke(
        Product $product,
        AvailabilityRequestData $request,
        ?string $startTime = null,
        int $extraHours = 0,
    ): array {
        $timezone = LocalDateTimeResolver::timezone();
        $vessel = $product->vessel;

        $blanket = $this->blanketRejection($product, $vessel);

        if ($blanket !== null) {
            return array_map(
                static fn (Carbon $date): VesselWindowData => VesselWindowData::refused($date->toDateString(), $blanket),
                $request->dates(),
            );
        }

        /** @var Vessel $vessel */
        $occupations = OccupationCollector::forRange($vessel, $this->rangeWindow($request, $timezone));
        [$plans, $seasons] = $this->cutoffInputs($product);

        $windows = [];

        foreach ($request->dates() as $date) {
            $windows[] = $this->evaluateDate($product, $date, $startTime, $extraHours, $occupations, $plans, $seasons);
        }

        return $windows;
    }

    /**
     * The same answer, against occupations a caller has already loaded.
     *
     * The departures calendar (2026-09-25) shows every charter an operator
     * sells beside every shared trip, and loads the whole fleet's occupations
     * once through {@see OccupationCollector::forVessels()} rather than twice
     * per boat. The dates are then decided here, by the rules `__invoke` uses,
     * with the product's default start and no proposal of the guest's.
     *
     * The calendar has the plans and the seasons loaded for the whole
     * catalogue already and passes them, so a fleet of charters costs no
     * query per charter; without them they are read here, as `__invoke` does.
     *
     * @param  list<Carbon>  $dates
     * @param  Collection<int, RatePlan>|null  $plans  this product's active plans
     * @param  Collection<int, Season>|null  $seasons
     * @return list<VesselWindowData> one entry per date
     */
    public function forDates(
        Product $product,
        array $dates,
        OccupationCollector $occupations,
        ?Collection $plans = null,
        ?Collection $seasons = null,
    ): array {
        $blanket = $this->blanketRejection($product, $product->vessel);

        if ($blanket === null && ($plans === null || $seasons === null)) {
            [$plans, $seasons] = $this->cutoffInputs($product);
        }

        $plans ??= collect();
        $seasons ??= collect();

        $windows = [];

        foreach ($dates as $date) {
            $windows[] = $blanket === null
                ? $this->evaluateDate($product, $date, null, 0, $occupations, $plans, $seasons)
                : VesselWindowData::refused($date->toDateString(), $blanket);
        }

        return $windows;
    }

    /**
     * One date, against the preloaded occupations.
     *
     * @param  Collection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     */
    private function evaluateDate(
        Product $product,
        Carbon $date,
        ?string $startTime,
        int $extraHours,
        OccupationCollector $occupations,
        Collection $plans,
        Collection $seasons,
    ): VesselWindowData {
        $key = $date->toDateString();

        ['window' => $window, 'rejection' => $rejection] = ProposedWindowBuilder::build(
            $product,
            $date,
            $startTime,
            $extraHours,
        );

        // An off-grid or out-of-hours proposal has no window to test, so there
        // is nothing to report but the reason.
        if ($window === null || $rejection !== null) {
            return VesselWindowData::refused($key, $rejection ?? AvailabilityRejection::NoProposedWindow);
        }

        // AVL-19 and AVL-20 (2026-09-25). Missing until then: a charter offered
        // every date of the month, yesterday included, and the guest who chose
        // one was refused by the quote. {@see BookingCutoff} is the rule the
        // seat calendar, the quote and the booking ask too.
        $cutoff = BookingCutoff::checkAgainst($plans, $seasons, $window->startUtc, $key, LocalDateTimeResolver::timezone());

        if ($cutoff !== null) {
            return VesselWindowData::forWindow($key, $window, false, null, $cutoff);
        }

        if ($occupations->isFree($window)) {
            return VesselWindowData::forWindow($key, $window, true, $product->price_from_cents);
        }

        return VesselWindowData::forWindow(
            $key,
            $window,
            false,
            null,
            // AVL-33: a window held by another guest is a different answer from
            // a window that is booked. One is worth waiting twenty minutes for.
            $occupations->isHeldPrivately($window)
                ? AvailabilityRejection::VesselHeld
                : AvailabilityRejection::VesselBusy,
        );
    }

    /**
     * The product's active plans and the tenant's seasons, for the cutoff.
     *
     * The plans the caller already loaded when it did (`findForAvailability()`
     * narrows the relation to the active ones), so `GET /availability` spends
     * no query on them; the seasons are one read for the whole range.
     *
     * @return array{0: Collection<int, RatePlan>, 1: Collection<int, Season>}
     */
    private function cutoffInputs(Product $product): array
    {
        $plans = $product->relationLoaded('ratePlans')
            ? $product->ratePlans->filter(static fn (RatePlan $plan): bool => $plan->is_active)->values()
            : RatePlan::query()->where('product_id', $product->getKey())->active()->get();

        return [$plans, SeasonCandidateResolver::allWithRanges()];
    }

    /** The conditions that hold for every date (AVL-22.6, AVL-22.7). */
    private function blanketRejection(Product $product, ?Vessel $vessel): ?AvailabilityRejection
    {
        // TEN-9: a lapsed subscription stops new bookings, and a charter is a
        // new booking.
        if (Tenancy::current()?->allowsWrites() === false) {
            return AvailabilityRejection::TenantReadOnly;
        }

        // A per-seat product has departures and is #30's question. Answering it
        // here would quietly ignore every seat already sold.
        if ($product->mode !== BookingMode::PerVessel) {
            return AvailabilityRejection::ProductNotActive;
        }

        if ($product->status !== ProductStatus::Active) {
            return AvailabilityRejection::ProductNotActive;
        }

        if (! $vessel instanceof Vessel || $vessel->status !== VesselStatus::Active) {
            return AvailabilityRejection::VesselNotActive;
        }

        return null;
    }

    /** The UTC window covering every requested local date. */
    private function rangeWindow(AvailabilityRequestData $request, string $timezone): Window
    {
        return Window::of(
            LocalDay::of($request->from, $timezone)->startUtc,
            LocalDay::of($request->to, $timezone)->endUtcExclusive,
        );
    }
}
