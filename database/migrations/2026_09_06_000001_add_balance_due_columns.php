<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three columns PRC-27 needs and `docs/data-model.md` never defined.
 *
 * ## Not a §6 item, and the filename says so
 *
 * Every other migration in this project carries a numeric suffix meaning its
 * position in `docs/data-model.md` §6. This one alters three existing tables
 * rather than creating one, so it has no item number — hence the different date
 * prefix, which sorts after the whole §6 series and reads as what it is.
 *
 * ## All three additions are portable, and §6 says exactly when that is true
 *
 * > *Adding a **nullable column with no FK and a constant default** to an
 * > existing table is portable and is allowed later.*
 *
 * - `bookings.balance_due_at` — nullable timestamp, no default.
 * - `rate_plans.balance_due_days_before_departure` — nullable, no default;
 *   null means "use the tenant's", which is the override's whole shape.
 * - `tenants.balance_due_days_before_departure` — nullable with the constant
 *   default 14. §6 names `tenants` specifically: *"allowed **only** if nullable
 *   with a constant default"*, which this is.
 *
 * No table is rebuilt on SQLite and no `ALTER` locks anything on MySQL.
 *
 * ## Why the gap existed
 *
 * PRC-27 and ADR-0018 settled the balance-due policy after §2 was written, and
 * the three columns it needs were never added to the tables it names. Found by
 * implementing it in #83; `docs/data-model.md` §2.2, §2.3 and §2.5 gain them in
 * the same commit, with the reason in `CHANGELOG.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            /*
             * How many days before departure a balance falls due (PRC-27.1).
             *
             * Fourteen by default, from ADR-0018. Nullable *and* defaulted, so
             * an existing row gets the platform answer without a backfill and a
             * future one can still be explicit about it.
             */
            $table->unsignedSmallInteger('balance_due_days_before_departure')->default(14)->nullable();
        });

        Schema::table('rate_plans', function (Blueprint $table): void {
            /*
             * The per-plan override, which takes precedence (PRC-27.1).
             *
             * **No default**, deliberately. Null means "use the tenant's", and a
             * default of 14 here would make every rate plan silently override
             * the tenant setting with the same number — which looks identical
             * until an operator changes the tenant one and nothing happens.
             */
            $table->unsignedSmallInteger('balance_due_days_before_departure')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            /*
             * Computed and written at confirmation, never derived on read
             * (PRC-27.2), so the reminder scheduler and the dashboard bucket
             * can index it. A derived value would mean every "what is overdue"
             * query became a full scan with arithmetic in the `WHERE`.
             */
            $table->timestamp('balance_due_at')->nullable();

            /*
             * The "Υπόλοιπα / Balances due" bucket (PRC-27.5) and the reminder
             * scheduler both read this. Tenant-first, unlike the hold sweeper's:
             * this one is an operator's own dashboard rather than a platform job.
             */
            $table->index(['tenant_id', 'balance_due_at', 'status'], 'bookings_balance_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_balance_due_idx');
            $table->dropColumn('balance_due_at');
        });

        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->dropColumn('balance_due_days_before_departure');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('balance_due_days_before_departure');
        });
    }
};
