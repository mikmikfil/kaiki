<?php

declare(strict_types=1);

use App\Jobs\ExpireAbandonedCheckoutsJob;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\GenerateDeparturesNightly;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Nightly departure generation (ADR-0009, spec AVL-54, NFR-11)
|--------------------------------------------------------------------------
|
| The rolling horizon is maintained here rather than at read time: ADR-0009
| Option A keeps the read path free of writes, so an availability request never
| pays for a calendar somebody has not opened in a month.
|
| The time comes from config and the timezone is the **platform default**, not
| the server's. A per-tenant schedule is not expressible in one cron line, and
| the job is additive and idempotent, so the exact minute matters far less than
| the fact that it runs after both DST transitions (03:00 and 04:00 local) have
| settled.
|
| `withoutOverlapping` because a slow night must not stack a second sweep on
| top of the first, and `onOneServer` because the horizon does not need
| extending twice.
|
*/
Schedule::job(new GenerateDeparturesNightly)
    ->dailyAt((string) config('kaiki.departures.nightly_at', '03:15'))
    ->timezone((string) config('kaiki.defaults.timezone', 'Europe/Athens'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('departures:generate-nightly');

/*
|--------------------------------------------------------------------------
| Audit retention (ADR-0025 §3, spec SEC-16)
|--------------------------------------------------------------------------
|
| Seven years, not ADR-0012's ninety days, and the reasoning is in the config
| block: the trail is kept for the operator's own bookkeeping and dispute
| obligations, and it survives a GDPR erasure because the actor is a `user_id`
| rather than a name.
|
| **One scheduler entry, deliberately.** ADR-0025 asks for this to be written
| "alongside the ADR-0012 purge rather than as a second scheduler entry" —
| that purge does not exist yet, because the personal data it removes arrives
| with `bookings` in M2. When it lands it joins this line rather than adding
| another: two retention jobs on two different minutes is how one of them stops
| running and nobody notices for a year.
|
| Not 03:15: the departure generator has that minute, and two long jobs
| contending for one connection pool on a single Hetzner box is avoidable.
*/
Schedule::command('audit:purge')
    ->dailyAt((string) config('kaiki.audit.purge_at', '04:10'))
    ->timezone((string) config('kaiki.defaults.timezone', 'Europe/Athens'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('audit:purge');

/*
|--------------------------------------------------------------------------
| Stale seat holds (ADR-0005, spec AVL-38)
|--------------------------------------------------------------------------
|
| **This is the tidier, not the guarantee**, and the distinction is the whole
| of AVL-38. A hold is dead the instant `hold_expires_at` passes; every
| availability read applies that on its own, and `HoldExpiryTest` proves it with
| this entry never run. What this does is bring `departures.seats_held` back
| into line, so an operator's dashboard is not showing seats held by nobody.
|
| Every minute, per the ADR. `withoutOverlapping` because a slow minute must not
| stack a second sweep on the first — two sweepers recomputing the same counter
| would each write a figure the other's uncommitted work made stale.
| `onOneServer` for the same reason across machines.
|
| No timezone: unlike the two above, this is not a daily job at a local hour.
| It runs every minute everywhere, and a timezone on a per-minute schedule is a
| line that reads as meaningful and is not.
*/
Schedule::job(new ExpireStaleHoldsJob)
    ->cron((string) config('kaiki.booking.sweeper_cron', '* * * * *'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('bookings:expire-holds');

/*
|--------------------------------------------------------------------------
| Abandoned checkouts (spec BKG-10)
|--------------------------------------------------------------------------
|
| **This one is not a tidier.** The hold sweeper above has a read-side twin —
| every availability read treats a lapsed hold as released on its own — so a
| worker outage there costs an inaccurate dashboard. A `pending_payment`
| booking's seats are in `seats_sold`, which is the number that must be
| believed, and nothing infers "but that guest left an hour ago". If this stops
| running, seats stay off sale.
|
| That asymmetry is the cost of BKG-9 committing at redirect rather than at the
| webhook, which is what closes the window where a guest on the gateway page
| loses the seat they are paying for.
|
| Every five minutes, not every minute: the cutoff is an hour, so a
| minute-by-minute sweep is twelve times the queries and frees no seat sooner.
*/
Schedule::job(new ExpireAbandonedCheckoutsJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('bookings:expire-checkouts');
