<?php

declare(strict_types=1);

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
