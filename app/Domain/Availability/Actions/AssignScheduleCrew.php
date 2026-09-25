<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\Support\CrewNames;
use App\Enums\DepartureStatus;
use App\Mail\CrewScheduleMail;
use App\Models\Departure;
use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Captain and crew for a whole schedule (Mike, 2026-09-24, from
 * docs/mockups/captain-crew.html): set once, copied to every departure the
 * schedule makes from now on and to its future departures that still follow it.
 *
 * A departure changed by hand for one day (`crew_from_rule` false) is left as
 * it is. Past and cancelled departures are history and are not touched.
 *
 * **Nobody on two departures at the same time**, as on a single departure: a
 * person already on any other departure overlapping one of this schedule's is
 * refused, naming that one and how many days clash.
 *
 * **One email per person per schedule**, not one per departure — a schedule
 * makes dozens of them, and a message that arrives dozens of times is one
 * nobody reads.
 *
 * **Crew typed by name** (Mike, 2026-09-25) travel the same way as
 * `captain_name`, and get no email. Null leaves the rule's as they are.
 */
final class AssignScheduleCrew
{
    /**
     * @param  list<int|string>  $crewUserIds
     * @param  list<string>|null  $crewNames  null = leave them as they are
     *
     * @throws ValidationException
     */
    public function __invoke(ScheduleRule $rule, int|string|null $captainUserId, ?string $captainName, array $crewUserIds, ?array $crewNames = null): ScheduleRule
    {
        $captainUserId = $captainUserId === null || $captainUserId === '' ? null : (int) $captainUserId;
        $crew = array_values(array_diff(array_unique(array_map('intval', $crewUserIds)), [$captainUserId]));
        $ids = array_values(array_filter([$captainUserId, ...$crew]));
        $name = $captainName === null ? null : trim($captainName);
        $name = $captainUserId === null && $name !== '' ? $name : null;
        $typed = CrewNames::clean($crewNames ?? $rule->crew_names);

        $targets = Departure::query()
            ->where('schedule_rule_id', $rule->getKey())
            ->where('crew_from_rule', true)
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->where('starts_at_utc', '>', Carbon::now())
            ->get();

        if ($ids !== []) {
            $known = User::query()->where('tenant_id', $rule->tenant_id)->whereKey($ids)->count();

            if ($known !== count($ids)) {
                throw ValidationException::withMessages([
                    'crew_user_ids' => [trans('availability.departure.crew.validation.not_ours')],
                ]);
            }

            $this->guardOverlaps($targets, $ids);
        }

        $before = array_values(array_filter([
            $rule->captain_user_id,
            ...array_map('intval', (array) ($rule->crew_user_ids ?? [])),
        ]));

        DB::transaction(function () use ($rule, $captainUserId, $name, $crew, $typed, $targets): void {
            $rule->forceFill([
                'captain_user_id' => $captainUserId,
                'captain_name' => $name,
                'crew_user_ids' => $crew === [] ? null : $crew,
                'crew_names' => $typed,
            ])->save();

            if ($targets->isNotEmpty()) {
                Departure::query()->whereKey($targets->modelKeys())->update([
                    'captain_user_id' => $captainUserId,
                    'captain_name' => $name,
                    'crew_user_ids' => $crew === [] ? null : json_encode($crew),
                    'crew_names' => $typed === null ? null : json_encode($typed, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });

        foreach (array_diff($ids, $before) as $newcomer) {
            $person = User::query()->find($newcomer);

            if ($person instanceof User && filter_var($person->email, FILTER_VALIDATE_EMAIL) !== false) {
                Mail::to($person->email)->queue(new CrewScheduleMail($person, $rule, $newcomer === $captainUserId));
            }
        }

        return $rule;
    }

    /**
     * @param  Collection<int, Departure>  $targets
     * @param  list<int>  $ids
     *
     * @throws ValidationException
     */
    private function guardOverlaps(Collection $targets, array $ids): void
    {
        if ($targets->isEmpty()) {
            return;
        }

        $others = Departure::query()
            ->with(['product', 'vessel'])
            ->whereKeyNot($targets->modelKeys())
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->where('starts_at_utc', '<', $targets->max('ends_at_utc'))
            ->where('ends_at_utc', '>', $targets->min('starts_at_utc'))
            ->where(static function ($query) use ($ids): void {
                $query->whereIn('captain_user_id', $ids)->orWhereNotNull('crew_user_ids');
            })
            ->get();

        /** @var array<string, array{name: string, trip: string, boat: string, time: string, days: int}> $clashes */
        $clashes = [];

        foreach ($others as $other) {
            $on = array_values(array_intersect($ids, array_filter([
                $other->captain_user_id,
                ...array_map('intval', (array) ($other->crew_user_ids ?? [])),
            ])));

            if ($on === []) {
                continue;
            }

            $overlaps = $targets->contains(static fn (Departure $mine): bool => $mine->starts_at_utc->lt($other->ends_at_utc)
                && $mine->ends_at_utc->gt($other->starts_at_utc));

            if (! $overlaps) {
                continue;
            }

            foreach ($on as $id) {
                $key = $id . ':' . ($other->schedule_rule_id ?? 'd' . $other->getKey());
                $clashes[$key] ??= [
                    'name' => (string) User::query()->whereKey($id)->value('name'),
                    'trip' => (string) $other->product->title,
                    'boat' => (string) $other->vessel->name,
                    'time' => substr((string) $other->local_time, 0, 5),
                    'days' => 0,
                ];
                $clashes[$key]['days']++;
            }
        }

        if ($clashes !== []) {
            throw ValidationException::withMessages([
                'crew_user_ids' => array_values(array_map(
                    static fn (array $clash): string => trans_choice('availability.schedule_rule.crew.overlap', $clash['days'], [
                        'name' => $clash['name'],
                        'trip' => $clash['trip'],
                        'boat' => $clash['boat'],
                        'time' => $clash['time'],
                        'count' => $clash['days'],
                    ]),
                    $clashes,
                )),
            ]);
        }
    }
}
