<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Models\Departure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit a departure, and refuse the edits that would strand a guest
 * (spec AVL-52, AVL-21).
 *
 * ## Three refusals, all of them about somebody who has already paid
 *
 * - **Moving a sold departure's date or time.** A guest booked 09:00 on the
 *   4th. Changing the row changes what they turn up to without telling them,
 *   and the ticket, the calendar entry and the reminder email were all sent
 *   with the old time. The remedy is to cancel and rebook, which is visible.
 * - **Lowering capacity below `seats_sold`.** The seats are already sold. A
 *   capacity of 8 on a departure with 10 aboard is not a smaller boat; it is a
 *   number that makes the availability arithmetic negative and the manifest
 *   wrong.
 * - **Changing the product.** The departure is the sellable instance *of* a
 *   product. Repointing it would re-price every booking on it retroactively.
 *
 * Everything else — notes, capacity upward, capacity down to at least the sold
 * count — is ordinary operator work and passes through.
 */
final class UpdateDeparture
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function __invoke(Departure $departure, array $attributes): Departure
    {
        $this->guardImmutableProduct($departure, $attributes);

        if ($departure->seats_sold > 0 || $departure->seats_held > 0) {
            $this->guardTimeChange($departure, $attributes);
        }

        $this->guardCapacity($departure, $attributes);

        return DB::transaction(function () use ($departure, $attributes): Departure {
            // The time triple is written together or not at all: CNV-3's guard
            // refuses a half-updated row, which is the correct outcome and a
            // confusing one to debug. Only the fields below are editable here;
            // moving a departure is a create-and-cancel, not an update.
            $departure->fill(array_intersect_key($attributes, array_flip([
                'capacity', 'notes', 'status',
            ])));

            $departure->save();

            return $departure->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardImmutableProduct(Departure $departure, array $attributes): void
    {
        $productId = $attributes['product_id'] ?? null;

        if ($productId === null || (int) $productId === $departure->product_id) {
            return;
        }

        throw ValidationException::withMessages([
            'product_id' => [trans('availability.departure.validation.product_immutable')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardTimeChange(Departure $departure, array $attributes): void
    {
        $date = $attributes['local_date'] ?? null;
        $time = $attributes['local_time'] ?? null;

        $dateMoved = $date !== null && (string) $date !== $departure->local_date->toDateString();
        $timeMoved = $time !== null && $this->normalise((string) $time) !== $this->normalise((string) $departure->local_time);

        if (! $dateMoved && ! $timeMoved) {
            return;
        }

        throw ValidationException::withMessages([
            'local_time' => [trans('availability.departure.validation.sold_time_locked', [
                'sold' => (string) $departure->seats_sold,
            ])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardCapacity(Departure $departure, array $attributes): void
    {
        $capacity = $attributes['capacity'] ?? null;

        if ($capacity === null || $capacity === '') {
            return;
        }

        if ((int) $capacity >= $departure->seats_sold) {
            return;
        }

        throw ValidationException::withMessages([
            'capacity' => [trans('availability.departure.validation.capacity_below_sold', [
                'sold' => (string) $departure->seats_sold,
            ])],
        ]);
    }

    private function normalise(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }
}
