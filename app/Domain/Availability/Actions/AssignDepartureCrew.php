<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Enums\DepartureStatus;
use App\Mail\CrewAssignedMail;
use App\Models\Departure;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Captain and crew for one departure (the 24/9 list, #3).
 *
 * The captain is one of the operator's people or a typed name, never both —
 * a person chosen wins, and a name typed beside them is dropped rather than
 * printed as a second captain. The crew are the operator's people; the captain
 * is not listed twice.
 *
 * **Everyone must be this operator's.** The ids come from a form, and a user id
 * from another tenant would put a stranger's name on this boat's passenger list
 * — so each is checked against the departure's own tenant, not trusted.
 *
 * **Nobody on two departures at once** (Mike, 2026-09-24: «not allowed»). A
 * person already on another departure whose time overlaps this one — as its
 * captain or its crew, and not cancelled — is refused, naming the other one.
 *
 * **An email to whoever is newly added** (Mike, same day). Saving again sends
 * nothing to the people already on it.
 */
final class AssignDepartureCrew
{
    /**
     * @param  list<int|string>  $crewUserIds
     *
     * @throws ValidationException
     */
    public function __invoke(Departure $departure, int|string|null $captainUserId, ?string $captainName, array $crewUserIds): Departure
    {
        $captainUserId = $captainUserId === null || $captainUserId === '' ? null : (int) $captainUserId;
        $crew = array_values(array_diff(array_unique(array_map('intval', $crewUserIds)), [$captainUserId]));
        $ids = array_values(array_filter([$captainUserId, ...$crew]));

        if ($ids !== []) {
            $known = User::query()
                ->where('tenant_id', $departure->tenant_id)
                ->whereKey($ids)
                ->count();

            if ($known !== count($ids)) {
                throw ValidationException::withMessages([
                    'crew_user_ids' => [trans('availability.departure.crew.validation.not_ours')],
                ]);
            }

            $this->guardOverlaps($departure, $ids);
        }

        $before = array_values(array_filter([
            $departure->captain_user_id,
            ...array_map('intval', (array) ($departure->crew_user_ids ?? [])),
        ]));

        $name = $captainName === null ? null : trim($captainName);

        $departure->forceFill([
            'captain_user_id' => $captainUserId,
            'captain_name' => $captainUserId === null && $name !== '' ? $name : null,
            'crew_user_ids' => $crew === [] ? null : $crew,
            // Changed by hand for this one day: the schedule's crew no longer
            // reaches it.
            'crew_from_rule' => false,
        ])->save();

        foreach (array_diff($ids, $before) as $newcomer) {
            $person = User::query()->find($newcomer);

            if ($person instanceof User && filter_var($person->email, FILTER_VALIDATE_EMAIL) !== false) {
                Mail::to($person->email)->queue(new CrewAssignedMail($person, $departure, $newcomer === $captainUserId));
            }
        }

        return $departure;
    }

    /**
     * @param  list<int>  $ids
     *
     * @throws ValidationException
     */
    private function guardOverlaps(Departure $departure, array $ids): void
    {
        $others = Departure::query()
            ->with(['product', 'vessel'])
            ->whereKeyNot($departure->getKey())
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->where('starts_at_utc', '<', $departure->ends_at_utc)
            ->where('ends_at_utc', '>', $departure->starts_at_utc)
            ->where(static function ($query) use ($ids): void {
                $query->whereIn('captain_user_id', $ids)->orWhereNotNull('crew_user_ids');
            })
            ->get();

        $messages = [];

        foreach ($others as $other) {
            $on = array_values(array_intersect($ids, array_filter([
                $other->captain_user_id,
                ...array_map('intval', (array) ($other->crew_user_ids ?? [])),
            ])));

            foreach ($on as $id) {
                $messages[] = trans('availability.departure.crew.validation.overlap', [
                    'name' => (string) User::query()->whereKey($id)->value('name'),
                    'trip' => (string) ($other->product->title),
                    'boat' => (string) ($other->vessel->name),
                    'time' => substr((string) $other->local_time, 0, 5),
                ]);
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['crew_user_ids' => array_values(array_unique($messages))]);
        }
    }
}
