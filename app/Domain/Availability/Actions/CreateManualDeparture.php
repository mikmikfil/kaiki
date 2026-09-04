<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\DepartureConflictFinder;
use App\Enums\BookingMode;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Vessel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A one-off departure the operator typed in (spec AVL-52, AVL-11, AVL-12,
 * CAT-14).
 *
 * `schedule_rule_id` stays null, which is what "manual" means in §2.4 — there
 * is no `is_manual` column, and the absence of a rule is the fact.
 *
 * ## It warns rather than blocks, except where the spec says otherwise
 *
 * AVL-11: overlapping zero-sold departures on one boat are legal and useful.
 * The operator schedules two products at the same hour and lets the bookings
 * decide which sails. Blocking that would be wrong; leaving it silent would
 * produce departures that quietly stop being sellable the moment the first seat
 * is committed elsewhere.
 *
 * So a clash with a **different product** is reported and the caller may
 * proceed with `$confirmed`. A clash with the **same product** is refused
 * outright — AVL-12's last sentence — because one trip sold twice on one boat
 * is a mistake, not a strategy.
 *
 * ## Capacity is resolved, not trusted
 *
 * `products.max_pax` by default, capped at the vessel's certificate. An
 * operator typing 40 into a one-off for a boat licensed for 30 has made a
 * mistake the port authority would find, and a manual departure is exactly
 * where that would slip through — the generator resolves it, and this must too.
 */
final class CreateManualDeparture
{
    /**
     * @param  array<string, mixed>  $attributes  local_date, local_time, optional capacity/notes
     *
     * @throws ValidationException
     */
    public function __invoke(Product $product, array $attributes, bool $confirmed = false): Departure
    {
        $this->guardProductMode($product);

        $vessel = $product->vessel;

        if (! $vessel instanceof Vessel) {
            throw ValidationException::withMessages([
                'product_id' => [trans('availability.departure.validation.no_vessel')],
            ]);
        }

        $localDate = (string) ($attributes['local_date'] ?? '');
        $localTime = (string) ($attributes['local_time'] ?? '');

        $timezone = LocalDateTimeResolver::timezone();
        $resolved = LocalDateTimeResolver::resolve($localDate, $localTime, $timezone);

        // ADR-0016: the spring-forward gap. A manual departure is exactly the
        // remedy the operator is offered for a generated one that was skipped,
        // so the message has to say why this particular time will not do.
        if (! $resolved->existent) {
            throw ValidationException::withMessages([
                'local_time' => [trans('availability.departure.validation.dst_nonexistent', [
                    'time' => $localTime,
                    'date' => $localDate,
                ])],
            ]);
        }

        $conflicts = DepartureConflictFinder::forProposed($product, $vessel, $localDate, $localTime);

        $this->guardSameProductOverlap($conflicts, $product);

        if (! $confirmed && $conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'local_time' => [trans('availability.departure.validation.conflict', [
                    'count' => (string) $conflicts->count(),
                    'first' => (string) $conflicts->first()->local_time,
                ])],
            ]);
        }

        $starts = $resolved->instantOrFail();
        $capacity = $this->capacityFor($product, $vessel, $attributes['capacity'] ?? null);

        return DB::transaction(fn (): Departure => Departure::query()->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
            // The fact that makes it manual (§2.4).
            'schedule_rule_id' => null,
            'local_date' => LocalDateTimeResolver::localDate($starts, $timezone),
            'local_time' => LocalDateTimeResolver::localTime($starts, $timezone),
            'starts_at_utc' => $starts,
            'ends_at_utc' => LocalDateTimeResolver::endsAt($starts, (int) $product->duration_minutes),
            'dst_ambiguous' => $resolved->ambiguous,
            'capacity' => $capacity,
            'min_pax' => (int) $product->min_pax,
            'status' => DepartureStatus::Scheduled,
            'notes' => $attributes['notes'] ?? null,
        ]));
    }

    /** @throws ValidationException */
    private function guardProductMode(Product $product): void
    {
        if ($product->mode === BookingMode::PerSeat) {
            return;
        }

        // A charter is booked as a window against the boat's calendar, not as a
        // seat on a sailing. A departure there would be a row nothing sells.
        throw ValidationException::withMessages([
            'product_id' => [trans('availability.departure.validation.not_per_seat', [
                'mode' => $product->mode->label(),
            ])],
        ]);
    }

    /**
     * @param  Collection<int, Departure>  $conflicts
     *
     * @throws ValidationException
     */
    private function guardSameProductOverlap($conflicts, Product $product): void
    {
        $sameProduct = $conflicts->first(
            static fn (Departure $departure): bool => $departure->product_id === $product->getKey(),
        );

        if ($sameProduct === null) {
            return;
        }

        throw ValidationException::withMessages([
            'local_time' => [trans('availability.departure.validation.same_product_overlap', [
                'date' => $sameProduct->local_date->toDateString(),
                'time' => (string) $sameProduct->local_time,
            ])],
        ]);
    }

    /** `products.max_pax`, capped at the vessel's certificate (AVL-55, CAT-5). */
    private function capacityFor(Product $product, Vessel $vessel, mixed $requested): int
    {
        $capacity = $requested === null || $requested === ''
            ? (int) $product->max_pax
            : (int) $requested;

        return max(0, min($capacity, (int) $vessel->capacity_max));
    }
}
