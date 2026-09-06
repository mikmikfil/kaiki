<?php

declare(strict_types=1);

use App\Jobs\ApplyWeatherChoiceDefaults;
use App\Jobs\ExpireAbandonedCheckoutsJob;
use App\Jobs\ExpireQuotesJob;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\GenerateDeparturesNightly;
use App\Jobs\Reminders\SendDueRemindersJob;
use App\Models\NotificationLog;
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

/*
|--------------------------------------------------------------------------
| The weather-choice clocks (spec CXL-7)
|--------------------------------------------------------------------------
|
| CXL-7 is RESOLVED with its own reason: the brief leaves the no-response case
| undefined, and *"it must not strand money indefinitely."* A guest who never
| opens the email otherwise leaves an operator holding money that is not theirs
| on a booking nobody will ever close.
|
| Two clocks, one job: a reminder at 72 hours and the operator's default at 14
| days. Hourly, because both deadlines are measured in days and a minute-by-
| minute sweep would be twenty-four times the queries to send the same email at
| the same hour.
|
| Not on the hour: 03:15 and 04:10 already have long jobs, and a queue that
| wakes three workers on one Hetzner box at the same second is avoidable.
*/
Schedule::job(new ApplyWeatherChoiceDefaults)
    ->hourlyAt(35)
    ->withoutOverlapping()
    ->onOneServer()
    ->name('bookings:weather-choices');

/*
|--------------------------------------------------------------------------
| Quotes that ran out (spec BKG-26, `docs/data-model.md` §4.4)
|--------------------------------------------------------------------------
|
| **The tidier, not the guarantee** — the same division AVL-38 draws for seat
| holds. `Quote::canBeAccepted()` reads `valid_until` directly, so a lapsed
| offer is unacceptable the instant it lapses whether or not this has run. What
| this does is bring the *status* into line, so the operator's "pending quotes"
| card stops counting offers nobody can take and any opt-in vessel hold comes
| off the boat.
|
| Every fifteen minutes. Validity is measured in days, so a per-minute sweep
| would be sixty times the queries to free the same boat in the same quarter of
| an hour.
*/
Schedule::job(new ExpireQuotesJob)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('quotes:expire');

/*
|--------------------------------------------------------------------------
| Guest reminders (spec BKG-16, BKG-18)
|--------------------------------------------------------------------------
|
| **A sweep, not delayed jobs.** BKG-13.7 says "schedule the reminder jobs", and
| one delayed job per reminder per booking is the obvious reading — and the one
| that fires anyway when the departure moves, the balance is paid, the details
| are completed or the booking is cancelled. The schedule is derived from the
| booking on every pass instead, and every suppression condition in BKG-16's own
| table is evaluated at send time.
|
| Every fifteen minutes. The offsets are hours and days, so a per-minute sweep
| would be sixty times the queries to send the same message in the same quarter
| of an hour — and BKG-18's deferral to 08:00 works by the next pass finding the
| window open, which needs the passes to be frequent rather than instant.
|
| Not on the hour, and not at :35 where the weather-choice sweep sits: two long
| jobs on one Hetzner box contending for the same connection pool is avoidable.
*/
Schedule::job(new SendDueRemindersJob)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('notifications:reminders');

/*
|--------------------------------------------------------------------------
| Notification log retention (`docs/data-model.md` §2.7)
|--------------------------------------------------------------------------
|
| Twelve months. Long enough that an operator asking "did the guest ever get the
| confirmation" six months later gets an answer, and short enough that a table
| holding every guest's email address does not grow without limit — `to` is
| personal data and §2.7 puts it in the GDPR export and erase paths.
*/
Schedule::command('model:prune', ['--model' => [NotificationLog::class]])
    ->dailyAt((string) config('kaiki.audit.purge_at', '04:10'))
    ->timezone((string) config('kaiki.defaults.timezone', 'Europe/Athens'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('notifications:prune');
