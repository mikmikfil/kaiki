<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Models\Departure;
use App\Models\User;
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
        $crew = array_values(array_unique(array_map('intval', $crewUserIds)));

        $ids = array_values(array_unique(array_filter([$captainUserId, ...$crew])));

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
        }

        $name = $captainName === null ? null : trim($captainName);

        $departure->forceFill([
            'captain_user_id' => $captainUserId,
            'captain_name' => $captainUserId === null && $name !== '' ? $name : null,
            'crew_user_ids' => array_values(array_diff($crew, [$captainUserId])) ?: null,
        ])->save();

        return $departure;
    }
}
