<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Contracts\ProductBookingCount;
use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Enums\BookingMode;
use App\Enums\ProductStatus;
use App\Exceptions\ProductModeLocked;
use App\Models\Product;
use App\Rules\FlexibleStartOnlyPerVessel;
use App\Rules\ItineraryStopsShape;
use App\Rules\MaxPaxWithinVesselCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Traversable;

/**
 * Save a product, and refuse the ways it can be made incoherent
 * (spec CAT-4, CAT-5, `docs/data-model.md` §2.3).
 *
 * The panel calls this, and so will `POST /api/v1/products` and the WooCommerce
 * importer. Everything here is a rule that must hold whoever is writing —
 * anything that is merely a form affordance stays in the Filament resource
 * (#24).
 *
 * ## `mode` is immutable once a booking exists
 *
 * §2.3: *"a `per_seat` product that becomes `per_vessel` would invalidate every
 * departure and every price snapshot's meaning."* Not a database constraint —
 * the database cannot see `bookings` from here, and that table does not exist
 * until M2.
 *
 * So the guard ships against {@see ProductBookingCount}, an interface with
 * **no implementation registered today**. It correctly refuses nothing,
 * because nothing can yet hold a booking; M2 adds one class and one `tag()`
 * line, and the refusal, its message and its test are already built. Exactly
 * the shape #16 used for `GuardVesselCapacity`, and for the same reason:
 * writing the guard when the table arrives means writing it under time pressure
 * in a milestone that is already the largest.
 *
 * ## CAT-15 is a gate here, and a checklist in the panel
 *
 * A product may not go `active` while a prerequisite is missing. Enforced here
 * rather than in the Filament resource because the panel is only the first of
 * three writers — `POST /api/v1/products` and the importer will both be able to
 * set a status, and a rule that lives in a form is a rule neither of them has.
 * The refusal names the unmet requirement **keys** from
 * {@see ProductPublishChecklist}, which the panel renders as a checklist and
 * the API will return as error codes.
 *
 * ## What is *not* here
 *
 * `max_pax` against the vessel ceiling and `flexible_start` against the mode
 * are validation rules ({@see MaxPaxWithinVesselCapacity},
 * {@see FlexibleStartOnlyPerVessel}), because they belong beside the
 * field where an operator can act on them. This Action re-checks the itinerary
 * shape because an importer has no form to attach a rule to.
 */
final class SaveProduct
{
    public const TAG = 'product.booking-counts';

    /** @param iterable<ProductBookingCount> $bookingCounts */
    public function __construct(private readonly iterable $bookingCounts = []) {}

    /**
     * @param  array<string, mixed>  $attributes  already validated by the caller
     *
     * @throws ProductModeLocked
     * @throws ValidationException
     */
    public function __invoke(Product $product, array $attributes): Product
    {
        $this->guardModeChange($product, $attributes);
        $this->guardItineraryShape($attributes);

        return DB::transaction(function () use ($product, $attributes): Product {
            $product->fill($attributes);

            // CAT-5: `min_pax` is the guaranteed-departure threshold and only
            // means anything when seats are counted. Zeroed rather than
            // refused — an operator switching a draft from per-seat to a
            // charter should not have to hunt for a field that stopped
            // applying, and a stale value would silently gate departures if
            // they switched back.
            if (! $product->mode->usesMinPax()) {
                $product->min_pax = 0;
            }

            // Same reasoning for the flexible window. The validation rule
            // refuses the *flag* on the wrong mode; this clears the two times
            // that only meant something alongside it.
            if (! $product->mode->allowsFlexibleStart()) {
                $product->flexible_start = false;
                $product->earliest_start_time = null;
                $product->latest_start_time = null;
            }

            $this->guardPublishable($product);

            $product->save();

            return $product->refresh();
        });
    }

    /**
     * CAT-15: a product may not go `active` while a prerequisite is missing.
     *
     * Checked **after** `fill()` and inside the transaction, on the product as
     * it would be saved. Checking the attributes array instead would miss the
     * half of the checklist that lives in other tables, and checking the
     * unfilled model would refuse an operator who supplied the missing vessel
     * in the very same submit.
     *
     * The exception names the unmet requirement **keys** rather than sentences.
     * The panel renders them as a checklist, the API will return them as error
     * codes, and neither has to parse the other's wording.
     *
     * A product leaving `active` is never blocked: unpublishing something
     * incomplete is the fix, not another thing to refuse.
     *
     * @throws ValidationException
     */
    private function guardPublishable(Product $product): void
    {
        if ($product->status !== ProductStatus::Active) {
            return;
        }

        $unmet = ProductPublishChecklist::unmet($product);

        if ($unmet === []) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => array_map(
                static fn (string $key): string => trans("catalog.product.checklist.{$key}.unmet"),
                $unmet,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ProductModeLocked
     */
    private function guardModeChange(Product $product, array $attributes): void
    {
        if (! $product->exists || ! array_key_exists('mode', $attributes)) {
            return;
        }

        $requested = $attributes['mode'] instanceof BookingMode
            ? $attributes['mode']
            : BookingMode::tryFrom((string) $attributes['mode']);

        if ($requested === null || $requested === $product->mode) {
            return;
        }

        $bookings = $this->countBookings($product);

        if ($bookings > 0) {
            throw ProductModeLocked::forProduct($product, $requested, $bookings);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardItineraryShape(array $attributes): void
    {
        if (! array_key_exists('itinerary_stops', $attributes)) {
            return;
        }

        // Through the framework's validator rather than by calling the rule
        // directly: the rule reports through a `$fail` callback, and hand-rolling
        // a collector for it is both more code and a second definition of what a
        // failure looks like. This way an importer gets the same
        // `ValidationException` the panel does, with the same messages.
        Validator::make(
            ['itinerary_stops' => $attributes['itinerary_stops']],
            ['itinerary_stops' => [new ItineraryStopsShape]],
        )->validate();
    }

    /**
     * How many bookings this product already has.
     *
     * Zero today, from an empty source list — and correctly so: `bookings`
     * arrives in M2. The sum rather than the first answer, because a later
     * milestone may well have more than one thing that counts as a commitment.
     */
    private function countBookings(Product $product): int
    {
        $total = 0;

        foreach ($this->sources() as $source) {
            $total += $source->countFor($product);
        }

        return $total;
    }

    /** @return iterable<ProductBookingCount> */
    private function sources(): iterable
    {
        return $this->bookingCounts instanceof Traversable
            ? iterator_to_array($this->bookingCounts)
            : $this->bookingCounts;
    }
}
