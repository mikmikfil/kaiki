<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Enums\DepartureStatus;
use App\Mail\CrewReminderMail;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The 24-hours-before reminder to a departure's captain and crew (Mike,
 * 2026-09-24: «24 hours before»).
 *
 * A sweep, like the guest reminders: every quarter of an hour it finds the
 * departures starting within the next 24 hours that have not been reminded
 * yet, and mails each person on them who has an email. `crew_reminded_at` is
 * set whatever happens, so a departure is reminded once — a person added
 * after that gets the assignment email instead, which says the same.
 *
 * A departure created less than 24 hours ahead is reminded on the next pass:
 * late is better than never for somebody who has to be at the quay.
 */
final class SendCrewReminders
{
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $sent = 0;

        $due = Tenancy::withoutTenancy(static fn () => Departure::query()
            ->withoutGlobalScopes()
            ->whereNull('crew_reminded_at')
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->where('starts_at_utc', '>', $now)
            ->where('starts_at_utc', '<=', $now->copy()->addHours(24))
            ->where(static function ($query): void {
                $query->whereNotNull('captain_user_id')->orWhereNotNull('crew_user_ids');
            })
            ->get(['id', 'tenant_id']));

        foreach ($due->groupBy('tenant_id') as $tenantId => $rows) {
            $tenant = Tenancy::withoutTenancy(static fn (): ?Tenant => Tenant::query()->find($tenantId));

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $sent += (int) Tenancy::forTenant($tenant, static function () use ($rows): int {
                $count = 0;

                foreach (Departure::query()->with(['product.meetingPoint', 'vessel'])->whereKey($rows->modelKeys())->get() as $departure) {
                    $people = array_values(array_unique(array_filter([
                        $departure->captain_user_id,
                        ...array_map('intval', (array) ($departure->crew_user_ids ?? [])),
                    ])));

                    foreach (User::query()->whereKey($people)->get() as $person) {
                        if (filter_var($person->email, FILTER_VALIDATE_EMAIL) === false) {
                            continue;
                        }

                        Mail::to($person->email)->queue(new CrewReminderMail(
                            $person,
                            $departure,
                            (int) $person->getKey() === (int) $departure->captain_user_id,
                        ));
                        $count++;
                    }

                    $departure->forceFill(['crew_reminded_at' => Carbon::now()])->saveQuietly();
                }

                return $count;
            });
        }

        return $sent;
    }
}
