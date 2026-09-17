<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Enums\DepartureStatus;
use App\Events\DepartureGuaranteed;
use App\Events\MinimumWaived;
use App\Models\Departure;
use Illuminate\Support\Facades\DB;

/**
 * «Φεύγει κανονικά»: the operator decides a sailing short of its minimum goes
 * anyway (product owner, 2026-09-17).
 *
 * ## Stored as `guaranteed`, not as a new flag
 *
 * `guaranteed` already means exactly this — *"the operator has committed to
 * sailing"* (`DepartureStatus`) — and it is already the state AVL-49 never
 * reverses. Reaching it by seats (AVL-48) or by the operator saying so are two
 * roads to one promise, so a second column recording the same promise would
 * be two answers to one question. The attention list asks only about
 * `scheduled` departures, so a guaranteed one stops being a decision.
 *
 * What differs is the trail: seats reaching the minimum is a fact, this is a
 * choice, and a choice to run a boat half-empty is an `override.applied` row
 * ({@see MinimumWaived}) with the numbers it was made against.
 *
 * Idempotent, and a no-op on anything that is not `scheduled`: a cancelled
 * sailing cannot be un-cancelled from here, and a guaranteed one has nothing
 * left to decide.
 */
final class SailBelowMinimum
{
    /** @return bool whether the departure changed */
    public function __invoke(Departure $departure): bool
    {
        $changed = DB::transaction(function () use ($departure): bool {
            /** @var Departure $locked */
            $locked = Departure::query()->lockForUpdate()->findOrFail($departure->getKey());

            if ($locked->status !== DepartureStatus::Scheduled) {
                return false;
            }

            $locked->forceFill(['status' => DepartureStatus::Guaranteed])->save();

            return true;
        });

        $departure->refresh();

        if (! $changed) {
            return false;
        }

        // After commit, like every other event in the booking domain.
        MinimumWaived::dispatch($departure);
        DepartureGuaranteed::dispatch((int) $departure->getKey(), (int) $departure->tenant_id);

        return true;
    }
}
