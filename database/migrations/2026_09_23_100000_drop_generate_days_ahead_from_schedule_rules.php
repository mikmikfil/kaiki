<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `schedule_rules.generate_days_ahead` (Mike, 2026-09-23).
 *
 * The column shipped with the table, defaulted to 180, and had a field on the
 * «Δρομολόγια» screen labelled «Ορίζοντας — πόσες ημέρες μπροστά δημιουργούνται
 * αναχωρήσεις». **Nothing ever read it.** `GenerateDepartures` takes its
 * horizon from `config('kaiki.departures.horizon_days')`, which is 400, so an
 * operator who set 180 got 400 — and had no way to find out except by counting
 * the departures, which is exactly how this was found.
 *
 * Removed rather than wired up, because the question it asks is already
 * answered properly elsewhere. An operator who wants «from 5 September to 20
 * December» sets `valid_from` and `valid_until`, and generation honours them:
 * `ScheduleRuleDateIterator::endDate()` is `min(valid_until, today + horizon)`.
 * The horizon is not a preference at all — it is the backstop that stops an
 * open-ended rule generating for ever, and §2.3 is explicit that "open-ended"
 * means the operator set no end, not that the job runs without one.
 *
 * So there is one horizon, it is 400 days, and it lives in config where the
 * comment beside it already says a per-tenant override is *"reserved for later
 * and deliberately not built"*.
 *
 * **Down restores the column and its old default**, which is honest: the
 * rolling back of this migration returns a column nothing reads, exactly as it
 * was. No data is recoverable because none was ever meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->dropColumn('generate_days_ahead');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('generate_days_ahead')->default(180)->after('capacity_override');
        });
    }
};
