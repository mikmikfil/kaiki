<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\DepartureReconciler;
use App\Domain\Availability\Support\Window;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\ScheduleRule;
use App\Models\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A rule's boat changed — its own, or its trip's when it has no override — so
 * its future sailings follow it (audit 2, ADR-0009).
 *
 * A sailing occupies the boat it sails: `vessel_id` decides the charters and
 * blocks it clashes with and the certificate it is held to. Until this, only
 * the capacity followed a boat change, so the old boat looked busy and the new
 * one free, and a charter could be sold on a boat a sailing was using.
 *
 * ADR-0009's line, the same one the capacity sync draws: a sailing nobody has
 * booked or is holding moves, with its capacity capped at the new boat's
 * certificate. One with seats sold or held never moves by itself; it is
 * returned to the caller, listed for the operator, and shown in the
 * reconciliation list ({@see DepartureReconciler::VESSEL_DRIFT}). So is an
 * empty one whose new boat is already taken at that hour.
 */
final class MoveDeparturesToRuleVessel
{
    public function __construct(private readonly RecomputeDepartureBlockedFlags $recompute) {}

    /**
     * @return array{moved: int, kept: Collection<int, Departure>}
     */
    public function __invoke(ScheduleRule $rule, ?Carbon $today = null): array
    {
        $rule = $rule->fresh(['product.vessel', 'vessel']) ?? $rule;
        $vesselId = $rule->effectiveVesselId();

        /** @var Collection<int, Departure> $kept */
        $kept = collect();

        if ($vesselId === null) {
            return ['moved' => 0, 'kept' => $kept];
        }

        $today ??= Carbon::now(LocalDateTimeResolver::timezone());

        $candidates = self::onAnotherVessel($rule, $vesselId, $today);

        $moved = 0;

        foreach ($candidates as $candidate) {
            if ($this->moveOne($candidate, $vesselId, $rule->effectiveCapacity())) {
                $moved++;
            } else {
                $kept->push($candidate->refresh());
            }
        }

        return ['moved' => $moved, 'kept' => $kept];
    }

    /**
     * The rule's future, live sailings that are not on its boat.
     *
     * @return Collection<int, Departure>
     */
    public static function onAnotherVessel(ScheduleRule $rule, int $vesselId, Carbon $today): Collection
    {
        return Departure::query()
            ->where('schedule_rule_id', $rule->getKey())
            ->where('local_date', '>=', $today->toDateString())
            ->where('starts_at_utc', '>', now())
            ->whereIn('status', [DepartureStatus::Scheduled->value, DepartureStatus::Guaranteed->value])
            ->where('vessel_id', '!=', $vesselId)
            ->orderBy('starts_at_utc')
            ->get();
    }

    private function moveOne(Departure $departure, int $vesselId, int $capacity): bool
    {
        $target = DB::transaction(function () use ($departure, $vesselId, $capacity): ?Vessel {
            // AVL-45's order: the boats before the sailing, and the two boats
            // in id order, so two moves in opposite directions cannot deadlock.
            $vessels = Vessel::query()
                ->whereKey([(int) $departure->vessel_id, $vesselId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $target = $vessels->get($vesselId);

            $locked = Departure::query()->lockForUpdate()->find($departure->getKey());

            if (! $target instanceof Vessel || ! $locked instanceof Departure) {
                return null;
            }

            // Somebody booked onto it, or is at the checkout for it: not moved
            // from under them.
            if ($locked->seats_sold > 0 || ReleaseHold::liveHeldSeats($locked) > 0) {
                return null;
            }

            // The new boat taken at that hour (a charter, a block, a sailing
            // with passengers): left where it is, for the operator.
            if (! HoldSeats::vesselIsFreeFor($target, $locked)) {
                return null;
            }

            $locked->forceFill([
                'vessel_id' => $vesselId,
                'capacity' => $capacity,
            ])->save();

            return $target;
        });

        if (! $target instanceof Vessel) {
            return false;
        }

        // The new boat's blocks decide the flag now, not the old boat's.
        $this->recompute->forVesselWindow($target, Window::of($departure->starts_at_utc, $departure->ends_at_utc));

        return true;
    }
}
