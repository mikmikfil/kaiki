<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\VesselWindowData;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\LocalDay;
use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\ProposedWindowBuilder;
use App\Domain\Availability\Support\Window;
use App\Enums\AvailabilityRejection;
use App\Enums\BookingMode;
use App\Enums\ProductStatus;
use App\Enums\VesselStatus;
use App\Models\Product;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

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

        $windows = [];

        foreach ($request->dates() as $date) {
            $windows[] = $this->evaluateDate($product, $date, $startTime, $extraHours, $occupations);
        }

        return $windows;
    }

    /** One date, against the preloaded occupations. */
    private function evaluateDate(
        Product $product,
        Carbon $date,
        ?string $startTime,
        int $extraHours,
        OccupationCollector $occupations,
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
