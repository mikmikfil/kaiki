<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a guest's weather-cancellation choice is recorded (spec CXL-6, CXL-7).
 *
 * ## Not a §6 item either, for the same reason as the balance-due columns
 *
 * These alter two existing tables rather than creating one, so there is no item
 * number and the date prefix sorts after the whole §6 series. All five are
 * nullable with no foreign key, and the one default is a constant — the
 * addition §6 permits, so nothing is rebuilt on SQLite and no `ALTER` locks
 * anything on MySQL.
 *
 * ## Why columns rather than a table
 *
 * A weather choice is **one fact per booking**, made once and never superseded:
 * CXL-7 records it *"with timestamp and IP"* and the deadline closes the
 * question for good. A `booking_weather_choices` table would have a unique
 * index on `booking_id` and one row per booking — which is a column with extra
 * steps, and a join on the path of a sweeper that runs every hour.
 *
 * ## The deadline is stored, not derived
 *
 * `weather_choice_due_at` could be `cancelled_at + 14 days` computed in the
 * `WHERE` clause, and then the sweeper's query is a full scan with arithmetic
 * in it — the same argument PRC-27.2 makes for `balance_due_at`. Storing it
 * also means an operator who extends one guest's deadline changes a value
 * rather than fighting a formula.
 *
 * ## The second index in the schema that does not lead with `tenant_id`
 *
 * `bookings_hold_expiry_idx` was the first, and §2.5 called it *"the only such
 * index"* — that sentence is corrected in the same commit. The reason is
 * identical: the sweeper that applies the CXL-7 default is a **platform job**
 * that runs cross-tenant, and an index led by `tenant_id` would be useless to
 * it. An operator never queries this; they look at one booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            /*
             * What happens when a guest never answers (CXL-7).
             *
             * Per-tenant with a constant default, because an operator who
             * always refunds and an operator who always issues a voucher are
             * both defensible and the platform cannot pick for them. `refund`
             * is the default because it is the one choice that cannot leave a
             * guest holding credit they never asked for.
             */
            $table->string('weather_choice_default', 16)->default('refund')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            /* `refund` | `voucher` | `rebook` — PHP enum `WeatherChoice`. */
            $table->string('weather_choice', 16)->nullable();

            /*
             * CXL-7 asks for both, and the IP is the same kind of evidence as
             * `bookings.ip_address` beside `terms_accepted_at`: the fact is the
             * timestamp, the evidence is the address it came from.
             */
            $table->timestamp('weather_choice_at')->nullable();
            $table->string('weather_choice_ip', 45)->nullable();

            /*
             * When the operator default gets applied instead (CXL-7).
             *
             * Set by the weather-cancellation workflow, not at booking time —
             * a booking that never meets bad weather never gets one.
             */
            $table->timestamp('weather_choice_due_at')->nullable();

            /*
             * The 72-hour reminder, once (CXL-7).
             *
             * `notification_logs` (§6 item 40) is #87's and does not exist yet,
             * and a reminder whose idempotency depends on a table that has not
             * been built is a reminder that goes out every hour. This column is
             * the guard until that table arrives, and stays afterwards as the
             * cheap answer to "has this one been reminded".
             */
            $table->timestamp('weather_choice_reminded_at')->nullable();

            /*
             * The CXL-7 sweeper's query, cross-tenant:
             *
             *   where weather_choice is null and weather_choice_due_at < now()
             *
             * Deliberately **not** tenant-first — see the class docblock.
             */
            $table->index(['weather_choice_due_at', 'weather_choice'], 'bookings_weather_choice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_weather_choice_idx');
            $table->dropColumn([
                'weather_choice',
                'weather_choice_at',
                'weather_choice_ip',
                'weather_choice_due_at',
                'weather_choice_reminded_at',
            ]);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('weather_choice_default');
        });
    }
};
